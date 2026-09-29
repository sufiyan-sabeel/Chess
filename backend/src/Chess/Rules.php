<?php

declare(strict_types=1);

namespace Checkmate\Chess;

/**
 * Standard chess rules engine (FIDE rules, no Chess960).
 *
 * Board representation: 0x88 mailbox (a1 = 0, h1 = 7, a8 = 112, h8 = 119).
 * Moves are generated pseudo-legally and filtered by making the move and
 * testing that the mover's own king is not attacked (never partially applies
 * an illegal move). Checkmate/stalemate are detected with complete legal-move
 * validation, never merely "king attacked".
 *
 * Draw detection: insufficient material (dead positions), threefold
 * repetition (position + side to move + castling rights + en-passant
 * availability) and the fifty-move rule. The fifty-move and threefold rules
 * are treated as automatic draws in the result API; canClaimDraw() /
 * canClaimFiftyMove() expose the claim predicate.
 *
 * The class deliberately mirrors chess.js 1.4.0 behaviour for FEN
 * en-passant display (the ep square is printed only when at least one legal
 * ep capture exists) and for repetition keying (the internal ep square is
 * kept after a double push only when an enemy pawn is adjacent), so that the
 * cross-check test against chess.js compares like with like.
 *
 * Class alias `Chess\Rules` and global alias `Rules` are registered at the
 * bottom of this file for consumers that expect those names.
 */
final class Rules
{
    public const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    /* ---- castling right bits ---- */
    private const CAST_K = 1;   // white O-O
    private const CAST_Q = 2;   // white O-O-O
    private const CAST_k = 4;   // black O-O
    private const CAST_q = 8;   // black O-O-O

    /* ---- packed move encoding -----------------------------------------
     * bits 0-6   : from square (0..127)
     * bits 7-13  : to square   (0..127)
     * bits 14-16 : promotion (0 none, 1 N, 2 B, 3 R, 4 Q)
     * bits 17-19 : flag (see F_* below)
     */
    private const F_QUIET  = 0;
    private const F_CAP    = 1;
    private const F_DOUBLE = 2;
    private const F_EP     = 3;
    private const F_KCAST  = 4;
    private const F_QCAST  = 5;

    private const P_NONE = 0;
    private const P_N    = 1;
    private const P_B    = 2;
    private const P_R    = 3;
    private const P_Q    = 4;

    private const KNIGHT_DIRS = [14, 18, -14, -18, 31, 33, -31, -33];
    private const KING_DIRS   = [1, -1, 16, -16, 15, -15, 17, -17];
    private const ROOK_DIRS   = [1, -1, 16, -16];
    private const BISHOP_DIRS = [15, -15, 17, -17];
    private const QUEEN_DIRS  = [15, -15, 17, -17, 1, -1, 16, -16];
    private const CAP_DIRS_W  = [15, 17];
    private const CAP_DIRS_B  = [-15, -17];

    /** @var array<int, string|int> 128 slots (0x88 holes unused), 0 = empty */
    private array $bd;
    private string $turn;        // 'w' | 'b'
    private int $cast;           // CAST_* bitmask
    private int $ep;             // en-passant target square or -1
    private int $half;           // halfmove clock
    private int $full;           // fullmove number
    /** @var array{w:int,b:int} */
    private array $kings;

    private string $startFen;
    private string $startSide;
    private int $startFull;

    /** @var list<string> UCI move history since load */
    private array $histUci = [];
    /** @var list<string> SAN move history since load */
    private array $histSan = [];
    /** @var list<string> position keys (board+turn+castling+ep) incl. initial */
    private array $keys = [];

    private function __construct()
    {
    }

    /* ================================================================== */
    /* construction                                                        */
    /* ================================================================== */

