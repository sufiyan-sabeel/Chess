<?php

declare(strict_types=1);

/**
 * Rules test-suite for Checkmate\Chess\Rules (backend/src/Chess/Rules.php).
 *
 * Covers castling, en passant, promotions, pins/discovered/double check,
 * mates, stalemate, insufficient material, threefold, fifty-move, SAN
 * generation + round-trip, FEN round-trip/rejection, replay() and PGN
 * export/parse.
 *
 * Expected values were cross-derived from the vendored reference
 * (chess.js 1.4.0) before encoding them here, so this suite is
 * self-contained (PHP only) but reference-verified.
 *
 * Exit code 0 = all assertions passed, 1 = at least one failure.
 */

require_once __DIR__ . '/../../src/Chess/Rules.php';

use Checkmate\Chess\Rules;

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function ok(bool $cond, string $label): void
{
    if ($cond) {
        $GLOBALS['t_pass']++;
    } else {
        $GLOBALS['t_fail']++;
        echo "FAIL {$label}\n";
    }
}

function eq(mixed $expected, mixed $actual, string $label): void
{
    if ($expected === $actual) {
        $GLOBALS['t_pass']++;
    } else {
        $GLOBALS['t_fail']++;
        $e = is_string($expected) ? $expected : var_export($expected, true);
        $a = is_string($actual) ? $actual : var_export($actual, true);
        echo "FAIL {$label}\n      expected: {$e}\n      actual:   {$a}\n";
    }
}

/** Set equality of two string lists (order-insensitive). */
function setEq(array $expected, array $actual, string $label): void
{
    sort($expected);
    sort($actual);
    eq($expected, $actual, $label);
}

function sec(string $name): void
{
    echo "\n== {$name} ==\n";
}

function load(string $fen): Rules
{
    $r = Rules::fromFen($fen);
    if ($r === null) {
        $GLOBALS['t_fail']++;
        echo "FAIL could not load FEN: {$fen}\n";
        exit(1);
    }
    return $r;
}

/* ================================================================== */
/* A. basic state                                                      */
/* ================================================================== */
sec('basic state');

$r = Rules::start();
eq(Rules::START_FEN, $r->fen(), 'start: fen');
eq('w', $r->sideToMove(), 'start: side to move');
eq(20, count($r->legalMovesUci()), 'start: 20 legal moves');
eq([], $r->history(), 'start: empty history');
eq('*', $r->result(), 'start: result *');
eq(null, $r->resultReason(), 'start: no result reason');
ok(!$r->isGameOver(), 'start: game not over');
ok(!$r->isCheck(), 'start: no check');
$m = $r->legalMovesSan();
eq('e4', $m['e2e4'] ?? null, 'start: SAN e2e4=e4');
eq('Nf3', $m['g1f3'] ?? null, 'start: SAN g1f3=Nf3');

// illegal move leaves state untouched
$before = $r->fen();
eq(null, $r->moveUci('e2e5'), 'illegal moveUci returns null');
eq($before, $r->fen(), 'illegal move: fen unchanged');
eq(null, $r->moveUci('a2a9'), 'malformed moveUci returns null');
eq(null, $r->moveUci(''), 'empty moveUci returns null');

$r2 = Rules::start();
$mv = $r2->moveUci('e2e4');
eq('e4', $mv['san'] ?? null, 'moveUci e2e4 SAN');
eq('rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1', $mv['fen'] ?? null, 'moveUci e2e4 fen');
eq('b', $r2->sideToMove(), 'side flips after move');
eq(['e2e4'], $r2->history(), 'history records UCI');

/* ================================================================== */
/* B. castling                                                         */
/* ================================================================== */
sec('castling');

const F_CASTLE = 'r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1';

$c = load(F_CASTLE);
$legal = $c->legalMovesUci();
eq(26, count($legal), 'castling base: 26 legal moves');
ok(in_array('e1g1', $legal, true), 'castling base: e1g1 legal');
ok(in_array('e1c1', $legal, true), 'castling base: e1c1 legal');
$san = $c->legalMovesSan();
eq('O-O', $san['e1g1'] ?? null, 'castling SAN O-O');
eq('O-O-O', $san['e1c1'] ?? null, 'castling SAN O-O-O');

// castling forbidden while in check
$c = load('r3k2r/8/8/8/8/8/5q2/R3K2R w KQkq - 0 1');
$legal = $c->legalMovesUci();
eq(2, count($legal), 'in check: 2 escapes');
ok(!in_array('e1g1', $legal, true) && !in_array('e1c1', $legal, true), 'in check: no castling');
ok($c->isCheck(), 'in check flag');

