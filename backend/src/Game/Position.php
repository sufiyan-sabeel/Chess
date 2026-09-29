<?php

declare(strict_types=1);

namespace Checkmate\Game;

/**
 * An immutable chess position: board array, side to move, castling rights,
 * en-passant target and move clocks, with full legal move generation.
 *
 * Internal square indexing: 0 = a8, 1 = b8, ... 7 = h8,
 * 8 = a7 ... 56 = a1 ... 63 = h1.  (row 0 is rank 8, file is the low bits)
 *
 * Pieces are single FEN characters ('P'..'K' white, 'p'..'k' black);
 * empty squares hold the empty string.
 *
 * "Making" a move returns a NEW Position (PHP arrays are copy-on-write value
 * types), which keeps the engine free of make/unmake bookkeeping bugs at a
 * small performance cost.
 *
 * Semantics (FEN en-passant printing, insufficient material, SAN details)
 * follow the vendored reference implementation chess.js 1.4.0 so that both
 * engines produce byte-identical FENs for the same game.
 */
final class Position
{
    public const WHITE = 'w';
    public const BLACK = 'b';

    public const CASTLE_WHITE_KING  = 1;
    public const CASTLE_WHITE_QUEEN = 2;
    public const CASTLE_BLACK_KING  = 4;
    public const CASTLE_BLACK_QUEEN = 8;

    public const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    /** @var string[] 64 entries, '' = empty */
    private array $board;

    private string $turn;
    private int $castling;
    private ?int $epSquare;
    private int $halfmove;
    private int $fullmove;

    /** @var int[] knight offsets in the 8-wide board array */
    private const KNIGHT_OFFSETS = [-17, -15, -10, -6, 6, 10, 15, 17];

    /** @var int[] king offsets */
    private const KING_OFFSETS = [-9, -8, -7, -1, 1, 7, 8, 9];

    /**
     * Sliding rays as [fileStep, rowStep] pairs.
     * Row grows downwards (row 0 = rank 8), so rowStep 1 = towards rank 1.
     */
    private const ROOK_RAYS   = [[0, -1], [0, 1], [-1, 0], [1, 0]];
    private const BISHOP_RAYS = [[-1, -1], [1, -1], [-1, 1], [1, 1]];

    /** @var int[] promotion piece choices, queen first */
    private const PROMOTIONS = ['q', 'r', 'b', 'n'];

    /** @var Move[]|null memoised result of legalMoves(); positions are immutable */
    private ?array $legalMovesCache = null;

    /** home squares that grant/lose castling rights */
    private const ROOK_RIGHT_SQUARES = [
        56 => self::CASTLE_WHITE_QUEEN,   // a1
        63 => self::CASTLE_WHITE_KING,    // h1
        0  => self::CASTLE_BLACK_QUEEN,   // a8
        7  => self::CASTLE_BLACK_KING,    // h8
    ];

    private function __construct(array $board, string $turn, int $castling, ?int $epSquare, int $halfmove, int $fullmove)
    {
        $this->board = $board;
        $this->turn = $turn;
        $this->castling = $castling;
        $this->epSquare = $epSquare;
        $this->halfmove = $halfmove;
        $this->fullmove = $fullmove;
    }

    // ------------------------------------------------------------------
    // Square helpers
    // ------------------------------------------------------------------

    public static function squareIndex(string $algebraic): int
    {
        $file = ord($algebraic[0]) - 97;   // 'a' -> 0
        $rank = 8 - (int) $algebraic[1];   // '8' -> 0
        return $rank * 8 + $file;
    }

    public static function squareName(int $index): string
    {
        return chr(97 + ($index & 7)) . (string) (8 - ($index >> 3));
    }

    public static function isValidSquare(string $sq): bool
    {
        return strlen($sq) === 2
            && $sq[0] >= 'a' && $sq[0] <= 'h'
            && $sq[1] >= '1' && $sq[1] <= '8';
    }

    /** Uppercase FEN letters are white pieces. */
    private static function isWhitePiece(string $piece): bool
    {
        return $piece >= 'A' && $piece <= 'Z';
    }

    // ------------------------------------------------------------------
    // FEN
    // ------------------------------------------------------------------