    /** Parses and validates a FEN. Returns null when the FEN is invalid. */
    public static function fromFen(string $fen): ?self
    {
        $tokens = preg_split('/\s+/', trim($fen));
        if ($tokens === false || count($tokens) !== 6) {
            return null;
        }
        [$board, $side, $castling, $ep, $half, $full] = $tokens;

        if ($side !== 'w' && $side !== 'b') {
            return null;
        }
        if (!preg_match('/^\d+$/', $half) || !preg_match('/^[1-9]\d*$/', $full)) {
            return null;
        }
        if ($castling !== '-' && !preg_match('/^[KQkq]+$/', $castling)) {
            return null;
        }
        if ($ep !== '-' && !preg_match('/^[a-h][36]$/', $ep)) {
            return null;
        }
        // canonical ep rank: rank 3 only with black to move, rank 6 only with white
        if ($ep !== '-' && ((($ep[1] === '3') && $side !== 'b') || (($ep[1] === '6') && $side !== 'w'))) {
            return null;
        }

        $rows = explode('/', $board);
        if (count($rows) !== 8) {
            return null;
        }

        $self = new self();
        $self->bd = array_fill(0, 128, 0);
        $self->kings = ['w' => -1, 'b' => -1];
        $kings = ['w' => 0, 'b' => 0];
        $pawnsBackRank = false;

        for ($i = 0; $i < 8; $i++) {
            $rank = 7 - $i;          // FEN row 0 is rank 8
            $file = 0;
            $row = $rows[$i];
            $len = strlen($row);
            for ($k = 0; $k < $len; $k++) {
                $ch = $row[$k];
                if ($ch >= '1' && $ch <= '8') {
                    if ($k > 0 && $row[$k - 1] >= '1' && $row[$k - 1] <= '8') {
                        return null; // consecutive digits
                    }
                    $file += (int) $ch;
                    continue;
                }
                if (!str_contains('prnbqkPRNBQK', $ch)) {
                    return null;
                }
                if ($file > 7) {
                    return null;
                }
                $sq = $rank * 16 + $file;
                $self->bd[$sq] = $ch;
                $file++;
                $white = ord($ch) < 97;
                if ($ch === 'K' || $ch === 'k') {
                    $kings[$white ? 'w' : 'b']++;
                    $self->kings[$white ? 'w' : 'b'] = $sq;
                } elseif ($ch === 'P' || $ch === 'p') {
                    if ($rank === 0 || $rank === 7) {
                        $pawnsBackRank = true;
                    }
                }
            }
            if ($file !== 8) {
                return null;
            }
        }

        if ($kings['w'] !== 1 || $kings['b'] !== 1) {
            return null;
        }
        if ($pawnsBackRank) {
            return null;
        }

        // castling rights: parse and require the king and rook at home squares
        $cast = 0;
        if ($castling !== '-') {
            if (str_contains($castling, 'K')) {
                $cast |= self::CAST_K;
            }
            if (str_contains($castling, 'Q')) {
                $cast |= self::CAST_Q;
            }
            if (str_contains($castling, 'k')) {
                $cast |= self::CAST_k;
            }
            if (str_contains($castling, 'q')) {
                $cast |= self::CAST_q;
            }
            if (($cast & self::CAST_K) && !($self->bd[4] === 'K' && $self->bd[7] === 'R')) {
                return null;
            }
            if (($cast & self::CAST_Q) && !($self->bd[4] === 'K' && $self->bd[0] === 'R')) {
                return null;
            }
            if (($cast & self::CAST_k) && !($self->bd[116] === 'k' && $self->bd[119] === 'r')) {
                return null;
            }
            if (($cast & self::CAST_q) && !($self->bd[116] === 'k' && $self->bd[112] === 'r')) {
                return null;
            }
        }

        $self->turn = $side;
        $self->cast = $cast;
        $self->ep = $ep === '-' ? -1 : (self::sqToIndex($ep));
        $self->half = (int) $half;
        $self->full = (int) $full;

        // A position is illegal when the side NOT to move is already in check
        // (it would mean the side to move's previous move left its own king en prise).
        $other = $side === 'w' ? 'b' : 'w';
        if ($self->attacked($self->kings[$other], $side)) {
            return null;
        }

        $self->startFen = implode(' ', $tokens);
        $self->startSide = $side;
        $self->startFull = (int) $full;
        $self->keys[] = $self->key();
        return $self;
    }

    public static function start(): self
    {
        $r = self::fromFen(self::START_FEN);
        assert($r !== null);
        return $r;
    }

    /* ================================================================== */
    /* state                                                               */
    /* ================================================================== */

    public function fen(): string
    {
        $out = '';
        for ($rank = 7; $rank >= 0; $rank--) {
            $empty = 0;
            for ($file = 0; $file < 8; $file++) {
                $p = $this->bd[$rank * 16 + $file];
                if ($p === 0) {
                    $empty++;
                    continue;
                }
                if ($empty > 0) {
                    $out .= $empty;
                    $empty = 0;
                }
                $out .= $p;
            }
            if ($empty > 0) {
                $out .= $empty;
            }
            if ($rank > 0) {
                $out .= '/';
            }
        }
        $out .= ' ' . $this->turn;
        $out .= ' ' . $this->castlingStr();
        $out .= ' ' . $this->epDisplay();
        $out .= ' ' . $this->half . ' ' . $this->full;
        return $out;
    }

    public function sideToMove(): string
    {
        return $this->turn;
    }

    /** @return list<string> */
    public function history(): array
    {
        return $this->histUci;
    }

    /** @return list<string> */
    public function legalMovesUci(): array
    {
        $out = [];
        foreach ($this->legalMoves() as $m) {
            $out[] = $this->toUci($m);
        }
        return $out;
    }

    /** @return array<string,string> map uci => san */
    public function legalMovesSan(): array
    {
        $legal = $this->legalMoves();
        $out = [];
        foreach ($legal as $m) {
            $out[$this->toUci($m)] = $this->sanOf($m, $legal);
        }
        return $out;
    }

    /* ================================================================== */
    /* making moves                                                        */
    /* ================================================================== */

    /**
     * Applies a UCI move (e.g. "e2e4", "e7e8q").
     * Returns ['uci'=>, 'san'=>, 'fen'=>] or null when the move is illegal
     * (the position is then left completely unchanged).
     */
    public function moveUci(string $uci): ?array
    {
        $uci = strtolower(trim($uci));
        if (!preg_match('/^[a-h][1-8][a-h][1-8][qrbn]?$/', $uci)) {
            return null;
        }
        $legal = $this->legalMoves();
        foreach ($legal as $m) {
            if ($this->toUci($m) === $uci) {
                return $this->applyMove($m, $legal);
            }
        }
        return null;
    }

    /**
     * Applies a SAN move (e.g. "Nf3", "O-O", "Qxd7#", "e8=Q+"). Decorations
     * ("+", "#", "!", "?") are accepted. Returns like moveUci(), or null.
     */
    public function moveSan(string $san): ?array
    {
        $legal = $this->legalMoves();
        $clean = self::stripSan($san);
        if ($clean === '') {
            return null;
        }
        foreach ($legal as $m) {
            if (self::stripSan($this->sanOf($m, $legal)) === $clean) {
                return $this->applyMove($m, $legal);
            }
        }
        return null;
    }

    /**
     * Replays a UCI move list from $startFen (default: standard start).
     * Returns null as soon as any move is illegal or $startFen is invalid.
     */
    public function replay(array $uciMoves, string $startFen = self::START_FEN): ?self
    {
        $r = self::fromFen($startFen);
        if ($r === null) {
            return null;
        }
        foreach ($uciMoves as $u) {
            if (!is_string($u) || $r->moveUci($u) === null) {
                return null;
            }
        }
        return $r;
    }