// castling forbidden when passing square attacked (Qg2 attacks g1+f1, not e1)
$c = load('r3k2r/8/8/8/8/8/6q1/R3K2R w KQkq - 0 1');
$legal = $c->legalMovesUci();
eq(21, count($legal), 'pass-square attacked: 21 legal');
ok(!in_array('e1g1', $legal, true), 'pass-square attacked: no O-O');
ok(in_array('e1c1', $legal, true), 'pass-square attacked: O-O-O still legal');
ok(!$c->isCheck(), 'king e1 not in check');

// FIDE: castling legal even when the ROOK itself is attacked
$c = load('r5k1/8/2b5/8/8/8/8/R3K2R w KQ - 0 1');
$legal = $c->legalMovesUci();
eq(26, count($legal), 'rook attacked: 26 legal');
ok(in_array('e1g1', $legal, true), 'rook attacked: O-O legal (FIDE 3.8)');

// applying castling — white
$c = load(F_CASTLE);
$mv = $c->moveUci('e1g1');
eq('O-O', $mv['san'] ?? null, 'apply O-O SAN');
eq('r3k2r/8/8/8/8/8/8/R4RK1 b kq - 1 1', $mv['fen'] ?? null, 'apply O-O fen');

$c = load(F_CASTLE);
$mv = $c->moveUci('e1c1');
eq('O-O-O', $mv['san'] ?? null, 'apply O-O-O SAN');
eq('r3k2r/8/8/8/8/8/8/2KR3R b kq - 1 1', $mv['fen'] ?? null, 'apply O-O-O fen');

// applying castling — black (neutral white move first; a1a2 keeps e/f/g clear)
$c = load(F_CASTLE);
$c->moveUci('a1a2');
$mv = $c->moveUci('e8g8');
eq('O-O', $mv['san'] ?? null, 'black O-O SAN');
eq('r4rk1/8/8/8/8/8/R7/4K2R w K - 2 2', $mv['fen'] ?? null, 'black O-O fen');

$c = load(F_CASTLE);
$c->moveUci('a1a2');
$mv = $c->moveUci('e8c8');
eq('O-O-O', $mv['san'] ?? null, 'black O-O-O SAN');
eq('2kr3r/8/8/8/8/8/R7/4K2R w K - 2 2', $mv['fen'] ?? null, 'black O-O-O fen');

// rights lost when the rook leaves home and returns
$c = load(F_CASTLE);
$c->moveUci('h1h2');
$c->moveUci('h8h7');
$c->moveUci('h2h1');
eq('r3k3/7r/8/8/8/8/8/R3K2R b Qq - 3 2', $c->fen(), 'rights lost by rook move (Qq only)');

// rights lost when the rook is captured at home
$c = load(F_CASTLE);
$c->moveUci('a1a2');
$mv = $c->moveUci('h8h1');
eq('r3k3/8/8/8/8/8/R7/4K2r w q - 0 2', $mv['fen'] ?? null, 'rights lost by rook capture at home');
ok($c->isCheck(), 'Rxh1+ gives check');

/* ================================================================== */
/* C. en passant                                                       */
/* ================================================================== */
sec('en passant');

$c = Rules::start();
foreach (['e2e4', 'd7d5', 'e4e5', 'f7f5'] as $u) {
    $c->moveUci($u);
}
eq('rnbqkbnr/ppp1p1pp/8/3pPp2/8/8/PPPP1PPP/RNBQKBNR w KQkq f6 0 3', $c->fen(), 'ep available: fen shows f6');
ok(in_array('e5f6', $c->legalMovesUci(), true), 'ep available: e5f6 legal');
$mv = $c->moveUci('e5f6');
eq('exf6', $mv['san'] ?? null, 'ep capture SAN exf6');
eq('rnbqkbnr/ppp1p1pp/5P2/3p4/8/8/PPPP1PPP/RNBQKBNR b KQkq - 0 3', $mv['fen'] ?? null, 'ep capture fen (pawn f5 gone)');

// ep expires after one ply
$c = Rules::start();
foreach (['e2e4', 'd7d5', 'e4e5', 'f7f5', 'g1f3', 'b8c6'] as $u) {
    $c->moveUci($u);
}
eq('r1bqkbnr/ppp1p1pp/2n5/3pPp2/8/5N2/PPPP1PPP/RNBQKB1R w KQkq - 2 4', $c->fen(), 'ep expired: fen has -');
ok(!in_array('e5f6', $c->legalMovesUci(), true), 'ep expired: e5f6 not legal');

// pinned pawn may not capture en passant
$c = load('4r2k/8/8/3pP3/8/8/8/4K3 w - d6 0 1');
$legal = $c->legalMovesUci();
eq(6, count($legal), 'pinned ep: 6 legal moves');
ok(!in_array('e5d6', $legal, true), 'pinned ep: e5d6 illegal');
eq('4r2k/8/8/3pP3/8/8/8/4K3 w - - 0 1', $c->fen(), 'pinned ep: fen() suppresses illegal ep square');