    /**
     * Parse and validate a FEN string (all six fields required).
     *
     * @throws InvalidFenException
     */
    public static function fromFen(string $fen): self
    {
        $tokens = preg_split('/\s+/', trim($fen));
        if ($tokens === false || count($tokens) !== 6) {
            throw new InvalidFenException('FEN must contain six space-delimited fields');
        }
        [$placement, $turn, $castling, $ep, $halfmove, $fullmove] = $tokens;

        if ($turn !== self::WHITE && $turn !== self::BLACK) {
            throw new InvalidFenException('FEN side-to-move field must be "w" or "b"');
        }
        if ($castling !== '-' && ($castling === '' || str_contains($castling, '-')
            || count(array_unique(str_split($castling))) !== strlen($castling)
            || preg_match('/[^KQkq]/', $castling))) {
            throw new InvalidFenException('FEN castling field is invalid');
        }
        if (!preg_match('/^(-|[a-h][36])$/', $ep)) {
            throw new InvalidFenException('FEN en-passant field is invalid');
        }
        if (!preg_match('/^\d+$/', $halfmove)) {
            throw new InvalidFenException('FEN halfmove clock must be a non-negative integer');
        }
        if (!preg_match('/^[1-9]\d*$/', $fullmove)) {
            throw new InvalidFenException('FEN fullmove number must be a positive integer');
        }
        if ($ep !== '-') {
            $epRank = $ep[1];
            // rank 3 is behind a white double push (black to move), rank 6
            // behind a black one (white to move)
            if (($epRank === '3' && $turn === self::WHITE) || ($epRank === '6' && $turn === self::BLACK)) {
                throw new InvalidFenException('FEN en-passant square is on the wrong rank for the side to move');
            }
        }

        $rows = explode('/', $placement);
        if (count($rows) !== 8) {
            throw new InvalidFenException('FEN piece data must contain 8 "/"-delimited rows');
        }
        $board = array_fill(0, 64, '');
        $kings = [self::WHITE => 0, self::BLACK => 0];
        foreach ($rows as $rowIndex => $row) {
            $file = 0;
            $previousWasDigit = false;
            $length = strlen($row);
            for ($i = 0; $i < $length; $i++) {
                $ch = $row[$i];
                if ($ch >= '1' && $ch <= '8') {
                    if ($previousWasDigit) {
                        throw new InvalidFenException('FEN piece data contains consecutive digits');
                    }
                    $file += (int) $ch;
                    $previousWasDigit = true;
                    continue;
                }
                if (!str_contains('pnbrqkPNBRQK', $ch)) {
                    throw new InvalidFenException("FEN piece data contains invalid character \"{$ch}\"");
                }
                if (($ch === 'P' || $ch === 'p') && ($rowIndex === 0 || $rowIndex === 7)) {
                    throw new InvalidFenException('FEN must not place pawns on the first or eighth rank');
                }
                if ($ch === 'K') {
                    $kings[self::WHITE]++;
                } elseif ($ch === 'k') {
                    $kings[self::BLACK]++;
                }
                if ($file > 7) {
                    throw new InvalidFenException('FEN row contains more than 8 squares');
                }
                $board[$rowIndex * 8 + $file] = $ch;
                $file++;
                $previousWasDigit = false;
            }
            if ($file !== 8) {
                throw new InvalidFenException('FEN row does not describe exactly 8 squares');
            }
        }
        if ($kings[self::WHITE] !== 1 || $kings[self::BLACK] !== 1) {
            throw new InvalidFenException('FEN must contain exactly one king of each colour');
        }

        $rights = 0;
        if (str_contains($castling, 'K')) {
            $rights |= self::CASTLE_WHITE_KING;
        }
        if (str_contains($castling, 'Q')) {
            $rights |= self::CASTLE_WHITE_QUEEN;
        }
        if (str_contains($castling, 'k')) {
            $rights |= self::CASTLE_BLACK_KING;
        }
        if (str_contains($castling, 'q')) {
            $rights |= self::CASTLE_BLACK_QUEEN;
        }

        return new self(
            $board,
            $turn,
            $rights,
            $ep === '-' ? null : self::squareIndex($ep),
            (int) $halfmove,
            (int) $fullmove,
        );
    }

    public static function initial(): self
    {
        return self::fromFen(self::START_FEN);
    }