    /* ================================================================== */
    /* queries                                                             */
    /* ================================================================== */

    public function isCheck(): bool
    {
        return $this->attacked($this->kings[$this->turn], $this->turn === 'w' ? 'b' : 'w');
    }

    public function isCheckmate(): bool
    {
        return $this->isCheck() && $this->legalMoves() === [];
    }

    public function isStalemate(): bool
    {
        return !$this->isCheck() && $this->legalMoves() === [];
    }

    /** Dead-position check (insufficient material to force/produce mate). */
    public function isInsufficientMaterial(): bool
    {
        $count = 0;
        $bishops = 0;
        $knights = 0;
        $nonBishop = 0;     // everything that is not a king or bishop
        $colorSum = 0;      // sum of bishop square-colour flags (0 light, 1 dark)
        $bishopCount = 0;
        for ($sq = 0; $sq < 128; $sq++) {
            if (($sq & 0x88) !== 0) {
                $sq += 7;
                continue;
            }
            $p = $this->bd[$sq];
            if ($p === 0) {
                continue;
            }
            $count++;
            $t = chr(ord($p) | 32);
            if ($t === 'k') {
                continue;
            }
            if ($t === 'b') {
                $bishops++;
                $bishopCount++;
                $colorSum += ((($sq >> 4) + ($sq & 15)) & 1);
            } elseif ($t === 'n') {
                $knights++;
                $nonBishop++;
            } else {
                $nonBishop++;
            }
        }
        // K vs K
        if ($count === 2) {
            return true;
        }
        // K + single minor vs K
        if ($count === 3 && ($knights === 1 || $bishops === 1)) {
            return true;
        }
        // any number of bishops, all on the same colour, vs two kings
        if ($nonBishop === 0 && $bishopCount > 0 && ($colorSum === 0 || $colorSum === $bishopCount)) {
            return true;
        }
        return false;
    }

    public function isThreefoldRepetition(): bool
    {
        return $this->threefoldCount() >= 3;
    }

    public function isFiftyMove(): bool
    {
        return $this->half >= 100;
    }

    /** True for stalemate, insufficient material, threefold or fifty-move. */
    public function isDraw(): bool
    {
        return $this->isStalemate()
            || $this->isInsufficientMaterial()
            || $this->isThreefoldRepetition()
            || $this->half >= 100;
    }

    /** A fifty-move-rule draw claim is available. */
    public function canClaimFiftyMove(): bool
    {
        return $this->half >= 100;
    }

    /** A threefold-repetition or fifty-move claim is available. */
    public function canClaimDraw(): bool
    {
        return $this->threefoldCount() >= 3 || $this->half >= 100;
    }

    public function isGameOver(): bool
    {
        return $this->resultReason() !== null;
    }

    public function result(): string
    {
        $reason = $this->resultReason();
        if ($reason === null) {
            return '*';
        }
        if ($reason === 'checkmate') {
            return $this->turn === 'w' ? '0-1' : '1-0';
        }
        return '1/2-1/2';
    }

    public function resultReason(): ?string
    {
        if ($this->isCheckmate()) {
            return 'checkmate';
        }
        if ($this->isStalemate()) {
            return 'stalemate';
        }
        if ($this->isInsufficientMaterial()) {
            return 'insufficient_material';
        }
        if ($this->isThreefoldRepetition()) {
            return 'threefold_repetition';
        }
        if ($this->half >= 100) {
            return 'fifty_move';
        }
        return null;
    }

    /* ================================================================== */
    /* perft                                                               */
    /* ================================================================== */

    public function perft(int $depth): int
    {
        if ($depth < 0) {
            throw new \InvalidArgumentException('perft depth must be >= 0');
        }
        return $this->perftRec($depth);
    }

    private function perftRec(int $depth): int
    {
        if ($depth === 0) {
            return 1;
        }
        $me = $this->turn;
        $them = $me === 'w' ? 'b' : 'w';
        $total = 0;
        foreach ($this->genPseudo() as $m) {
            $u = $this->make($m);
            if (!$this->attacked($this->kings[$me], $them)) {
                $total += $depth === 1 ? 1 : $this->perftRec($depth - 1);
            }
            $this->unmake($m, $u);
        }
        return $total;
    }

    /* ================================================================== */
    /* PGN                                                                 */
    /* ================================================================== */

    /**
     * Exports the game (headers + movetext). $headers overrides the default
     * seven-tag roster values (White/Black/Event/...); Result, SetUp and FEN
     * are filled in automatically unless explicitly overridden.
     */
    public function toPgn(array $headers = []): string
    {
        $tags = [
            'Event' => 'Checkmate Game',
            'Site'  => '?',
            'Date'  => '????.??.??',
            'Round' => '-',
            'White' => '?',
            'Black' => '?',
            'Result' => $this->result(),
        ];
        if ($this->startFen !== self::START_FEN) {
            $tags['SetUp'] = '1';
            $tags['FEN'] = $this->startFen;
        }
        foreach ($headers as $k => $v) {
            $tags[(string) $k] = (string) $v;
        }
        // keep the seven-tag roster first, SetUp/FEN next, extras last
        $order = ['Event', 'Site', 'Date', 'Round', 'White', 'Black', 'Result', 'SetUp', 'FEN'];
        $out = '';
        foreach ($order as $name) {
            if (array_key_exists($name, $tags)) {
                $out .= sprintf("[%s \"%s\"]\n", $name, str_replace(['\\', '"'], ['\\\\', '\\"'], $tags[$name]));
                unset($tags[$name]);
            }
        }
        foreach ($tags as $name => $value) {
            $out .= sprintf("[%s \"%s\"]\n", $name, str_replace(['\\', '"'], ['\\\\', '\\"'], $value));
        }
        $out .= "\n";

        // movetext with correct move numbers
        $num = $this->startFull;
        $side = $this->startSide;
        $afterWhite = false;
        $lines = [];
        $cur = '';
        $push = static function (string $token) use (&$lines, &$cur): void {
            if ($cur === '') {
                $cur = $token;
            } elseif (strlen($cur) + 1 + strlen($token) > 76) {
                $lines[] = $cur;
                $cur = $token;
            } else {
                $cur .= ' ' . $token;
            }
        };
        foreach ($this->histSan as $san) {
            if ($side === 'w') {
                $push($num . '. ' . $san);
                $side = 'b';
                $afterWhite = true;
            } else {
                // black replies share the move number ("1. e4 e5"); a black
                // move not preceded by a white one gets "1... e5"
                $push(($afterWhite ? '' : $num . '... ') . $san);
                $afterWhite = false;
                $num++;
                $side = 'w';
            }
        }
        $push($this->result());
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $out . implode("\n", $lines) . "\n";
    }