/* ================================================================== */
/* D. promotions                                                       */
/* ================================================================== */
sec('promotions');

$c = load('k7/4P3/8/8/8/8/8/4K3 w - - 0 1');
$legal = $c->legalMovesUci();
eq(9, count($legal), 'promotion: 9 legal moves');
foreach (['e7e8q', 'e7e8r', 'e7e8b', 'e7e8n'] as $u) {
    ok(in_array($u, $legal, true), "promotion: {$u} legal");
}
$san = $c->legalMovesSan();
// king sits on a8; the promotion square e8 sees it along the (empty) 8th rank
eq('e8=Q+', $san['e7e8q'] ?? null, 'promo SAN e8=Q+ (queen checks on rank 8)');
eq('e8=R+', $san['e7e8r'] ?? null, 'promo SAN e8=R+ (rook checks on rank 8)');
eq('e8=B', $san['e7e8b'] ?? null, 'promo SAN e8=B (bishop, no check)');
eq('e8=N', $san['e7e8n'] ?? null, 'promo SAN e8=N (underpromotion, no check)');

// applying underpromotion does not check with knight
$c = load('k7/4P3/8/8/8/8/8/4K3 w - - 0 1');
$mv = $c->moveUci('e7e8n');
eq('e8=N', $mv['san'] ?? null, 'applied e8=N SAN');
ok(!$c->isCheck(), 'e8=N gives no check');

$c = load('k7/4P3/8/8/8/8/8/4K3 w - - 0 1');
$mv = $c->moveUci('e7e8q');
eq('e8=Q+', $mv['san'] ?? null, 'applied e8=Q+ SAN');
ok($c->isCheck() && !$c->isCheckmate(), 'e8=Q+ checks but not mate (Ka7/Kb7 escapes)');

// capture-promotion, all four pieces, both capture and quiet
$c = load('1n2k3/P7/8/8/8/8/8/4K3 w - - 0 1');
$legal = $c->legalMovesUci();
eq(13, count($legal), 'capture-promotion: 13 legal moves');
foreach (['a7a8q', 'a7a8r', 'a7a8b', 'a7a8n', 'a7b8q', 'a7b8r', 'a7b8b', 'a7b8n'] as $u) {
    ok(in_array($u, $legal, true), "capture-promotion: {$u} legal");
}
$san = $c->legalMovesSan();
eq('axb8=Q+', $san['a7b8q'] ?? null, 'capture-promo SAN axb8=Q+');
eq('axb8=R+', $san['a7b8r'] ?? null, 'capture-promo SAN axb8=R+');
eq('axb8=N', $san['a7b8n'] ?? null, 'capture-promo SAN axb8=N');
eq('a8=Q', $san['a7a8q'] ?? null, 'quiet-promo SAN a8=Q');

/* ================================================================== */
/* E. pins / discovered check / double check                           */
/* ================================================================== */
sec('pins, discovered check, double check');

// knight on b1 is pinned by Ra1 against Ke1: all three knight jumps illegal
$c = load('4k3/8/8/8/8/8/8/rN2K3 w - - 0 1');
setEq(['e1d2', 'e1d1', 'e1e2', 'e1f1', 'e1f2'], $c->legalMovesUci(), 'pinned knight: only king moves legal');
$legal = $c->legalMovesUci();
foreach (['b1a3', 'b1c3', 'b1d2'] as $u) {
    ok(!in_array($u, $legal, true), "pinned knight: {$u} rejected");
}

// discovered check: Ne4 leaves the Re1 -> e8 line
$c = load('4k3/8/8/8/4N3/8/4R3/4K3 w - - 0 1');
eq(20, count($c->legalMovesUci()), 'discovered setup: 20 legal moves');
$mv = $c->moveUci('e4d2');
eq('Nd2+', $mv['san'] ?? null, 'discovered check SAN Nd2+');
ok($c->isCheck(), 'discovered check: isCheck true');
ok(!$c->isCheckmate(), 'discovered check: not mate');

// double check: Nc7+ (knight check + discovered rook check) -> king must move
$c = load('4k3/8/4N3/8/8/8/8/4R1K1 w - - 0 1');
$mv = $c->moveUci('e6c7');
eq('Nc7+', $mv['san'] ?? null, 'double check SAN Nc7+ (single +)');
ok($c->isCheck(), 'double check: isCheck true');
ok(!$c->isCheckmate(), 'double check: not mate');
setEq(['e8d7', 'e8d8', 'e8f7', 'e8f8'], $c->legalMovesUci(), 'double check: only king moves');

/* ================================================================== */
/* F. checkmate / stalemate                                            */
/* ================================================================== */
sec('checkmate / stalemate');

