<?php

declare(strict_types=1);

/**
 * Checkmate chess engine test suite (pure PHP).
 *
 * Sections:
 *   1. unit cases - FEN round-trips, SAN generation/parsing, castling,
 *      en passant, promotion, mate detection (fool's / scholar's / smothered),
 *      stalemate, insufficient material, threefold repetition, 50-move rule,
 *      GameResult, move-list validation (ILLEGAL_MOVE index)
 *   2. perft table against known-good reference node counts
 *      (expected values independently re-verified against chess.js 1.4.0)
 *   3. random cross-check against chess.js-generated games - only runs when
 *      the data file exists (generate it with backend/tests/helpers/
 *      generate-random-games.mjs; requires Node, optional in CI)
 *
 * Usage:  php backend/tests/chess-suite.php
 * Exit code 0 = everything passed, 1 = at least one failure.
 */

require_once __DIR__ . '/../src/Autoloader.php';
\Checkmate\Autoloader::register();

require_once __DIR__ . '/perft.php';
require_once __DIR__ . '/chess-random.php';

use Checkmate\Game\Game;
use Checkmate\Game\GameResult;
use Checkmate\Game\IllegalMoveException;
use Checkmate\Game\InvalidFenException;
use Checkmate\Game\Position;

// ---------------------------------------------------------------------------
// tiny test harness
// ---------------------------------------------------------------------------

$GLOBALS['chess_suite_pass'] = 0;
$GLOBALS['chess_suite_fail'] = 0;
$GLOBALS['chess_suite_failures'] = [];

function expectTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        $export = static fn (mixed $v): string => is_string($v) ? $v : json_encode($v);
        throw new RuntimeException(
            "{$message} (expected {$export($expected)}, got {$export($actual)})",
        );
    }
}