    /**
     * Parses PGN movetext (comments, NAGs and variations are stripped) into a
     * UCI move list. Returns null on malformed or oversized input.
     * Supports the tags [White], [Black], [Result], [FEN] and [SetUp].
     */
    public static function pgnToUci(string $pgn): ?array
    {
        if (strlen($pgn) > 65536) {
            return null;
        }
        $fen = self::START_FEN;
        if (preg_match_all('/\[(\w+)\s+"([^"]*)"\]/', $pgn, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $h) {
                if (strtolower($h[1]) === 'fen') {
                    $fen = $h[2];
                }
            }
        }
        $body = preg_replace('/\[(\w+)\s+"([^"]*)"\]/', ' ', $pgn);
        $body = preg_replace('/\{[^}]*\}/s', ' ', $body);
        $body = preg_replace('/;[^\n]*/', ' ', $body);
        $prev = null;
        while ($prev !== $body) {          // strip (possibly nested) variations
            $prev = $body;
            $body = preg_replace('/\([^()]*\)/', ' ', $body);
        }
        $body = preg_replace('/\$\d+/', ' ', $body);

        $r = self::fromFen($fen);
        if ($r === null) {
            return null;
        }
        $uci = [];
        $tokens = preg_split('/\s+/', trim($body));
        if ($tokens === false) {
            return null;
        }
        foreach ($tokens as $tok) {
            if ($tok === '') {
                continue;
            }
            while (preg_match('/^\d+\.\.\.?/', $tok) === 1 || preg_match('/^\d+\./', $tok) === 1) {
                $tok = (string) preg_replace('/^\d+\.\.\.?/', '', $tok);
                $tok = (string) preg_replace('/^\d+\./', '', $tok);
                if ($tok === '') {
                    break;
                }
            }
            if ($tok === '' || $tok === '...') {
                continue;
            }
            if ($tok === '1-0' || $tok === '0-1' || $tok === '1/2-1/2' || $tok === '*') {
                break;
            }
            $mv = $r->moveSan($tok);
            if ($mv === null) {
                return null;
            }
            $uci[] = $mv['uci'];
            if (count($uci) > 1024) {
                return null;
            }
        }
        return $uci;
    }

    /* ================================================================== */
    /* move generation                                                     */
    /* ================================================================== */