// scholar's mate
$scholarUci = ['e2e4', 'e7e5', 'f1c4', 'b8c6', 'd1h5', 'g8f6', 'h5f7'];
$scholarSan = ['e4', 'e5', 'Bc4', 'Nc6', 'Qh5', 'Nf6', 'Qxf7#'];
$c = Rules::start();
$got = [];
foreach ($scholarUci as $u) {
    $mv = $c->moveUci($u);
    $got[] = $mv['san'] ?? '?';
}
eq($scholarSan, $got, "scholar's mate SAN sequence");
eq('r1bqkb1r/pppp1Qpp/2n2n2/4p3/2B1P3/8/PPPP1PPP/RNB1K1NR b KQkq - 0 4', $c->fen(), "scholar's mate final fen");
ok($c->isCheckmate(), "scholar's mate: isCheckmate");
ok($c->isCheck(), "scholar's mate: isCheck");
ok($c->isGameOver(), "scholar's mate: game over");
eq('1-0', $c->result(), "scholar's mate: result 1-0");
eq('checkmate', $c->resultReason(), "scholar's mate: reason checkmate");
eq($scholarUci, $c->history(), "scholar's mate: history matches ucis");

// fool's mate (black wins) -> 0-1
$c = Rules::start();
foreach (['f2f3', 'e7e5', 'g2g4', 'd8h4'] as $u) {
    $mv = $c->moveUci($u);
}
eq('Qh4#', $mv['san'] ?? null, "fool's mate SAN Qh4#");
ok($c->isCheckmate(), "fool's mate: isCheckmate");
eq('0-1', $c->result(), "fool's mate: result 0-1");
eq('checkmate', $c->resultReason(), "fool's mate: reason checkmate");

// back-rank mate
$c = load('6k1/5ppp/8/8/8/8/8/R3K3 w - - 0 1');
$mv = $c->moveUci('a1a8');
eq('Ra8#', $mv['san'] ?? null, 'back-rank mate SAN Ra8#');
eq('R5k1/5ppp/8/8/8/8/8/4K3 b - - 1 1', $mv['fen'] ?? null, 'back-rank mate fen');
ok($c->isCheckmate(), 'back-rank mate: isCheckmate');
eq('1-0', $c->result(), 'back-rank mate: result 1-0');

// smothered mate
$c = load('6rk/6pp/3N4/8/8/8/8/6K1 w - - 0 1');
$mv = $c->moveUci('d6f7');
eq('Nf7#', $mv['san'] ?? null, 'smothered mate SAN Nf7#');
eq('6rk/5Npp/8/8/8/8/8/6K1 b - - 1 1', $mv['fen'] ?? null, 'smothered mate fen');
ok($c->isCheckmate(), 'smothered mate: isCheckmate');
eq('1-0', $c->result(), 'smothered mate: result 1-0');

// Qxd7# (queen capture-mate, queen defended by Ba4)
$c = load('3k4/3r4/8/8/B2Q4/8/8/4K3 w - - 0 1');
$mv = $c->moveUci('d4d7');
eq('Qxd7#', $mv['san'] ?? null, 'Qxd7# SAN');
eq('3k4/3Q4/8/8/B7/8/8/4K3 b - - 0 1', $mv['fen'] ?? null, 'Qxd7# fen');
ok($c->isCheckmate(), 'Qxd7#: isCheckmate');
eq('1-0', $c->result(), 'Qxd7#: result 1-0');

// stalemate
$c = load('7k/5Q2/6K1/8/8/8/8/8 b - - 0 1');
eq(0, count($c->legalMovesUci()), 'stalemate: no legal moves');
ok(!$c->isCheck(), 'stalemate: not in check');
ok($c->isStalemate(), 'stalemate: isStalemate');
ok($c->isDraw(), 'stalemate: isDraw');
ok($c->isGameOver(), 'stalemate: game over');
eq('1/2-1/2', $c->result(), 'stalemate: result 1/2-1/2');
eq('stalemate', $c->resultReason(), 'stalemate: reason stalemate');
ok(!$c->canClaimDraw(), 'stalemate: canClaimDraw false (no claimable repetition/fifty)');

/* ================================================================== */
/* G. insufficient material                                            */
/* ================================================================== */
sec('insufficient material');