    /**
     * Export as FEN.
     *
     * The en-passant field follows the same rule as chess.js 1.4.0: it is
     * printed only when an en-passant capture is actually legal (a pawn of the
     * side to move can capture it and doing so does not leave its own king in
     * check). This makes FENs of really-identical positions identical.
     */
    public function fen(): string
    {
        $rows = [];
        for ($row = 0; $row < 8; $row++) {
            $line = '';
            $empty = 0;
            for ($file = 0; $file < 8; $file++) {
                $piece = $this->board[$row * 8 + $file];
                if ($piece === '') {
                    $empty++;
                    continue;
                }
                if ($empty > 0) {
                    $line .= $empty;
                    $empty = 0;
                }
                $line .= $piece;
            }
            if ($empty > 0) {
                $line .= $empty;
            }
            $rows[] = $line;
        }

        return implode(' ', [
            implode('/', $rows),
            $this->turn,
            $this->castlingField(),
            $this->printableEpSquare(),
            (string) $this->halfmove,
            (string) $this->fullmove,
        ]);
    }

    private function castlingField(): string
    {
        $s = '';
        if (($this->castling & self::CASTLE_WHITE_KING) !== 0) {
            $s .= 'K';
        }
        if (($this->castling & self::CASTLE_WHITE_QUEEN) !== 0) {
            $s .= 'Q';
        }
        if (($this->castling & self::CASTLE_BLACK_KING) !== 0) {
            $s .= 'k';
        }
        if (($this->castling & self::CASTLE_BLACK_QUEEN) !== 0) {
            $s .= 'q';
        }
        return $s === '' ? '-' : $s;
    }

    private function printableEpSquare(): string
    {
        if ($this->epSquare === null) {
            return '-';
        }
        foreach ($this->legalMoves() as $move) {
            if ($move->isEnPassant() && $move->to === $this->epSquare) {
                return self::squareName($this->epSquare);
            }
        }
        return '-';
    }