    /** @return list<int> packed pseudo-legal moves */
    private function genPseudo(): array
    {
        $bd = $this->bd;
        $mv = [];
        $isW = $this->turn === 'w';
        $promoRank = $isW ? 7 : 0;

        for ($sq = 0; $sq < 128; $sq++) {
            if (($sq & 0x88) !== 0) {
                $sq += 7;
                continue;
            }
            $p = $bd[$sq];
            if ($p === 0) {
                continue;
            }
            if ((ord($p) < 97) !== $isW) {
                continue;
            }
            $isPawn = $p === 'P' || $p === 'p';

            if ($isPawn) {
                $dir = $isW ? 16 : -16;
                $caps = $isW ? self::CAP_DIRS_W : self::CAP_DIRS_B;
                $startRank = $isW ? 1 : 6;
                $to = $sq + $dir;
                if (($to & 0x88) === 0 && $bd[$to] === 0) {
                    if (intdiv($to, 16) === $promoRank) {
                        $mv[] = self::pack($sq, $to, self::P_Q, self::F_QUIET);
                        $mv[] = self::pack($sq, $to, self::P_R, self::F_QUIET);
                        $mv[] = self::pack($sq, $to, self::P_B, self::F_QUIET);
                        $mv[] = self::pack($sq, $to, self::P_N, self::F_QUIET);
                    } else {
                        $mv[] = self::pack($sq, $to, self::P_NONE, self::F_QUIET);
                        if (intdiv($sq, 16) === $startRank) {
                            $to2 = $sq + 2 * $dir;
                            if ($bd[$to2] === 0) {
                                $mv[] = self::pack($sq, $to2, self::P_NONE, self::F_DOUBLE);
                            }
                        }
                    }
                }
                foreach ($caps as $d) {
                    $to = $sq + $d;
                    if (($to & 0x88) !== 0) {
                        continue;
                    }
                    $t = $bd[$to];
                    if ($t !== 0 && ((ord($t) < 97) !== $isW)) {
                        if (intdiv($to, 16) === $promoRank) {
                            $mv[] = self::pack($sq, $to, self::P_Q, self::F_CAP);
                            $mv[] = self::pack($sq, $to, self::P_R, self::F_CAP);
                            $mv[] = self::pack($sq, $to, self::P_B, self::F_CAP);
                            $mv[] = self::pack($sq, $to, self::P_N, self::F_CAP);
                        } else {
                            $mv[] = self::pack($sq, $to, self::P_NONE, self::F_CAP);
                        }
                    } elseif ($t === 0 && $to === $this->ep) {
                        $mv[] = self::pack($sq, $to, self::P_NONE, self::F_EP);
                    }
                }
                continue;
            }

            if ($p === 'N' || $p === 'n') {
                foreach (self::KNIGHT_DIRS as $d) {
                    $to = $sq + $d;
                    if (($to & 0x88) !== 0) {
                        continue;
                    }
                    $t = $bd[$to];
                    if ($t === 0) {
                        $mv[] = self::pack($sq, $to, self::P_NONE, self::F_QUIET);
                    } elseif ((ord($t) < 97) !== $isW) {
                        $mv[] = self::pack($sq, $to, self::P_NONE, self::F_CAP);
                    }
                }
                continue;
            }

            if ($p === 'K' || $p === 'k') {
                foreach (self::KING_DIRS as $d) {
                    $to = $sq + $d;
                    if (($to & 0x88) !== 0) {
                        continue;
                    }
                    $t = $bd[$to];
                    if ($t === 0) {
                        $mv[] = self::pack($sq, $to, self::P_NONE, self::F_QUIET);
                    } elseif ((ord($t) < 97) !== $isW) {
                        $mv[] = self::pack($sq, $to, self::P_NONE, self::F_CAP);
                    }
                }
                continue;
            }

            $dirs = match ($p) {
                'B', 'b' => self::BISHOP_DIRS,
                'R', 'r' => self::ROOK_DIRS,
                default  => self::QUEEN_DIRS,
            };
            foreach ($dirs as $d) {
                $to = $sq + $d;
                while (($to & 0x88) === 0) {
                    $t = $bd[$to];
                    if ($t === 0) {
                        $mv[] = self::pack($sq, $to, self::P_NONE, self::F_QUIET);
                    } else {
                        if ((ord($t) < 97) !== $isW) {
                            $mv[] = self::pack($sq, $to, self::P_NONE, self::F_CAP);
                        }
                        break;
                    }
                    $to += $d;
                }
            }
        }

        // castling: rights + king/rook placement (required by fromFen) + empty
        // squares + king not in check and not passing over an attacked square.
        // The rook itself may be under attack (FIDE 3.8 / chess.js behaviour).
        if ($isW) {
            if (($this->cast & self::CAST_K) !== 0 && $bd[4] === 'K' && $bd[7] === 'R'
                && $bd[5] === 0 && $bd[6] === 0
                && !$this->attacked(4, 'b') && !$this->attacked(5, 'b') && !$this->attacked(6, 'b')) {
                $mv[] = self::pack(4, 6, self::P_NONE, self::F_KCAST);
            }
            if (($this->cast & self::CAST_Q) !== 0 && $bd[4] === 'K' && $bd[0] === 'R'
                && $bd[1] === 0 && $bd[2] === 0 && $bd[3] === 0
                && !$this->attacked(4, 'b') && !$this->attacked(3, 'b') && !$this->attacked(2, 'b')) {
                $mv[] = self::pack(4, 2, self::P_NONE, self::F_QCAST);
            }
        } else {
            if (($this->cast & self::CAST_k) !== 0 && $bd[116] === 'k' && $bd[119] === 'r'
                && $bd[117] === 0 && $bd[118] === 0
                && !$this->attacked(116, 'w') && !$this->attacked(117, 'w') && !$this->attacked(118, 'w')) {
                $mv[] = self::pack(116, 118, self::P_NONE, self::F_KCAST);
            }
            if (($this->cast & self::CAST_q) !== 0 && $bd[116] === 'k' && $bd[112] === 'r'
                && $bd[113] === 0 && $bd[114] === 0 && $bd[115] === 0
                && !$this->attacked(116, 'w') && !$this->attacked(115, 'w') && !$this->attacked(114, 'w')) {
                $mv[] = self::pack(116, 114, self::P_NONE, self::F_QCAST);
            }
        }

        return $mv;
    }

    /** @return list<int> fully legal packed moves */
    private function legalMoves(): array
    {
        $me = $this->turn;
        $them = $me === 'w' ? 'b' : 'w';
        $res = [];
        foreach ($this->genPseudo() as $m) {
            $u = $this->make($m);
            if (!$this->attacked($this->kings[$me], $them)) {
                $res[] = $m;
            }
            $this->unmake($m, $u);
        }
        return $res;
    }

    /* ================================================================== */
    /* make / unmake                                                       */
    /* ================================================================== */