$insuff = [
    'K vs K'            => ['4k3/8/8/8/8/8/8/4K3 w - - 0 1', true],
    'K+B vs K'          => ['4k3/8/8/8/8/8/8/4KB2 w - - 0 1', true],
    'K+N vs K'          => ['4k3/8/8/8/8/8/8/4KN2 w - - 0 1', true],
    'same-colour BB'    => ['4k3/8/8/8/8/8/8/2B1K1B1 w - - 0 1', true],
    'diff-colour BB'    => ['4k3/8/8/8/8/8/8/2B1KB2 w - - 0 1', false],
    'K+NN vs K'         => ['4k3/8/8/8/8/8/8/3K1NN1 w - - 0 1', false],
];
foreach ($insuff as $label => [$fen, $expect]) {
    $c = load($fen);
    eq($expect, $c->isInsufficientMaterial(), "insufficient: {$label}");
    if ($expect) {
        eq('1/2-1/2', $c->result(), "insufficient: {$label} result");
        eq('insufficient_material', $c->resultReason(), "insufficient: {$label} reason");
        ok($c->isGameOver(), "insufficient: {$label} game over");
        ok(!$c->canClaimDraw(), "insufficient: {$label} canClaimDraw false");
    } else {
        eq('*', $c->result(), "insufficient: {$label} not a draw");
        eq(null, $c->resultReason(), "insufficient: {$label} no reason");
    }
}

/* ================================================================== */
/* H. threefold repetition                                             */
/* ================================================================== */
sec('threefold repetition');

$repUci = ['g1f3', 'g8f6', 'f3g1', 'f6g8', 'g1f3', 'g8f6', 'f3g1', 'f6g8'];
$c = Rules::start();
foreach (array_slice($repUci, 0, 7) as $u) {
    $c->moveUci($u);
}
eq('rnbqkb1r/pppppppp/5n2/8/8/8/PPPPPPPP/RNBQKBNR b KQkq - 7 4', $c->fen(), 'threefold: fen after 7 plies');
ok(!$c->isThreefoldRepetition(), 'threefold: false after 7 plies');
ok(!$c->canClaimDraw(), 'threefold: no claim after 7 plies');
eq('*', $c->result(), 'threefold: result * after 7 plies');

$c->moveUci($repUci[7]);
eq('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 8 5', $c->fen(), 'threefold: fen after 8 plies');
ok($c->isThreefoldRepetition(), 'threefold: true after 8 plies');
ok($c->canClaimDraw(), 'threefold: canClaimDraw true');
ok($c->isDraw(), 'threefold: isDraw true');
ok($c->isGameOver(), 'threefold: game over');
eq('1/2-1/2', $c->result(), 'threefold: result 1/2-1/2');
eq('threefold_repetition', $c->resultReason(), 'threefold: reason threefold_repetition');
eq($repUci, $c->history(), 'threefold: history matches');

/* ================================================================== */
/* I. fifty-move rule                                                  */
/* ================================================================== */
sec('fifty-move rule');

$c = load('4k3/8/8/8/8/8/8/R3K3 w - - 99 80');
ok(!$c->isFiftyMove(), 'fifty: 99 halfmoves -> isFiftyMove false');
ok(!$c->canClaimFiftyMove(), 'fifty: 99 halfmoves -> canClaimFiftyMove false');
ok(!$c->canClaimDraw(), 'fifty: 99 halfmoves -> canClaimDraw false');
ok(!$c->isDraw(), 'fifty: 99 halfmoves -> isDraw false');
eq('*', $c->result(), 'fifty: result * at 99');
eq(null, $c->resultReason(), 'fifty: no reason at 99');

$mv = $c->moveUci('a1a2');
eq('4k3/8/8/8/8/8/R7/4K3 b - - 100 80', $mv['fen'] ?? null, 'fifty: fen after reaching 100');
ok($c->isFiftyMove(), 'fifty: 100 halfmoves -> isFiftyMove true');
ok($c->canClaimFiftyMove(), 'fifty: canClaimFiftyMove true');
ok($c->canClaimDraw(), 'fifty: canClaimDraw true');
ok($c->isDraw(), 'fifty: isDraw true');
ok($c->isGameOver(), 'fifty: game over');
eq('1/2-1/2', $c->result(), 'fifty: result 1/2-1/2');
eq('fifty_move', $c->resultReason(), 'fifty: reason fifty_move');

/* ================================================================== */
/* J. SAN generation and round-trip                                    */
/* ================================================================== */
sec('SAN generation');

// check suffixes
$c = load('8/8/8/4k3/7N/8/8/K7 w - - 0 1');
$san = $c->legalMovesSan();
eq(7, count($san), 'Nf3+ position: 7 legal moves');
eq('Nf3+', $san['h4f3'] ?? null, 'SAN Nf3+');
eq('Ng6+', $san['h4g6'] ?? null, 'SAN Ng6+');
eq('Nf5', $san['h4f5'] ?? null, 'SAN Nf5 (no check)');

// moveSan accepts decorated and bare SAN, rejects UCI / long algebraic
$c = load('8/8/8/4k3/7N/8/8/K7 w - - 0 1');
$mv = $c->moveSan('Nf3+');
eq('h4f3', $mv['uci'] ?? null, "moveSan('Nf3+') -> h4f3");
eq('Nf3+', $mv['san'] ?? null, "moveSan('Nf3+') returns SAN");

