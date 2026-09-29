<?php

declare(strict_types=1);

/**
 * Perft (performance test) for the Checkmate chess rules engine
 * (backend/src/Chess/Rules.php).
 *
 * All six standard Chess Programming Wiki perft positions are verified
 * against known-good reference node counts. Every position runs through
 * depth 3 at minimum; startpos, position 3, 4, 5 and 6 additionally run
 * depth 4 (the values required by the test specification).
 *
 * Usage:
 *   php backend/tests/chess/perft.php           full table (incl. depth 4)
 *   php backend/tests/chess/perft.php --quick   cap every position at depth 3
 *
 * Exit code 0 = all counts correct, 1 = mismatch or FEN load failure.
 */

require_once __DIR__ . '/../../src/Chess/Rules.php';

use Checkmate\Chess\Rules;

/**
 * @return list<array{name: string, fen: string, cases: array<int, int>}>
 */
function perftPositions(): array
{
    return [
        [
            'name'  => 'startpos',
            'fen'   => Rules::START_FEN,
            'cases' => [1 => 20, 2 => 400, 3 => 8902, 4 => 197281],
        ],
        [
            'name'  => 'kiwipete',
            'fen'   => 'r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1',
            'cases' => [1 => 48, 2 => 2039, 3 => 97862],
        ],
        [
            'name'  => 'position 3',
            'fen'   => '8/2p5/3p4/KP5r/1R3p1k/8/4P1P1/8 w - - 0 1',
            'cases' => [1 => 14, 2 => 191, 3 => 2812, 4 => 43238],
        ],
        [
            'name'  => 'position 4',
            'fen'   => 'r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq - 0 1',
            'cases' => [1 => 6, 2 => 264, 3 => 9467, 4 => 422333],
        ],
        [
            'name'  => 'position 5',
            'fen'   => 'rnbq1k1r/pp1Pbppp/2p5/8/2B5/8/PPP1NnPP/RNBQK2R w KQ - 1 8',
            'cases' => [1 => 44, 2 => 1486, 3 => 62379, 4 => 2103487],
        ],
        [
            'name'  => 'position 6',
            'fen'   => 'r4rk1/1pp1qppp/p1np1n2/2b1p1B1/2B1P1b1/P1NP1N2/1PP1QPPP/R4RK1 w - - 0 1',
            'cases' => [1 => 46, 2 => 2079, 3 => 89890, 4 => 3894594],
        ],
    ];
}

/**
 * Runs the table.
 *
 * @return list<array{name: string, depth: int, expected: int, actual: int, sec: float, pass: bool}>
 */
function runPerft(int $maxDepth): array
{
    $rows = [];
    foreach (perftPositions() as $pos) {
        $engine = Rules::fromFen($pos['fen']);
        if ($engine === null) {
            fwrite(STDERR, "FAIL could not load FEN for {$pos['name']}\n");
            exit(1);
        }
        foreach ($pos['cases'] as $depth => $expected) {
            if ($depth > $maxDepth) {
                continue;
            }
            $t0 = microtime(true);
            $actual = $engine->perft($depth);
            $sec = microtime(true) - $t0;
            $pass = $actual === $expected;
            $rows[] = [
                'name' => $pos['name'], 'depth' => $depth,
                'expected' => $expected, 'actual' => $actual,
                'sec' => $sec, 'pass' => $pass,
            ];
            printf(
                "%-12s d%d  expected %10d  actual %10d  %8.2fs  %s\n",
                $pos['name'], $depth, $expected, $actual, $sec,
                $pass ? 'PASS' : 'FAIL'
            );
            if (!$pass) {
                echo "FAIL node count mismatch for {$pos['name']} at depth {$depth}\n";
            }
        }
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $quick = in_array('--quick', $argv, true);
    $maxDepth = $quick ? 3 : 4;

    printf("PHP %s | opcache.cli=%s | jit=%s | mode=%s\n",
        PHP_VERSION,
        var_export((bool) ini_get('opcache.enable_cli'), true),
        (string) (ini_get('opcache.jit') ?: 'off'),
        $quick ? 'quick (depth <= 3)' : 'full (depth <= 4)'
    );
    echo str_repeat('-', 74) . "\n";

    $rows = runPerft($maxDepth);

    echo str_repeat('-', 74) . "\n";
    $passed = count(array_filter($rows, static fn (array $r): bool => $r['pass']));
    printf(
        "perft summary: %d/%d passed | nodes: %d | time: %.1fs\n",
        $passed,
        count($rows),
        array_sum(array_column($rows, 'actual')),
        array_sum(array_column($rows, 'sec'))
    );

    if ($passed !== count($rows)) {
        echo "RESULT: FAIL\n";
        exit(1);
    }
    echo "RESULT: PASS\n";
    exit(0);
}