    /**
     * Applies a packed move unconditionally (callers must have filtered for
     * legality). Returns an undo record for unmake().
     */
    private function make(int $m): array
    {
        $from = $m & 0x7f;
        $to = ($m >> 7) & 0x7f;
        $promo = ($m >> 14) & 7;
        $flag = ($m >> 17) & 7;

        $isW = $this->turn === 'w';
        $dir = $isW ? 16 : -16;

        $captured = $this->bd[$to];
        $capSq = $to;
        if ($flag === self::F_EP) {
            $capSq = $to - $dir;
            $captured = $this->bd[$capSq];
        }

        $u = [$captured, $capSq, $this->cast, $this->ep, $this->half, $this->full, $this->kings[$this->turn]];

        $orig = $this->bd[$from];
        $this->bd[$to] = $promo !== self::P_NONE
            ? self::promoPiece($this->turn, $promo)
            : $orig;
        $this->bd[$from] = 0;
        if ($flag === self::F_EP) {
            $this->bd[$capSq] = 0;
        }

        if ($flag === self::F_KCAST) {        // king e1g1: rook h1 -> f1
            $this->bd[$to - 1] = $this->bd[$to + 1];
            $this->bd[$to + 1] = 0;
        } elseif ($flag === self::F_QCAST) {  // king e1c1: rook a1 -> d1
            $this->bd[$to + 1] = $this->bd[$to - 2];
            $this->bd[$to - 2] = 0;
        }

        if ($orig === 'K' || $orig === 'k') {
            $this->kings[$isW ? 'w' : 'b'] = $to;
        }

        // castling rights: king moved, rook moved from home, rook captured at home
        if ($orig === 'K') {
            $this->cast &= ~(self::CAST_K | self::CAST_Q);
        } elseif ($orig === 'k') {
            $this->cast &= ~(self::CAST_k | self::CAST_q);
        }
        // four independent tests: a move can touch two home squares (e.g. a
        // rook leaving h1 and capturing on a8)
        if ($from === 0 || $to === 0) {
            $this->cast &= ~self::CAST_Q;
        }
        if ($from === 7 || $to === 7) {
            $this->cast &= ~self::CAST_K;
        }
        if ($from === 112 || $to === 112) {
            $this->cast &= ~self::CAST_q;
        }
        if ($from === 119 || $to === 119) {
            $this->cast &= ~self::CAST_k;
        }

        // halfmove clock
        $pawnMove = $orig === 'P' || $orig === 'p';
        if ($pawnMove || $captured !== 0) {
            $this->half = 0;
        } else {
            $this->half++;
        }
        if (!$isW) {
            $this->full++;
        }

        // en passant square: kept (like chess.js) only when an enemy pawn is
        // pseudo-adjacent to the destination of the double push.
        if ($flag === self::F_DOUBLE) {
            $epSq = $to - $dir;
            $enemyPawn = $isW ? 'p' : 'P';
            $left = $to - 1;
            $right = $to + 1;
            if ((($left & 0x88) === 0 && $this->bd[$left] === $enemyPawn)
                || (($right & 0x88) === 0 && $this->bd[$right] === $enemyPawn)) {
                $this->ep = $epSq;
            } else {
                $this->ep = -1;
            }
        } else {
            $this->ep = -1;
        }

        $this->turn = $isW ? 'b' : 'w';
        return $u;
    }

    /** @param array<int, mixed> $u undo record from make() */
    private function unmake(int $m, array $u): void
    {
        [$captured, $capSq, $cast, $ep, $half, $full, $oldKing] = $u;
        $from = $m & 0x7f;
        $to = ($m >> 7) & 0x7f;
        $promo = ($m >> 14) & 7;
        $flag = ($m >> 17) & 7;

        $me = $this->turn === 'w' ? 'b' : 'w';   // side that made the move
        $isW = $me === 'w';

        if ($promo !== self::P_NONE) {
            $this->bd[$from] = $isW ? 'P' : 'p';
            $this->bd[$to] = 0;
        } else {
            $this->bd[$from] = $this->bd[$to];
            $this->bd[$to] = 0;
        }
        if ($flag === self::F_EP) {
            $this->bd[$capSq] = $captured;
        } elseif ($captured !== 0) {
            $this->bd[$to] = $captured;
        }
        if ($flag === self::F_KCAST) {
            $this->bd[$to + 1] = $this->bd[$to - 1];
            $this->bd[$to - 1] = 0;
        } elseif ($flag === self::F_QCAST) {
            $this->bd[$to - 2] = $this->bd[$to + 1];
            $this->bd[$to + 1] = 0;
        }

        $this->kings[$me] = $oldKing;
        $this->cast = $cast;
        $this->ep = $ep;
        $this->half = $half;
        $this->full = $full;
        $this->turn = $me;
    }

    /**
     * Commits a legal move (caller must have obtained $m from legalMoves()).
     * @param list<int> $legal legal move list of the current position
     * @return array{uci:string,san:string,fen:string}
     */
    private function applyMove(int $m, array $legal): array
    {
        $san = $this->sanBaseOf($m, $legal);
        $me = $this->turn;
        $them = $me === 'w' ? 'b' : 'w';

        $this->make($m);
        if ($this->attacked($this->kings[$them], $me)) {
            $san .= $this->legalMoves() === [] ? '#' : '+';
        }
        $uci = $this->toUci($m);
        $this->histUci[] = $uci;
        $this->histSan[] = $san;
        $this->keys[] = $this->key();
        return ['uci' => $uci, 'san' => $san, 'fen' => $this->fen()];
    }

    /* ================================================================== */
    /* attack detection                                                    */
    /* ================================================================== */