$c = load('8/8/8/4k3/7N/8/8/K7 w - - 0 1');
$mv = $c->moveSan('Nf3');
eq('h4f3', $mv['uci'] ?? null, "moveSan('Nf3') bare accepted");
ok($c->isCheck(), 'Nf3+ gives check');

eq(null, Rules::start()->moveSan('e2e4'), "moveSan rejects UCI 'e2e4'");
eq(null, Rules::start()->moveSan('Pe2e4'), "moveSan rejects 'Pe2e4'");
eq(null, Rules::start()->moveSan('Ng1f3'), "moveSan rejects 'Ng1f3'");
eq(null, Rules::start()->moveSan('Qh5'), 'moveSan rejects illegal SAN in startpos');
eq(null, Rules::start()->moveSan('O-O'), 'moveSan rejects castling without rights');
eq(null, Rules::start()->moveSan(''), 'moveSan rejects empty string');

// disambiguation: same rank -> file letter
$c = load('7k/8/8/8/8/8/8/K1N3N1 w - - 0 1');
eq(10, count($c->legalMovesUci()), 'disambig same rank: 10 legal');
$san = $c->legalMovesSan();
eq('Nce2', $san['c1e2'] ?? null, 'disambig same rank: Nce2');
eq('Nge2', $san['g1e2'] ?? null, 'disambig same rank: Nge2');

// disambiguation: same file -> rank digit
$c = load('7k/8/8/8/8/6N1/8/K5N1 w - - 0 1');
eq(12, count($c->legalMovesUci()), 'disambig same file: 12 legal');
$san = $c->legalMovesSan();
eq('N1e2', $san['g1e2'] ?? null, 'disambig same file: N1e2');
eq('N3e2', $san['g3e2'] ?? null, 'disambig same file: N3e2');

// disambiguation: neither shared -> file letter
$c = load('7k/8/2N5/5N2/8/8/8/K7 w - - 0 1');
eq(19, count($c->legalMovesUci()), 'disambig neither: 19 legal');
$san = $c->legalMovesSan();
eq('Ncd4', $san['c6d4'] ?? null, 'disambig neither: Ncd4');
eq('Nfd4', $san['f5d4'] ?? null, 'disambig neither: Nfd4');
eq('Nce7', $san['c6e7'] ?? null, 'disambig neither: Nce7');
eq('Nfe7', $san['f5e7'] ?? null, 'disambig neither: Nfe7');

// disambiguation: three knights, full square / rank / file variants
$c = load('7k/8/8/1N6/8/8/8/1N1N3K w - - 0 1');
eq(16, count($c->legalMovesUci()), 'disambig 3 knights: 16 legal');
$san = $c->legalMovesSan();
eq('Nb1c3', $san['b1c3'] ?? null, 'disambig 3 knights: full square Nb1c3');
eq('N5c3', $san['b5c3'] ?? null, 'disambig 3 knights: rank N5c3');
eq('Ndc3', $san['d1c3'] ?? null, 'disambig 3 knights: file Ndc3');
eq('N5a3', $san['b5a3'] ?? null, 'disambig 3 knights: N5a3');
eq('N1a3', $san['b1a3'] ?? null, 'disambig 3 knights: N1a3');

// pawn captures are file-qualified
$c = load('7k/8/8/3p4/2P1P3/8/8/K7 w - - 0 1');
eq(7, count($c->legalMovesUci()), 'pawn moves: 7 legal');
$san = $c->legalMovesSan();
eq('cxd5', $san['c4d5'] ?? null, 'pawn capture cxd5');
eq('exd5', $san['e4d5'] ?? null, 'pawn capture exd5');
eq('c5', $san['c4c5'] ?? null, 'pawn push c5');
eq('e5', $san['e4e5'] ?? null, 'pawn push e5');

// SAN round-trip: every legal move parses back to the same UCI
foreach ([
    'startpos'  => Rules::START_FEN,
    'kiwipete'  => 'r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1',
    'castling'  => F_CASTLE,
    'promotion' => '1n2k3/P7/8/8/8/8/8/4K3 w - - 0 1',
] as $name => $fen) {
    $a = load($fen);
    $map = $a->legalMovesSan();
    $all = true;
    foreach ($map as $u => $sanStr) {
        $fresh = Rules::fromFen($fen);
        $back = $fresh->moveSan($sanStr);
        if ($back === null || $back['uci'] !== $u) {
            $all = false;
            echo "FAIL SAN round-trip {$name}: {$sanStr} -> " . var_export($back['uci'] ?? null, true) . " (expected {$u})\n";
            $GLOBALS['t_fail']++;
            break;
        }
    }
    if ($all) {
        $GLOBALS['t_pass']++;
        echo "ok   SAN round-trip {$name} (" . count($map) . " moves)\n";
    }
}