function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['chess_suite_pass']++;
        printf("  PASS  %s\n", $name);
    } catch (Throwable $e) {
        $GLOBALS['chess_suite_fail']++;
        $GLOBALS['chess_suite_failures'][] = "{$name}: {$e->getMessage()}";
        printf("  FAIL  %s\n        %s\n", $name, $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 1. unit tests
// ---------------------------------------------------------------------------

echo "== Unit tests ==\n";

// --- FEN -------------------------------------------------------------------

test('FEN round-trip: start position', function (): void {
    expectSame(Position::START_FEN, Position::initial()->fen(), 'start FEN round-trip');
});

test('FEN round-trip: all perft benchmark positions', function (): void {
    foreach (perftPositions() as $position) {
        expectSame($position['fen'], Position::fromFen($position['fen'])->fen(), $position['name']);
    }
});

test('FEN round-trip: stable after re-import', function (): void {
    foreach (perftPositions() as $position) {
        $once = Position::fromFen($position['fen'])->fen();
        $twice = Position::fromFen($once)->fen();
        expectSame($once, $twice, $position['name'] . ' double round-trip');
    }
});

test('FEN en-passant field follows reference rule (no capturer -> "-")', function (): void {
    // After 1.e4 no black pawn can capture e3, so neither chess.js 1.4.0 nor
    // this engine print the ep square.
    $game = Game::start()->applyMoves(['e4']);
    $fen = $game->fen();
    expectTrue(str_contains($fen, '4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1'), "unexpected FEN: {$fen}");
});

test('FEN en-passant field printed when capture is legal', function (): void {
    $game = Game::start()->applyMoves(['e4', 'e6', 'e5', 'd5']);
    expectTrue(
        str_ends_with($game->fen(), ' d6 0 3'),
        'ep square d6 should be printed: ' . $game->fen(),
    );
});

test('FEN rejects invalid input', function (): void {
    $bad = [
        'too few fields'          => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -',
        'bad side to move'        => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR x KQkq - 0 1',
        'bad castling field'      => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w XQkq - 0 1',
        'missing black king'      => '8/8/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
        'too many white kings'    => 'KKN5/8/8/8/8/8/8/7k w - - 0 1',
        'pawn on rank 8'          => 'P3k3/8/8/8/8/8/8/7K w - - 0 1',
        'row with 9 squares'      => 'rnbqkbnr/pppppppp/9/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
        'seven ranks'             => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP w KQkq - 0 1',
        'bad ep rank'             => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq e4 0 1',
        'halfmove not a number'   => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - x 1',
        'fullmove zero'           => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 0',
    ];
    foreach ($bad as $label => $fen) {
        try {
            Position::fromFen($fen);
            throw new RuntimeException("expected rejection: {$label}");
        } catch (InvalidFenException) {
            // correctly rejected
        }
    }
});

// --- SAN generation --------------------------------------------------------

test('SAN: quiet moves, captures, disambiguation from a real game', function (): void {
    $game = Game::start()->applyMoves(['e4', 'e5', 'Nf3', 'Nc6', 'Bb5', 'a6', 'Ba4', 'Nf6']);
    expectSame(['e4', 'e5', 'Nf3', 'Nc6', 'Bb5', 'a6', 'Ba4', 'Nf6'], $game->history(), 'history SANs');
    expectSame('r1bqkb1r/1ppp1ppp/p1n2n2/4p3/B3P3/5N2/PPPP1PPP/RNBQK2R w KQkq - 2 5', $game->fen(), 'FEN after Ruy Lopez');
});

test('SAN: file disambiguation (Nab3 / Ncb3)', function (): void {
    $position = Position::fromFen('k7/8/8/8/8/8/8/N1N4K w - - 0 1');
    $sans = $position->legalMoves();
    $names = array_map(static fn ($m) => $position->toSan($m), $sans);
    expectTrue(in_array('Nab3', $names, true), 'Nab3 missing: ' . implode(',', $names));
    expectTrue(in_array('Ncb3', $names, true), 'Ncb3 missing: ' . implode(',', $names));
});

test('SAN: rank disambiguation (N1c2 / N3c2)', function (): void {
    $position = Position::fromFen('8/8/8/4k3/8/N7/8/N5K1 w - - 0 1');
    $names = array_map(static fn ($m) => $position->toSan($m), $position->legalMoves());
    expectTrue(in_array('N1c2', $names, true), 'N1c2 missing: ' . implode(',', $names));
    expectTrue(in_array('N3c2', $names, true), 'N3c2 missing: ' . implode(',', $names));
});

test('SAN: full-square disambiguation (Ne4c5)', function (): void {
    // knights on e4/e6/a4 all reach c5: e4 shares file with e6 and rank with a4,
    // so neither file nor rank alone disambiguates it -> Ne4c5
    $position = Position::fromFen('7k/8/4N3/8/N3N3/8/8/K7 w - - 0 1');
    $names = array_map(static fn ($m) => $position->toSan($m), $position->legalMoves());
    expectTrue(in_array('Ne4c5', $names, true), 'Ne4c5 missing: ' . implode(',', $names));
    expectTrue(in_array('N6c5', $names, true), 'N6c5 missing: ' . implode(',', $names));
    expectTrue(in_array('Nac5', $names, true), 'Nac5 missing: ' . implode(',', $names));
});

test('SAN: check and mate suffixes', function (): void {
    $game = Game::start()->applyMoves(['f3', 'e5', 'g4', 'Qh4']);
    expectSame('Qh4#', $game->history()[3], 'fool\'s mate SAN');
    expectTrue($game->isCheckmate(), 'fool\'s mate should be checkmate');
    $result = $game->result();
    expectTrue($result instanceof GameResult, 'result should exist');
    expectSame(GameResult::CHECKMATE, $result->outcome, 'outcome');
    expectSame('b', $result->winner, 'winner');
    expectSame('0-1', $result->pgnResult(), 'pgn result');
});

// --- SAN / UCI parsing -----------------------------------------------------

test('parse: SAN, decorated SAN, move numbers, UCI, castling variants', function (): void {
    $game = Game::start();

    expectSame('e4', $game->play('e4')->history()[0], 'plain SAN');
    expectSame('e4', $game->play('1.e4')->history()[0], 'SAN with move number');

    $position = Position::initial();
    expectSame('e2e4', $position->parseMove('E2E4')?->uci(), 'uppercase UCI');

    $promoFen = '8/4P3/8/8/7k/8/8/1K6 w - - 0 1';
    expectSame(
        'e7e8q',
        Position::fromFen($promoFen)->parseMove('e7e8q')?->uci(),
        'UCI promotion',
    );
    $promo = Position::fromFen($promoFen);
    expectSame(
        'e8=Q',
        $promo->toSan($promo->parseMove('e8=Q') ?? throw new RuntimeException('e8=Q not parsed')),
        'promotion SAN',
    );

    // castling forms
    $kiwi = Game::fromFen('r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1');
    expectSame('e1g1', $kiwi->play('O-O')->historyUci()[0], 'O-O');
    expectSame('e1g1', $kiwi->play('0-0')->historyUci()[0], '0-0 accepted');
    expectSame('e1c1', $kiwi->play('O-O-O')->historyUci()[0], 'O-O-O');

    // decorated SAN
    $mateGame = Game::start()->applyMoves(['f3', 'e5', 'g4']);
    expectSame('Qh4#', $mateGame->play('Qh4+#')->history()[3], 'decorated mate SAN');

    // illegal moves must not parse
    expectSame(null, Position::initial()->parseMove('Ke2'), 'Ke2 blocked by own pawn');
    expectSame(null, Position::initial()->parseMove('e5'), 'e5 not a legal pawn push');
    expectSame(null, Position::initial()->parseMove('Zzz'), 'garbage input');
    expectSame(null, Position::fromFen('7k/4N3/8/N3N3/8/8/8/K7 w - - 0 1')->parseMove('Nbc5'), 'disambiguation for empty file');
});

// --- castling --------------------------------------------------------------

test('castling: both sides available in Kiwipete', function (): void {
    $sans = Game::fromFen('r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1')->sanMoves();
    expectTrue(in_array('O-O', $sans, true), 'O-O should be legal');
    expectTrue(in_array('O-O-O', $sans, true), 'O-O-O should be legal');
});

test('castling: transit square attacked blocks O-O only', function (): void {
    $game = Game::fromFen('r3k2r/8/8/8/8/8/6q1/R3K2R w KQkq - 0 1');
    $sans = $game->sanMoves();
    expectTrue(!in_array('O-O', $sans, true), 'O-O must be illegal (f1 attacked by Qg2)');
    expectTrue(in_array('O-O-O', $sans, true), 'O-O-O should be legal');
});

test('castling: through-check blocked (king square attacked)', function (): void {
    // black rook on e8 pins the e-file: white king cannot castle either way
    $game = Game::fromFen('4r2k/8/8/8/8/8/8/4K2R w KQ - 0 1');
    $sans = $game->sanMoves();
    expectTrue(!in_array('O-O', $sans, true), 'O-O illegal: king in check from Re8');
    expectTrue(!in_array('O-O-O', $sans, true), 'O-O-O illegal: king in check from Re8');
});

test('castling: rights dropped after king moves', function (): void {
    $game = Game::start()->applyMoves(['e4', 'e5', 'Ke2', 'Ke7']);
    $rights = explode(' ', $game->fen())[2];
    expectSame('-', $rights, 'both kings moved: castling field must be "-"');
});

test('castling: rook move drops only its own side rights', function (): void {
    $game = Game::fromFen('r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1')->applyMoves(['Rh3', 'Rh6']);
    $rights = explode(' ', $game->fen())[2];
    expectSame('Qq', $rights, 'h-rooks moved, queenside rights remain: ' . $rights);
});

test('castling: capturing the rook drops the opponent right', function (): void {
    // Rxa8+ wins the a8 rook and with it black's queenside right; the
    // a1 rook leaving home simultaneously drops white's queenside right.
    $game = Game::fromFen('r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1')->play('Rxa8');
    $rights = explode(' ', $game->fen())[2];
    expectSame('Kk', $rights, 'after Rxa8 only K and k remain: ' . $rights);
});

// --- en passant ------------------------------------------------------------

test('en passant: capture is generated, applied and removes the pawn', function (): void {
    $game = Game::start()->applyMoves(['e4', 'e6', 'e5', 'd5']);
    expectTrue(str_contains($game->fen(), ' d6 0 3'), 'ep square expected: ' . $game->fen());
    expectTrue(in_array('exd6', $game->sanMoves(), true), 'exd6 must be legal');
    $after = $game->play('exd6');
    expectSame(
        'rnbqkbnr/ppp2ppp/3Pp3/8/8/8/PPPP1PPP/RNBQKBNR b KQkq - 0 3',
        $after->fen(),
        'FEN after exd6',
    );
});

test('en passant: pinned capturer makes the capture illegal and hides ep from FEN', function (): void {
    // black pawn d4 is pinned by Rd8 against the black king on d1: capturing
    // e.p. would expose the king, so the capture is illegal and, like chess.js,
    // we print no ep square even though a pawn could capture.
    $position = Position::fromFen('3R4/8/8/8/3pP3/8/8/K2k4 b - e3 0 1');
    $sans = array_map(static fn ($m) => $position->toSan($m), $position->legalMoves());
    expectTrue(!in_array('dxe3', $sans, true), 'dxe3 must be illegal: ' . implode(',', $sans));
    expectTrue(in_array('d3', $sans, true), 'd3 push should be legal: ' . implode(',', $sans));
    expectTrue(str_ends_with($position->fen(), ' - 0 1'), 'ep field must be "-": ' . $position->fen());
    expectSame(null, $position->parseMove('dxe3'), 'pinned ep capture must not parse');
});

// --- promotion -------------------------------------------------------------

test('promotion: four legal continuations and SAN/UCI forms', function (): void {
    $game = Game::fromFen('8/4P3/8/8/7k/8/8/1K6 w - - 0 1');
    $sans = $game->sanMoves();
    sort($sans);
    expectSame(['Ka1', 'Ka2', 'Kb2', 'Kc1', 'Kc2', 'e8=B', 'e8=N', 'e8=Q', 'e8=R'], $sans, 'promotion SANs');

    // promote with check: the knight on e8 attacks the black king on f6
    $checking = Game::fromFen('8/4P3/5k2/8/8/8/8/1K6 w - - 0 1')->play('e8=N');
    expectSame('4N3/8/5k2/8/8/8/8/1K6 b - - 0 1', $checking->fen(), 'knight promoted with check');
    expectTrue($checking->isCheck(), 'black must be in check after e8=N+');

    $uci = Game::fromFen('8/4P3/8/8/7k/8/8/1K6 w - - 0 1')->play('e7e8q');
    expectTrue(str_contains($uci->fen(), '4Q3'), 'queen on e8 via UCI: ' . $uci->fen());
});

test('promotion: underpromotion capture', function (): void {
    $game = Game::fromFen('3nk3/4P3/8/8/8/8/8/4K3 w - - 0 1');
    expectTrue(in_array('exd8=N', $game->sanMoves(), true), 'exd8=N must be legal');
    $after = $game->play('exd8=N');
    expectSame('3Nk3/8/8/8/8/8/8/4K3 b - - 0 1', $after->fen(), 'promoted knight on d8');
});

// --- stalemate / checkmate positions --------------------------------------

test('stalemate: classic king-and-queen position', function (): void {
    $game = Game::fromFen('7k/5Q2/6K1/8/8/8/8/8 b - - 0 1');
    expectTrue($game->isStalemate(), 'should be stalemate');
    expectTrue(!$game->isCheck(), 'stalemate is not check');
    expectTrue($game->isGameOver(), 'game over');
    $result = $game->result();
    expectSame(GameResult::STALEMATE, $result?->outcome, 'stalemate outcome');
    expectSame(null, $result?->winner, 'stalemate has no winner');
    expectSame('1/2-1/2', $result?->pgnResult(), 'pgn draw');
});

test("mate detection: fool's mate", function (): void {
    $game = Game::start()->applyMoves(['f3', 'e5', 'g4', 'Qh4#']);
    expectTrue($game->isCheckmate(), 'fool\'s mate');
    expectSame('b', $game->result()?->winner, 'black wins');
});

test("mate detection: scholar's mate", function (): void {
    $game = Game::start()->applyMoves(['e4', 'e5', 'Bc4', 'Nc6', 'Qh5', 'Nf6', 'Qxf7']);
    expectSame('Qxf7#', $game->history()[6], 'scholar\'s mate SAN');
    expectTrue($game->isCheckmate(), 'scholar\'s mate');
    expectSame('w', $game->result()?->winner, 'white wins');
});

test('mate detection: smothered mate position', function (): void {
    $game = Game::fromFen('6rk/6pp/3N4/8/8/8/8/6K1 w - - 0 1');
    expectTrue(in_array('Nf7#', $game->sanMoves(), true), 'Nf7# must be legal');
    $game = $game->play('Nf7#');
    expectTrue($game->isCheckmate(), 'smothered mate');
    expectSame('w', $game->result()?->winner, 'white wins');
    expectSame('Nf7#', $game->history()[0], 'history');
});

// --- insufficient material -------------------------------------------------

test('insufficient material: K vs K, K+minor vs K', function (): void {
    expectTrue(Position::fromFen('8/8/8/8/8/8/8/K6k w - - 0 1')->isInsufficientMaterial(), 'K vs K');
    expectTrue(Position::fromFen('8/8/8/8/8/8/8/K5Bk w - - 0 1')->isInsufficientMaterial(), 'KB vs K');
    expectTrue(Position::fromFen('8/8/8/8/8/8/8/K5Nk w - - 0 1')->isInsufficientMaterial(), 'KN vs K');
    expectTrue(!Position::fromFen('8/8/8/8/8/4P3/8/1K5k w - - 0 1')->isInsufficientMaterial(), 'KP vs K is not insufficient');
    expectTrue(!Position::fromFen('8/8/8/8/8/8/4r3/K6k w - - 0 1')->isInsufficientMaterial(), 'KR vs K is not insufficient');
});

test('insufficient material: bishops on one colour vs opposite colours', function (): void {
    // Ba8 and Bb1 stand on the same square colour -> draw
    expectTrue(
        Position::fromFen('B7/8/5k2/8/8/8/8/1b4K1 w - - 0 1')->isInsufficientMaterial(),
        'same-colour bishops should be insufficient',
    );
    // Ba8 and Bc1 stand on opposite colours -> not an automatic draw
    expectTrue(
        !Position::fromFen('B7/8/5k2/8/8/8/8/2b3K1 w - - 0 1')->isInsufficientMaterial(),
        'opposite-colour bishops must not be insufficient',
    );
});

test('insufficient material: game result is a draw', function (): void {
    $game = Game::fromFen('8/8/8/8/8/8/8/K6k w - - 0 1');
    expectTrue($game->isGameOver(), 'game over');
    expectSame(GameResult::INSUFFICIENT, $game->result()?->outcome, 'outcome');
    expectSame('1/2-1/2', $game->result()?->pgnResult(), 'pgn');
});

// --- threefold repetition --------------------------------------------------

test('threefold: repetition of the start position', function (): void {
    $moves = ['Nf3', 'Nf6', 'Ng1', 'Ng8', 'Nf3', 'Nf6', 'Ng1', 'Ng8'];
    $game = Game::start();
    foreach ($moves as $i => $san) {
        $game = $game->play($san);
        $isThreefold = $game->isThreefoldRepetition();
        if ($i < 7) {
            expectSame(false, $isThreefold, "must not be threefold after ply " . ($i + 1));
        } else {
            expectSame(true, $isThreefold, 'must be threefold after ply 8');
        }
    }
    expectSame(
        explode(' ', Position::START_FEN)[0],
        explode(' ', $game->fen())[0],
        'board back to start placement',
    );
    expectSame(GameResult::REPETITION, $game->result()?->outcome, 'repetition outcome');
    expectTrue($game->isDraw(), 'isDraw');
});

test('threefold: FEN-loaded position counts occurrences from load', function (): void {
    // counting starts at the loaded position: two further repeats claim it
    $game = Game::fromFen(Position::START_FEN);
    foreach (['Nf3', 'Nf6', 'Ng1', 'Ng8'] as $san) {
        $game = $game->play($san);
    }
    expectSame(false, $game->isThreefoldRepetition(), 'only two occurrences so far');
    $game = $game->play('Nf3')->play('Nf6')->play('Ng1')->play('Ng8');
    expectSame(true, $game->isThreefoldRepetition(), 'third occurrence');
});

// --- 50-move rule ----------------------------------------------------------

test('50-move: draw declared at halfmove clock 100', function (): void {
    $game = Game::fromFen('7k/8/8/8/8/8/8/K3R3 w - - 99 1');
    expectSame(false, $game->isDrawByFiftyMoves(), '99 halfmoves is not yet a draw');
    $game = $game->play('Re2');
    expectSame(true, $game->isDrawByFiftyMoves(), 'clock reached 100');
    expectSame(GameResult::FIFTY_MOVE, $game->result()?->outcome, '50-move outcome');
    expectSame('1/2-1/2', $game->result()?->pgnResult(), 'pgn');
});

test('50-move: clock resets on pawn moves and captures', function (): void {
    expectSame(1, Game::start()->play('Nf3')->position()->halfmoveClock(), 'Nf3 increments');
    expectSame(0, Game::start()->play('e4')->position()->halfmoveClock(), 'e4 resets');
    $game = Game::start()->applyMoves(['e4', 'd5', 'exd5']);
    expectSame(0, $game->position()->halfmoveClock(), 'capture resets');
    expectSame(1, Game::start()->applyMoves(['e4', 'd5', 'Nf3'])->position()->halfmoveClock(), 'after capture+move');
});

// --- GameResult ------------------------------------------------------------

test('GameResult: resign / time-forfeit / abort factories', function (): void {
    $resign = GameResult::resign('b');
    expectSame('w', $resign->winner, 'black resigns, white wins');
    expectSame('1-0', $resign->pgnResult(), 'pgn');
    expectSame(GameResult::RESIGN, $resign->outcome, 'outcome');

    $time = GameResult::timeForfeit('w');
    expectSame('b', $time->winner, 'white flagged, black wins');
    expectSame('0-1', $time->pgnResult(), 'pgn');

    $abort = GameResult::abort();
    expectSame(null, $abort->winner, 'abort has no winner');
    expectTrue($abort->isDraw(), 'abort is a draw');
    expectSame('1/2-1/2', $abort->pgnResult(), 'pgn');

    expectSame(false, GameResult::stalemate()->isDecisive(), 'stalemate not decisive');
    expectSame(true, GameResult::checkmate('w')->isDecisive(), 'checkmate decisive');
});

// --- move-list validation --------------------------------------------------

test('validateMoveList: returns first illegal move index', function (): void {
    $game = Game::start();
    expectSame(null, $game->validateMoveList(['e4', 'e5', 'Nf3', 'Nc6']), 'legal list');
    // after 1.e4 e5 2.Qh5 black's queen is blocked by its own d7 pawn
    expectSame(3, $game->validateMoveList(['e4', 'e5', 'Qh5', 'Qd5']), 'illegal at index 3');
    expectSame(0, $game->validateMoveList(['Ke2']), 'illegal at index 0');
    expectSame(1, $game->validateMoveList(['e4', 'Ke2']), 'illegal at index 1 (own pawn)');
    expectSame(null, $game->validateMoveList(['e2e4', 'e7e5', 'g1f3']), 'UCI list');
    expectSame(2, $game->validateMoveList(['e2e4', 'e7e5', 'f1c4x']), 'bad UCI at index 2');
    // validation must not mutate the game
    expectSame(0, $game->ply(), 'game unchanged after validate');
});

test('applyMoves: throws IllegalMoveException carrying the index and FEN', function (): void {
    $game = Game::start();
    try {
        $game->applyMoves(['e4', 'e5', 'Qh5', 'Qd5']);
        throw new RuntimeException('expected IllegalMoveException');
    } catch (IllegalMoveException $e) {
        expectSame(3, $e->index, 'exception index');
        expectSame('Qd5', $e->move, 'offending move');
        expectTrue(str_contains($e->fenBefore, 'rnbqkbnr/pppp1ppp'), 'fen before: ' . $e->fenBefore);
        expectTrue(str_contains($e->getMessage(), 'index 3'), 'message: ' . $e->getMessage());
    }
    expectSame(0, $game->ply(), 'applyMoves is all-or-nothing for the receiver');
});

test('applyMoves: returns a new game (immutability)', function (): void {
    $game = Game::start();
    $after = $game->applyMoves(['e4', 'e5']);
    expectSame(0, $game->ply(), 'original untouched');
    expectSame(2, $after->ply(), 'new instance advanced');
    expectSame(['e4', 'e5'], $after->history(), 'history');
    expectSame(['e2e4', 'e7e5'], $after->historyUci(), 'uci history');
});

// ---------------------------------------------------------------------------
// 2. perft
// ---------------------------------------------------------------------------

echo "\n== Perft ==\n";
$perftRows = runPerft();
$perftOk = printPerftTable($perftRows);

// ---------------------------------------------------------------------------
// 3. random cross-check (optional - requires generated data from Node)
// ---------------------------------------------------------------------------

echo "\n== Random cross-check (chess.js reference) ==\n";
$crossResult = runRandomCrossCheck(randomCrossCheckDefaultPath());
$crossOk = true;
if ($crossResult['status'] === 'missing') {
    echo "  SKIP  data file not found: " . randomCrossCheckDefaultPath() . "\n";
    echo "        generate with: node backend/tests/helpers/generate-random-games.mjs\n";
} else {
    foreach ($crossResult['failures'] as $failure) {
        echo "  FAIL  {$failure}\n";
    }
    $crossOk = $crossResult['status'] === 'ok';
    printf(
        "  %s  %d games, %d plies, %d assertions (%s)\n",
        $crossOk ? 'PASS' : 'FAIL',
        $crossResult['games'],
        $crossResult['plies'],
        $crossResult['checks'],
        $crossResult['status'] === 'ok' ? 'FENs, SAN lists and flags identical after every move' : 'mismatches above',
    );
    if ($crossOk) {
        $GLOBALS['chess_suite_pass']++;
    } else {
        $GLOBALS['chess_suite_fail']++;
    }
}

// ---------------------------------------------------------------------------
// summary
// ---------------------------------------------------------------------------

echo "\n== Summary ==\n";
$unitPass = $GLOBALS['chess_suite_pass'];
$unitFail = $GLOBALS['chess_suite_fail'];
$perftPass = count(array_filter($perftRows, static fn (array $row) => $row['pass']));
$allOk = $unitFail === 0 && $perftOk && $crossOk;

printf("Units:   %d passed, %d failed\n", $unitPass, $unitFail);
printf("Perft:   %d/%d passed\n", $perftPass, count($perftRows));
printf("Random:  %s\n", $crossResult['status'] === 'ok' ? '1 passed' : ($crossResult['status'] === 'missing' ? 'skipped (no data file)' : '1 failed'));
foreach ($GLOBALS['chess_suite_failures'] as $failure) {
    echo "  FAIL  {$failure}\n";
}
echo $allOk ? "\nALL TESTS PASSED\n" : "\nTESTS FAILED\n";
exit($allOk ? 0 : 1);