    /**
     * Is $sq attacked by any piece of colour $by?
     * Fully unrolled for speed: this runs once per pseudo-legal move.
     */
    private function attacked(int $sq, string $by): bool
    {
        $bd = $this->bd;
        if ($by === 'w') {
            $P = 'P';
            $N = 'N';
            $K = 'K';
            $R = 'R';
            $B = 'B';
            $Q = 'Q';
            // white pawns attack upwards: attacker = sq-15 / sq-17
            $s = $sq - 15;
            if (($s & 0x88) === 0 && $bd[$s] === $P) {
                return true;
            }
            $s = $sq - 17;
            if (($s & 0x88) === 0 && $bd[$s] === $P) {
                return true;
            }
        } else {
            $P = 'p';
            $N = 'n';
            $K = 'k';
            $R = 'r';
            $B = 'b';
            $Q = 'q';
            $s = $sq + 15;
            if (($s & 0x88) === 0 && $bd[$s] === $P) {
                return true;
            }
            $s = $sq + 17;
            if (($s & 0x88) === 0 && $bd[$s] === $P) {
                return true;
            }
        }
        // knights
        $s = $sq + 14;
        if (($s & 0x88) === 0 && $bd[$s] === $N) {
            return true;
        }
        $s = $sq + 18;
        if (($s & 0x88) === 0 && $bd[$s] === $N) {
            return true;
        }
        $s = $sq - 14;
        if (($s & 0x88) === 0 && $bd[$s] === $N) {
            return true;
        }
        $s = $sq - 18;
        if (($s & 0x88) === 0 && $bd[$s] === $N) {
            return true;
        }
        $s = $sq + 31;
        if (($s & 0x88) === 0 && $bd[$s] === $N) {
            return true;
        }
        $s = $sq + 33;
        if (($s & 0x88) === 0 && $bd[$s] === $N) {
            return true;
        }
        $s = $sq - 31;
        if (($s & 0x88) === 0 && $bd[$s] === $N) {
            return true;
        }
        $s = $sq - 33;
        if (($s & 0x88) === 0 && $bd[$s] === $N) {
            return true;
        }
        // king
        $s = $sq + 1;
        if (($s & 0x88) === 0 && $bd[$s] === $K) {
            return true;
        }
        $s = $sq - 1;
        if (($s & 0x88) === 0 && $bd[$s] === $K) {
            return true;
        }
        $s = $sq + 16;
        if (($s & 0x88) === 0 && $bd[$s] === $K) {
            return true;
        }
        $s = $sq - 16;
        if (($s & 0x88) === 0 && $bd[$s] === $K) {
            return true;
        }
        $s = $sq + 15;
        if (($s & 0x88) === 0 && $bd[$s] === $K) {
            return true;
        }
        $s = $sq - 15;
        if (($s & 0x88) === 0 && $bd[$s] === $K) {
            return true;
        }
        $s = $sq + 17;
        if (($s & 0x88) === 0 && $bd[$s] === $K) {
            return true;
        }
        $s = $sq - 17;
        if (($s & 0x88) === 0 && $bd[$s] === $K) {
            return true;
        }
        // rooks / queens: four rays
        $s = $sq + 1;
        while (($s & 0x88) === 0) {
            $p = $bd[$s];
            if ($p !== 0) {
                if ($p === $R || $p === $Q) {
                    return true;
                }
                break;
            }
            $s++;
        }
        $s = $sq - 1;
        while (($s & 0x88) === 0) {
            $p = $bd[$s];
            if ($p !== 0) {
                if ($p === $R || $p === $Q) {
                    return true;
                }
                break;
            }
            $s--;
        }
        $s = $sq + 16;
        while (($s & 0x88) === 0) {
            $p = $bd[$s];
            if ($p !== 0) {
                if ($p === $R || $p === $Q) {
                    return true;
                }
                break;
            }
            $s += 16;
        }
        $s = $sq - 16;
        while (($s & 0x88) === 0) {
            $p = $bd[$s];
            if ($p !== 0) {
                if ($p === $R || $p === $Q) {
                    return true;
                }
                break;
            }
            $s -= 16;
        }
        // bishops / queens: four diagonals
        $s = $sq + 15;
        while (($s & 0x88) === 0) {
            $p = $bd[$s];
            if ($p !== 0) {
                if ($p === $B || $p === $Q) {
                    return true;
                }
                break;
            }
            $s += 15;
        }
        $s = $sq - 15;
        while (($s & 0x88) === 0) {
            $p = $bd[$s];
            if ($p !== 0) {
                if ($p === $B || $p === $Q) {
                    return true;
                }
                break;
            }
            $s -= 15;
        }
        $s = $sq + 17;
        while (($s & 0x88) === 0) {
            $p = $bd[$s];
            if ($p !== 0) {
                if ($p === $B || $p === $Q) {
                    return true;
                }
                break;
            }
            $s += 17;
        }
        $s = $sq - 17;
        while (($s & 0x88) === 0) {
            $p = $bd[$s];
            if ($p !== 0) {
                if ($p === $B || $p === $Q) {
                    return true;
                }
                break;
            }
            $s -= 17;
        }
        return false;
    }

    /* ================================================================== */
    /* SAN                                                                 */
    /* ================================================================== */

    /** Full SAN including check/mate suffix (uses make/unmake internally). */
    private function sanOf(int $m, array $legal): string
    {
        $san = $this->sanBaseOf($m, $legal);
        $me = $this->turn;
        $them = $me === 'w' ? 'b' : 'w';
        $u = $this->make($m);
        if ($this->attacked($this->kings[$them], $me)) {
            $san .= $this->legalMoves() === [] ? '#' : '+';
        }
        $this->unmake($m, $u);
        return $san;
    }

    /** SAN without the check/mate suffix. Position must be pre-move. */
    private function sanBaseOf(int $m, array $legal): string
    {
        $from = $m & 0x7f;
        $to = ($m >> 7) & 0x7f;
        $promo = ($m >> 14) & 7;
        $flag = ($m >> 17) & 7;

        if ($flag === self::F_KCAST) {
            return 'O-O';
        }
        if ($flag === self::F_QCAST) {
            return 'O-O-O';
        }

        $p = $this->bd[$from];
        $isPawn = $p === 'P' || $p === 'p';
        $isCapture = $flag === self::F_CAP || $flag === self::F_EP;

        $san = '';
        if (!$isPawn) {
            $san .= chr(ord($p) & ~32);   // uppercase piece letter
            $san .= $this->disambiguator($m, $legal);
        } elseif ($isCapture) {
            $san .= chr(97 + ($from & 15));
        }
        if ($isCapture) {
            $san .= 'x';
        }
        $san .= $this->sqName($to);
        if ($promo !== self::P_NONE) {
            $san .= '=' . self::promoLetter($promo, true);
        }
        return $san;
    }