    /**
     * Position identity for threefold-repetition detection: the first four
     * FEN fields (board, side to move, castling rights, en-passant target).
     * Two positions are identical (FIDE) iff their keys are equal.
     */
    public function positionKey(): string
    {
        $fen = $this->fen();
        // drop the two trailing clock fields (" ... <halfmove> <fullmove>")
        $lastSpace = strrpos($fen, ' ');
        $secondLast = strrpos(substr($fen, 0, (int) $lastSpace), ' ');
        return substr($fen, 0, (int) $secondLast);
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    public function turn(): string
    {
        return $this->turn;
    }

    public function pieceAt(string $square): string
    {
        return $this->board[self::squareIndex($square)];
    }

    /** @return string[] raw 64-square board array */
    public function board(): array
    {
        return $this->board;
    }

    public function castlingRights(): int
    {
        return $this->castling;
    }

    public function halfmoveClock(): int
    {
        return $this->halfmove;
    }

    public function fullmoveNumber(): int
    {
        return $this->fullmove;
    }

    public function epSquare(): ?int
    {
        return $this->epSquare;
    }

    public function kingSquare(string $color): ?int
    {
        $target = $color === self::WHITE ? 'K' : 'k';
        $index = array_search($target, $this->board, true);
        return $index === false ? null : (int) $index;
    }

    // ------------------------------------------------------------------
    // Attacks
    // ------------------------------------------------------------------

    /** Is $square attacked by a piece of colour $by? */
    public function isSquareAttacked(int $square, string $by): bool
    {
        $board = $this->board;
        $row = $square >> 3;
        $file = $square & 7;

        // pawns: a white attacker sits one row below (row+1 is towards rank 1);
        // a black attacker one row above.
        if ($by === self::WHITE) {
            if ($row < 7) {
                if ($file > 0 && $board[($row + 1) * 8 + $file - 1] === 'P') {
                    return true;
                }
                if ($file < 7 && $board[($row + 1) * 8 + $file + 1] === 'P') {
                    return true;
                }
            }
        } else {
            if ($row > 0) {
                if ($file > 0 && $board[($row - 1) * 8 + $file - 1] === 'p') {
                    return true;
                }
                if ($file < 7 && $board[($row - 1) * 8 + $file + 1] === 'p') {
                    return true;
                }
            }
        }

        // knights
        $knight = $by === self::WHITE ? 'N' : 'n';
        foreach (self::KNIGHT_OFFSETS as $offset) {
            $target = $square + $offset;
            if ($target < 0 || $target > 63 || abs(($target & 7) - $file) > 2) {
                continue;
            }
            if ($board[$target] === $knight) {
                return true;
            }
        }

        // king
        $king = $by === self::WHITE ? 'K' : 'k';
        foreach (self::KING_OFFSETS as $offset) {
            $target = $square + $offset;
            if ($target < 0 || $target > 63 || abs(($target & 7) - $file) > 1) {
                continue;
            }
            if ($board[$target] === $king) {
                return true;
            }
        }

        // sliding pieces
        $queen = $by === self::WHITE ? 'Q' : 'q';
        $rook = $by === self::WHITE ? 'R' : 'r';
        $bishop = $by === self::WHITE ? 'B' : 'b';
        foreach (self::ROOK_RAYS as [$stepFile, $stepRow]) {
            $f = $file + $stepFile;
            $r = $row + $stepRow;
            while ($f >= 0 && $f <= 7 && $r >= 0 && $r <= 7) {
                $piece = $board[$r * 8 + $f];
                if ($piece !== '') {
                    if ($piece === $rook || $piece === $queen) {
                        return true;
                    }
                    break;
                }
                $f += $stepFile;
                $r += $stepRow;
            }
        }
        foreach (self::BISHOP_RAYS as [$stepFile, $stepRow]) {
            $f = $file + $stepFile;
            $r = $row + $stepRow;
            while ($f >= 0 && $f <= 7 && $r >= 0 && $r <= 7) {
                $piece = $board[$r * 8 + $f];
                if ($piece !== '') {
                    if ($piece === $bishop || $piece === $queen) {
                        return true;
                    }
                    break;
                }
                $f += $stepFile;
                $r += $stepRow;
            }
        }

        return false;
    }

    /** Is the king of $color currently in check? */
    public function isCheck(string $color): bool
    {
        $king = $this->kingSquare($color);
        if ($king === null) {
            return false;
        }
        return $this->isSquareAttacked($king, $color === self::WHITE ? self::BLACK : self::WHITE);
    }

    // ------------------------------------------------------------------
    // Move generation
    // ------------------------------------------------------------------

    /** All legal moves for the side to move (memoised). */
    public function legalMoves(): array
    {
        if ($this->legalMovesCache !== null) {
            return $this->legalMovesCache;
        }
        $legal = [];
        $us = $this->turn;
        foreach ($this->pseudoLegalMoves() as $move) {
            if (!$this->make($move)->isCheck($us)) {
                $legal[] = $move;
            }
        }
        return $this->legalMovesCache = $legal;
    }

    /**
     * Pseudo-legal moves: pins and king safety are NOT checked (except
     * castling through check, which is part of the castling rules).
     *
     * @return Move[]
     */
    public function pseudoLegalMoves(): array
    {
        $moves = [];
        $board = $this->board;
        $us = $this->turn;
        $whiteToMove = $us === self::WHITE;

        for ($square = 0; $square < 64; $square++) {
            $piece = $board[$square];
            if ($piece === '' || self::isWhitePiece($piece) !== $whiteToMove) {
                continue; // empty or opponent's piece
            }
            switch (strtolower($piece)) {
                case 'p':
                    $this->generatePawnMoves($square, $moves);
                    break;
                case 'n':
                    $this->generateStepMoves($square, $moves, self::KNIGHT_OFFSETS, 2);
                    break;
                case 'k':
                    $this->generateStepMoves($square, $moves, self::KING_OFFSETS, 1);
                    break;
                case 'b':
                    $this->generateSlideMoves($square, $moves, self::BISHOP_RAYS);
                    break;
                case 'r':
                    $this->generateSlideMoves($square, $moves, self::ROOK_RAYS);
                    break;
                case 'q':
                    $this->generateSlideMoves($square, $moves, self::ROOK_RAYS);
                    $this->generateSlideMoves($square, $moves, self::BISHOP_RAYS);
                    break;
            }
        }

        $this->generateCastlingMoves($moves);
        return $moves;
    }

    private function generatePawnMoves(int $square, array &$moves): void
    {
        $board = $this->board;
        $white = $this->turn === self::WHITE;
        $row = $square >> 3;
        $file = $square & 7;
        $step = $white ? -8 : 8;             // direction of travel
        $startRow = $white ? 6 : 1;
        $promoRow = $white ? 0 : 7;
        $victim = $white ? 'p' : 'P';        // the pawn that would be captured e.p.

        // single and double pushes
        $one = $square + $step;
        if ($board[$one] === '') {
            if (($one >> 3) === $promoRow) {
                foreach (self::PROMOTIONS as $promotion) {
                    $moves[] = new Move($square, $one, 'p', Move::FLAG_PROMOTION, null, $promotion);
                }
            } else {
                $moves[] = new Move($square, $one, 'p', Move::FLAG_NORMAL);
                if ($row === $startRow) {
                    $two = $square + 2 * $step;
                    if ($board[$two] === '') {
                        $moves[] = new Move($square, $two, 'p', Move::FLAG_BIG_PAWN);
                    }
                }
            }
        }

        // captures and en passant
        foreach ([-1, 1] as $fileStep) {
            if ($file + $fileStep < 0 || $file + $fileStep > 7) {
                continue;
            }
            $target = $square + $step + $fileStep;
            $occupant = $board[$target];
            if ($occupant !== '' && self::isWhitePiece($occupant) !== $white) {
                if (($target >> 3) === $promoRow) {
                    foreach (self::PROMOTIONS as $promotion) {
                        $moves[] = new Move(
                            $square,
                            $target,
                            'p',
                            Move::FLAG_CAPTURE | Move::FLAG_PROMOTION,
                            strtolower($occupant),
                            $promotion,
                        );
                    }
                } else {
                    $moves[] = new Move($square, $target, 'p', Move::FLAG_CAPTURE, strtolower($occupant));
                }
            } elseif ($occupant === '' && $target === $this->epSquare && $board[$target - $step] === $victim) {
                $moves[] = new Move($square, $target, 'p', Move::FLAG_CAPTURE | Move::FLAG_EN_PASSANT, 'p');
            }
        }
    }

    /** Knights and kings: offsets, skipping board-edge wraps. */
    private function generateStepMoves(int $square, array &$moves, array $offsets, int $maxFileDelta): void
    {
        $board = $this->board;
        $white = $this->turn === self::WHITE;
        $file = $square & 7;
        $pieceType = strtolower($board[$square]);
        foreach ($offsets as $offset) {
            $target = $square + $offset;
            if ($target < 0 || $target > 63 || abs(($target & 7) - $file) > $maxFileDelta) {
                continue;
            }
            $occupant = $board[$target];
            if ($occupant === '') {
                $moves[] = new Move($square, $target, $pieceType, Move::FLAG_NORMAL);
            } elseif (self::isWhitePiece($occupant) !== $white) {
                $moves[] = new Move($square, $target, $pieceType, Move::FLAG_CAPTURE, strtolower($occupant));
            }
        }
    }

    /** Bishops, rooks, queens: sliding rays. */
    private function generateSlideMoves(int $square, array &$moves, array $rays): void
    {
        $board = $this->board;
        $white = $this->turn === self::WHITE;
        $pieceType = strtolower($board[$square]);
        $file = $square & 7;
        $row = $square >> 3;
        foreach ($rays as [$stepFile, $stepRow]) {
            $f = $file + $stepFile;
            $r = $row + $stepRow;
            while ($f >= 0 && $f <= 7 && $r >= 0 && $r <= 7) {
                $target = $r * 8 + $f;
                $occupant = $board[$target];
                if ($occupant === '') {
                    $moves[] = new Move($square, $target, $pieceType, Move::FLAG_NORMAL);
                } else {
                    if (self::isWhitePiece($occupant) !== $white) {
                        $moves[] = new Move($square, $target, $pieceType, Move::FLAG_CAPTURE, strtolower($occupant));
                    }
                    break;
                }
                $f += $stepFile;
                $r += $stepRow;
            }
        }
    }

    /**
     * Castling requires: rights, king and rook on their home squares, empty
     * transit squares, and the king may not start on, pass through, or land
     * on an attacked square.
     */
    private function generateCastlingMoves(array &$moves): void
    {
        $them = $this->turn === self::WHITE ? self::BLACK : self::WHITE;
        $board = $this->board;
        if ($this->turn === self::WHITE) {
            if (($this->castling & self::CASTLE_WHITE_KING) !== 0
                && $board[60] === 'K' && $board[63] === 'R'
                && $this->castlingSquaresFree(61, 62)
                && !$this->isSquareAttacked(60, $them)
                && !$this->isSquareAttacked(61, $them)
                && !$this->isSquareAttacked(62, $them)
            ) {
                $moves[] = new Move(60, 62, 'k', Move::FLAG_KSIDE);
            }
            if (($this->castling & self::CASTLE_WHITE_QUEEN) !== 0
                && $board[60] === 'K' && $board[56] === 'R'
                && $this->castlingSquaresFree(57, 58, 59)
                && !$this->isSquareAttacked(60, $them)
                && !$this->isSquareAttacked(59, $them)
                && !$this->isSquareAttacked(58, $them)
            ) {
                $moves[] = new Move(60, 58, 'k', Move::FLAG_QSIDE);
            }
        } else {
            if (($this->castling & self::CASTLE_BLACK_KING) !== 0
                && $board[4] === 'k' && $board[7] === 'r'
                && $this->castlingSquaresFree(5, 6)
                && !$this->isSquareAttacked(4, $them)
                && !$this->isSquareAttacked(5, $them)
                && !$this->isSquareAttacked(6, $them)
            ) {
                $moves[] = new Move(4, 6, 'k', Move::FLAG_KSIDE);
            }
            if (($this->castling & self::CASTLE_BLACK_QUEEN) !== 0
                && $board[4] === 'k' && $board[0] === 'r'
                && $this->castlingSquaresFree(1, 2, 3)
                && !$this->isSquareAttacked(4, $them)
                && !$this->isSquareAttacked(3, $them)
                && !$this->isSquareAttacked(2, $them)
            ) {
                $moves[] = new Move(4, 2, 'k', Move::FLAG_QSIDE);
            }
        }
    }

    private function castlingSquaresFree(int ...$squares): bool
    {
        foreach ($squares as $square) {
            if ($this->board[$square] !== '') {
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Making moves
    // ------------------------------------------------------------------

    /**
     * Apply a (pseudo-)legal move and return the resulting position.
     * The move must have been generated from this position.
     */
    public function make(Move $move): self
    {
        $board = $this->board;
        $white = $this->turn === self::WHITE;
        $them = $white ? self::BLACK : self::WHITE;
        $castling = $this->castling;
        $halfmove = $this->halfmove + 1;
        $fullmove = $this->fullmove;

        $from = $move->from;
        $to = $move->to;
        $piece = $board[$from];
        $type = strtolower($piece);

        if ($move->isEnPassant()) {
            $board[$to - ($white ? -8 : 8)] = '';   // remove the captured pawn
            $halfmove = 0;
        } elseif ($move->isCapture()) {
            $halfmove = 0;
        }
        if ($type === 'p') {
            $halfmove = 0;
        }

        $board[$to] = $move->promotion !== null
            ? ($white ? strtoupper($move->promotion) : $move->promotion)
            : $piece;
        $board[$from] = '';

        if ($move->isCastle()) {
            if ($move->isKingsideCastle()) {
                $rookFrom = $white ? 63 : 7;
                $rookTo = $to - 1;
            } else {
                $rookFrom = $white ? 56 : 0;
                $rookTo = $to + 1;
            }
            $board[$rookTo] = $board[$rookFrom];
            $board[$rookFrom] = '';
        }

        // castling rights: any king move drops both of that side's rights;
        // a rook moving from, or any piece landing on, a home corner drops
        // the corresponding right (same rule as chess.js).
        if ($type === 'k') {
            $castling &= $white
                ? ~(self::CASTLE_WHITE_KING | self::CASTLE_WHITE_QUEEN)
                : ~(self::CASTLE_BLACK_KING | self::CASTLE_BLACK_QUEEN);
        }
        if (isset(self::ROOK_RIGHT_SQUARES[$from])) {
            $castling &= ~self::ROOK_RIGHT_SQUARES[$from];
        }
        if (isset(self::ROOK_RIGHT_SQUARES[$to])) {
            $castling &= ~self::ROOK_RIGHT_SQUARES[$to];
        }

        // en-passant target exists only after a double pawn push
        $epSquare = null;
        if ($move->isBigPawn()) {
            $epSquare = $to - ($white ? -8 : 8);
        }

        if (!$white) {
            $fullmove++;   // black just moved
        }

        return new self($board, $them, $castling & 15, $epSquare, $halfmove, $fullmove);
    }

    // ------------------------------------------------------------------
    // SAN
    // ------------------------------------------------------------------

    /** Standard algebraic notation for a move of this position. */
    public function toSan(Move $move): string
    {
        if ($move->isKingsideCastle()) {
            $san = 'O-O';
        } elseif ($move->isQueensideCastle()) {
            $san = 'O-O-O';
        } else {
            $san = '';
            if ($move->piece !== 'p') {
                $san .= strtoupper($move->piece) . $this->disambiguator($move);
            }
            if ($move->isCapture()) {
                if ($move->piece === 'p') {
                    $san .= chr(97 + ($move->from & 7));
                }
                $san .= 'x';
            }
            $san .= self::squareName($move->to);
            if ($move->promotion !== null) {
                $san .= '=' . strtoupper($move->promotion);
            }
        }

        $next = $this->make($move);
        if ($next->isCheck($next->turn)) {
            $san .= $next->legalMoves() === [] ? '#' : '+';
        }
        return $san;
    }

    private function disambiguator(Move $move): string
    {
        $ambiguities = 0;
        $sameFile = false;
        $sameRank = false;
        foreach ($this->legalMoves() as $other) {
            if ($other->piece !== $move->piece || $other->to !== $move->to || $other->from === $move->from) {
                continue;
            }
            $ambiguities++;
            if (($other->from & 7) === ($move->from & 7)) {
                $sameFile = true;
            }
            if (($other->from >> 3) === ($move->from >> 3)) {
                $sameRank = true;
            }
        }
        if ($ambiguities === 0) {
            return '';
        }
        if ($sameFile && $sameRank) {
            return self::squareName($move->from);   // full square disambiguation
        }
        if ($sameFile) {
            return (string) (8 - ($move->from >> 3)); // rank digit
        }
        return chr(97 + ($move->from & 7));            // file letter
    }

    /**
     * Resolve a move string (SAN or UCI) against this position.
     * Returns null when the string does not identify a legal move.
     *
     * Accepts standard SAN ("Nf3", "exd5", "O-O", "e8=Q#", "Nbd7", "R1a3")
     * with optional decorations/move numbers, and UCI ("e2e4", "e7e8q").
     */
    public function parseMove(string $input): ?Move
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // UCI form: e2e4, e7e8q (case-insensitive)
        if (preg_match('/^([a-h][1-8])([a-h][1-8])([qrbn])?$/i', $input, $m)) {
            $from = self::squareIndex(strtolower($m[1]));
            $to = self::squareIndex(strtolower($m[2]));
            $promotion = isset($m[3]) ? strtolower($m[3]) : null;
            foreach ($this->legalMoves() as $move) {
                if ($move->from === $from && $move->to === $to && $move->promotion === $promotion) {
                    return $move;
                }
            }
            return null;
        }

        // strip move numbers ("12...") and decorations ("Nf3+", "exd5!", "e8=Q")
        $clean = preg_replace('/^\d+\s*\.\s*(\.\.\.)?\s*/', '', $input) ?? $input;
        $clean = preg_replace('/=([qrbnQRBN])$/', '$1', $clean) ?? $clean;
        $clean = preg_replace('/[+#]/', '', $clean) ?? $clean;
        $clean = preg_replace('/[?!]+$/', '', $clean) ?? $clean;
        $clean = str_replace(['0-0-0', '0-0'], ['O-O-O', 'O-O'], $clean);

        $legal = $this->legalMoves();

        // stage 1: strict match against generated SAN (modulo decorations)
        foreach ($legal as $move) {
            if ($this->stripSan($this->toSan($move)) === $this->stripSan($clean)) {
                return $move;
            }
        }

        // stage 2: permissive parse (Pe2-e4, Rc1c4, f7f8q, Nge7, Qh4xe1 ...)
        if (!preg_match('/^([pnbrqkPNBRQK])?([a-h]?[1-8]?)x?-?([a-h][1-8])([qrbnQRBN])?$/', $clean, $m)) {
            return null;
        }
        $pieceHint = isset($m[1]) && $m[1] !== '' ? strtolower($m[1]) : null;
        $fromHint = $m[2];
        $to = self::squareIndex($m[3]);
        $promotion = isset($m[4]) && $m[4] !== '' ? strtolower($m[4]) : null;

        $candidates = [];
        foreach ($legal as $move) {
            if ($move->to !== $to) {
                continue;
            }
            if ($pieceHint !== null && $move->piece !== $pieceHint) {
                continue;
            }
            if ($promotion !== $move->promotion) {
                continue;
            }
            if (strlen($fromHint) === 2 && $move->from !== self::squareIndex($fromHint)) {
                continue;
            }
            $candidates[] = $move;
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if (count($candidates) > 1 && strlen($fromHint) === 1) {
            // over-disambiguated: "Nge7" style, from hint is a file or rank
            foreach ($candidates as $move) {
                $name = self::squareName($move->from);
                if ($name[0] === $fromHint || $name[1] === $fromHint) {
                    return $move;
                }
            }
        }
        return null;
    }

    private function stripSan(string $san): string
    {
        $san = str_replace('=', '', $san);
        $san = preg_replace('/[+#]/', '', $san) ?? $san;
        $san = preg_replace('/[?!]+$/', '', $san) ?? $san;
        return $san;
    }

    // ------------------------------------------------------------------
    // Terminal / draw conditions
    // ------------------------------------------------------------------

    public function hasLegalMove(): bool
    {
        return $this->legalMoves() !== [];
    }

    public function isCheckmate(): bool
    {
        return $this->isCheck($this->turn) && !$this->hasLegalMove();
    }

    public function isStalemate(): bool
    {
        return !$this->isCheck($this->turn) && !$this->hasLegalMove();
    }

    /**
     * Same rule as chess.js 1.4.0: K vs K, K+minor vs K, or every remaining
     * non-king piece is a bishop and all of them stand on one square colour.
     */
    public function isInsufficientMaterial(): bool
    {
        $bishops = 0;
        $knights = 0;
        $others = 0;              // pawns, rooks, queens
        $bishopColorSum = 0;      // sum of square-colour bits over the bishops
        $pieceCount = 0;
        for ($square = 0; $square < 64; $square++) {
            $piece = $this->board[$square];
            if ($piece === '') {
                continue;
            }
            $pieceCount++;
            $type = strtolower($piece);
            if ($type === 'k') {
                continue;
            }
            if ($type === 'b') {
                $bishops++;
                $bishopColorSum += (($square >> 3) + ($square & 7)) & 1;
                continue;
            }
            if ($type === 'n') {
                $knights++;
                continue;
            }
            $others++;
        }
        if ($pieceCount === 2) {
            return true;                       // k vs k
        }
        if ($pieceCount === 3 && ($bishops === 1 || $knights === 1)) {
            return true;                       // k+minor vs k
        }
        if ($others === 0 && $knights === 0 && $bishops === $pieceCount - 2) {
            // kb..b vs kb..b: a draw only when every bishop stands on the
            // same square colour, i.e. the colour sum is 0 or the bishop count
            return $bishopColorSum === 0 || $bishopColorSum === $bishops;
        }
        return false;
    }

    public function isFiftyMoveDraw(): bool
    {
        return $this->halfmove >= 100;
    }

    // ------------------------------------------------------------------
    // Perft
    // ------------------------------------------------------------------

    public function perft(int $depth): int
    {
        if ($depth <= 0) {
            return 1;
        }
        $nodes = 0;
        foreach ($this->legalMoves() as $move) {
            $nodes += $this->make($move)->perft($depth - 1);
        }
        return $nodes;
    }

    /**
     * Perft split by root move (useful for debugging mismatches).
     *
     * @return array<string, int>
     */
    public function perftDivide(int $depth): array
    {
        $result = [];
        foreach ($this->legalMoves() as $move) {
            $result[$this->toSan($move)] = $this->make($move)->perft($depth - 1);
        }
        return $result;
    }
}