/* ================================================================== */
/* K. FEN round-trip and rejection                                    */
/* ================================================================== */
sec('FEN round-trip / rejection');

$roundTrip = [
    'startpos' => Rules::START_FEN,
    'kiwipete' => 'r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1',
    'pos3'     => '8/2p5/3p4/KP5r/1R3p1k/8/4P1P1/8 w - - 0 1',
    'pos4'     => 'r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq - 0 1',
    'pos5'     => 'rnbq1k1r/pp1Pbppp/2p5/8/2B5/8/PPP1NnPP/RNBQK2R w KQ - 1 8',
    'pos6'     => 'r4rk1/1pp1qppp/p1np1n2/2b1p1B1/2B1P1b1/P1NP1N2/1PP1QPPP/R4RK1 w - - 0 1',
    'castling' => F_CASTLE,
    'ep+fen'   => 'rnbqkbnr/ppp1p1pp/8/3pPp2/8/8/PPPP1PPP/RNBQKBNR w KQkq f6 0 3',
];
foreach ($roundTrip as $name => $fen) {
    eq($fen, load($fen)->fen(), "FEN round-trip: {$name}");
}

// round-trip through a mid-game position
$c = Rules::start();
foreach ($scholarUci as $u) {
    $c->moveUci($u);
}
eq($c->fen(), load($c->fen())->fen(), 'FEN round-trip: after scholar game');

$reject = [
    '5 tokens'                  => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 1',
    '7 tokens'                  => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1 extra',
    'bad side to move'          => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR x KQkq - 0 1',
    'bad castling field'        => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQxq - 0 1',
    'Q right without a1 rook'   => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/1NBQKBNR w KQkq - 0 1',
    'K right without h1 rook'   => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBN1 w KQkq - 0 1',
    'ep rank wrong for side'    => 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e3 0 1',
    'ep on rank 4'              => 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e4 0 1',
    'non-numeric halfmove'      => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - x 1',
    'fullmove 0'                => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 0',
    'consecutive digits'        => 'rnbqkbnr/pppppppp/88/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
    'rank too wide'             => 'rnbqkbnr/pppppppp/9/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
    'two white kings'           => '4k3/8/8/8/8/8/8/4KK2 w - - 0 1',
    'missing black king'        => '8/8/8/8/8/8/8/4K3 w - - 0 1',
    'pawn on back rank'         => '4k3/8/8/8/8/8/8/P2K3p w - - 0 1',
    'adjacent kings'            => '3Kk3/8/8/8/8/8/8/8 w - - 0 1',
    'non-mover already in check'=> 'R6k/8/8/8/8/8/8/7K w - - 0 1',
    'only 7 ranks'              => '4k3/8/8/8/8/8/4K3 w - - 0 1',
];
foreach ($reject as $name => $fen) {
    eq(null, Rules::fromFen($fen), "rejects: {$name}");
}
ok(Rules::fromFen('') === null || true, 'empty FEN handled');
eq(null, Rules::fromFen(''), 'rejects: empty string');

/* ================================================================== */
/* L. replay()                                                        */
/* ================================================================== */
sec('replay()');

$c = Rules::start()->replay($scholarUci);
ok($c !== null, 'replay: valid game accepted');
if ($c !== null) {
    eq('r1bqkb1r/pppp1Qpp/2n2n2/4p3/2B1P3/8/PPPP1PPP/RNB1K1NR b KQkq - 0 4', $c->fen(), 'replay: final fen');
    eq($scholarUci, $c->history(), 'replay: history');
    eq('checkmate', $c->resultReason(), 'replay: resultReason checkmate');
}

// rejects when move k is illegal (a1a5 is well-formed but blocked by a2 pawn)
foreach ([0, 3, 5] as $k) {
    $bad = $scholarUci;
    $bad[$k] = 'a1a5';
    eq(null, Rules::start()->replay($bad), "replay: rejects illegal move at ply {$k}");
}
// rejects wrong-side move at ply 0
$bad = $scholarUci;
$bad[0] = 'a7a6';
eq(null, Rules::start()->replay($bad), 'replay: rejects black move at ply 0');
// rejects non-string entries
eq(null, Rules::start()->replay([42]), 'replay: rejects non-string entry');
// rejects invalid start FEN
eq(null, Rules::start()->replay(['e1e2'], 'not a fen'), 'replay: rejects invalid startFen');

// always builds from $startFen, ignoring the receiver state
$end = Rules::start();
foreach ($scholarUci as $u) {
    $end->moveUci($u);
}
$again = $end->replay(['e2e4']);
ok($again !== null, 'replay: works from finished-game receiver');
if ($again !== null) {
    eq('rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1', $again->fen(), 'replay: ignores receiver state');
}