    private function disambiguator(int $m, array $legal): string
    {
        $from = $m & 0x7f;
        $to = ($m >> 7) & 0x7f;
        $type = chr(ord($this->bd[$from]) | 32);
        $count = 0;
        $sameFile = 0;
        $sameRank = 0;
        foreach ($legal as $o) {
            if ($o === $m) {
                continue;
            }
            $of = $o & 0x7f;
            if ((($o >> 7) & 0x7f) !== $to) {
                continue;
            }
            $op = $this->bd[$of];
            if ($op === 0 || chr(ord($op) | 32) !== $type) {
                continue;
            }
            $count++;
            if (($of & 15) === ($from & 15)) {
                $sameFile++;
            }
            if (($of >> 4) === ($from >> 4)) {
                $sameRank++;
            }
        }
        if ($count === 0) {
            return '';
        }
        if ($sameFile > 0 && $sameRank > 0) {
            return $this->sqName($from);
        }
        if ($sameFile > 0) {
            return (string) ((($from >> 4) + 1));
        }
        return chr(97 + ($from & 15));
    }

    /** Removes "=", "+", "#" and annotation suffixes for comparison. */
    private static function stripSan(string $san): string
    {
        $san = trim($san);
        if (str_starts_with($san, '0-')) {        // "0-0" / "0-0-0" style castling
            $san = str_replace('0', 'O', $san);
        }
        $san = (string) preg_replace('/[+#]?[?!]*$/', '', $san);
        $san = str_replace('=', '', $san);
        return $san;
    }

    /* ================================================================== */
    /* keys / helpers                                                      */
    /* ================================================================== */

    /**
     * Repetition key: board + side to move + castling rights + internal
     * en-passant square (excludes move counters, like the FIDE definition of
     * "the same position"). Strings are used instead of Zobrist hashes so
     * repetitions can never be missed through a hash collision.
     */
    private function key(): string
    {
        $s = '';
        for ($rank = 7; $rank >= 0; $rank--) {
            $empty = 0;
            for ($file = 0; $file < 8; $file++) {
                $p = $this->bd[$rank * 16 + $file];
                if ($p === 0) {
                    $empty++;
                    continue;
                }
                if ($empty > 0) {
                    $s .= $empty;
                    $empty = 0;
                }
                $s .= $p;
            }
            if ($empty > 0) {
                $s .= $empty;
            }
            $s .= '/';
        }
        return $s . ' ' . $this->turn . ' ' . $this->castlingStr() . ' ' . $this->epName();
    }

    private function threefoldCount(): int
    {
        $cur = $this->key();
        $n = 0;
        foreach ($this->keys as $k) {
            if ($k === $cur) {
                $n++;
            }
        }
        return $n;
    }

    private function castlingStr(): string
    {
        $s = '';
        if (($this->cast & self::CAST_K) !== 0) {
            $s .= 'K';
        }
        if (($this->cast & self::CAST_Q) !== 0) {
            $s .= 'Q';
        }
        if (($this->cast & self::CAST_k) !== 0) {
            $s .= 'k';
        }
        if (($this->cast & self::CAST_q) !== 0) {
            $s .= 'q';
        }
        return $s === '' ? '-' : $s;
    }

    private function epName(): string
    {
        return $this->ep < 0 ? '-' : $this->sqName($this->ep);
    }

    /**
     * FEN en-passant field: printed only when at least one legal ep capture
     * exists (same convention as chess.js, which matches "ep availability").
     */
    private function epDisplay(): string
    {
        if ($this->ep < 0) {
            return '-';
        }
        $ep = $this->ep;
        $isW = $this->turn === 'w';
        $cands = $isW ? [$ep - 15, $ep - 17] : [$ep + 15, $ep + 17];
        $pawn = $isW ? 'P' : 'p';
        foreach ($cands as $from) {
            if (($from & 0x88) !== 0 || $this->bd[$from] !== $pawn) {
                continue;
            }
            $m = self::pack($from, $ep, self::P_NONE, self::F_EP);
            $me = $this->turn;
            $them = $me === 'w' ? 'b' : 'w';
            $u = $this->make($m);
            $legal = !$this->attacked($this->kings[$me], $them);
            $this->unmake($m, $u);
            if ($legal) {
                return $this->sqName($ep);
            }
        }
        return '-';
    }

    private static function pack(int $from, int $to, int $promo, int $flag): int
    {
        return $from | ($to << 7) | ($promo << 14) | ($flag << 17);
    }

    private function toUci(int $m): string
    {
        $from = $m & 0x7f;
        $to = ($m >> 7) & 0x7f;
        $promo = ($m >> 14) & 7;
        $uci = $this->sqName($from) . $this->sqName($to);
        if ($promo !== self::P_NONE) {
            $uci .= self::promoLetter($promo, false);
        }
        return $uci;
    }

    private function sqName(int $sq): string
    {
        return chr(97 + ($sq & 15)) . (string) ((($sq >> 4) + 1));
    }

    private static function sqToIndex(string $sq): int
    {
        return (ord($sq[0]) - 97) + 16 * ((int) $sq[1] - 1);
    }

    private static function promoLetter(int $promo, bool $upper): string
    {
        $ch = match ($promo) {
            self::P_N => 'n',
            self::P_B => 'b',
            self::P_R => 'r',
            default   => 'q',
        };
        return $upper ? strtoupper($ch) : $ch;
    }

    private static function promoPiece(string $turn, int $promo): string
    {
        $ch = self::promoLetter($promo, false);
        return $turn === 'w' ? strtoupper($ch) : $ch;
    }
}

// Convenience aliases so consumers can use whichever fully-qualified name
// they expect (the project autoloader maps Checkmate\Chess\Rules to
// src/Chess/Rules.php).
if (!class_exists('Chess\Rules', false)) {
    class_alias(Rules::class, 'Chess\Rules');
}
if (!class_exists('Rules', false)) {
    class_alias(Rules::class, 'Rules');
}