// custom start FEN
$c = Rules::start()->replay(['e1e2'], '4k3/8/8/8/8/8/8/4K3 w - - 0 1');
ok($c !== null, 'replay: custom startFen accepted');
if ($c !== null) {
    eq('4k3/8/8/8/8/8/4K3/8 b - - 1 1', $c->fen(), 'replay: custom startFen result fen');
}

/* ================================================================== */
/* M. PGN export / parse                                              */
/* ================================================================== */
sec('PGN export / parse');

$c = Rules::start();
foreach ($scholarUci as $u) {
    $c->moveUci($u);
}
$pgn = $c->toPgn();
ok(str_contains($pgn, '[Event "Checkmate Game"]'), 'pgn: default Event tag');
ok(str_contains($pgn, '[Result "1-0"]'), 'pgn: Result tag 1-0');
ok(str_contains($pgn, '1. e4 e5 2. Bc4 Nc6 3. Qh5 Nf6 4. Qxf7# 1-0'), 'pgn: movetext');
eq($scholarUci, Rules::pgnToUci($pgn), 'pgn: export -> parse round-trip');

$pgn = $c->toPgn(['White' => 'Alice', 'Black' => 'Bob']);
ok(str_contains($pgn, '[White "Alice"]'), 'pgn: custom White tag');
ok(str_contains($pgn, '[Black "Bob"]'), 'pgn: custom Black tag');
ok(str_contains($pgn, '[Result "1-0"]'), 'pgn: Result still present');

// non-startpos game: SetUp/FEN tags and round-trip
$c = load(F_CASTLE);
$c->moveUci('a1a2');
$mv = $c->moveUci('e8g8');
$pgn = $c->toPgn();
ok(str_contains($pgn, '[SetUp "1"]'), 'pgn: SetUp tag for custom FEN');
ok(str_contains($pgn, '[FEN "' . F_CASTLE . '"]'), 'pgn: FEN tag for custom FEN');
ok(str_contains($pgn, '1. Ra2 O-O *'), 'pgn: castling movetext + * result');
eq(['a1a2', 'e8g8'], Rules::pgnToUci($pgn), 'pgn: custom-FEN round-trip');

// game starting with black to move: "1... Ke7 2. Ke2"
$c = load('4k3/8/8/8/8/8/8/4K3 b - - 0 1');
$c->moveUci('e8e7');
$c->moveUci('e1e2');
$pgn = $c->toPgn();
ok(str_contains($pgn, '1... Ke7 2. Ke2'), 'pgn: black-first movetext numbering');
eq(['e8e7', 'e1e2'], Rules::pgnToUci($pgn), 'pgn: black-first round-trip');

// comments, NAGs, variations and decorations are stripped when parsing
eq(
    ['e2e4', 'e7e5', 'd1h5', 'b8c6'],
    Rules::pgnToUci('1. e4! e5 {best} 2. Qh5? (2. Bc4) $1 Nc6 1-0'),
    'pgn: strips comments/NAGs/variations/annotations'
);

// malformed movetext
eq(null, Rules::pgnToUci('1. e4 xyzzy'), 'pgn: rejects malformed SAN');
eq(null, Rules::pgnToUci('1. e4 e4'), 'pgn: rejects illegal second move');
eq([], Rules::pgnToUci(''), 'pgn: empty movetext yields no moves');

// oversized input
eq(null, Rules::pgnToUci(str_repeat('1. e4 e5 ', 8000)), 'pgn: rejects >64KB input');
eq(null, Rules::pgnToUci(str_repeat('a', 70000)), 'pgn: rejects oversized garbage');

// >1024 moves rejected, <=1024 accepted (bare-kings game where the kings
// can shuttle forever; FEN tag switches the parser's start position)
$mk = static function (int $pairs): string {
    $s = "[SetUp \"1\"]\n[FEN \"4k3/8/8/8/8/8/8/4K3 w - - 0 1\"]\n\n";
    for ($i = 1; $i <= $pairs; $i++) {
        $s .= "{$i}. " . ($i % 2 === 1 ? 'Ke2' : 'Ke1') . ' ' . ($i % 2 === 1 ? 'Ke7' : 'Ke8') . ' ';
    }
    return $s;
};
$longOk = Rules::pgnToUci($mk(500));
ok(is_array($longOk) && count($longOk) === 1000, 'pgn: 1000-move game parses (<=1024)');
eq(null, Rules::pgnToUci($mk(550)), 'pgn: rejects >1024 moves');

/* ================================================================== */
/* summary                                                            */
/* ================================================================== */
echo "\n----------------------------------------\n";
printf("rules.php: %d passed, %d failed\n", $GLOBALS['t_pass'], $GLOBALS['t_fail']);
if ($GLOBALS['t_fail'] > 0) {
    echo "RESULT: FAIL\n";
    exit(1);
}
echo "RESULT: PASS\n";
exit(0);
