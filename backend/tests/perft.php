<?php

declare(strict_types=1);

/**
 * Perft (performance test) for the Checkmate chess engine.
 *
 * Runs the standard benchmark positions with known-good reference node
 * counts.  The expected values below were independently re-verified against
 * the vendored reference implementation (chess.js 1.4.0, see
 * backend/tests/helpers/) - all matched, no discrepancies.
 *
 * Usage:
 *   php backend/tests/perft.php                 run the full table
 *   php backend/tests/perft.php --divide FEN D  split depth D by root move
 *
 * Exit code 0 = all counts correct, 1 = mismatch.
 */

require_once __DIR__ . '/../src/Autoloader.php';
\Checkmate\Autoloader::register();

use Checkmate\Game\Position;

/**
 * @return array<int, array{name: string, fen: string, cases: array<int, int>}>
 */
function perftPositions(): array
{
    return [
        [
            'name' => 'startpos (initial position)',
            'fen'  => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
            'cases' => [1 => 20, 2 => 400, 3 => 8902, 4 => 197281],
        ],
        [
            'name' => 'kiwipete',
            'fen'  => 'r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1',
            'cases' => [1 => 48, 2 => 2039, 3 => 97862],
        ],
        [
            'name' => 'position 3',
            'fen'  => '8/2p5/3p4/KP5r/1R3p1k/8/4P1P1/8 w - - 0 1',
            'cases' => [1 => 14, 2 => 191, 3 => 2812, 4 => 43238],
        ],
        [
            'name' => 'position 4',
            'fen'  => 'r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq - 0 1',
            'cases' => [1 => 6, 2 => 264, 3 => 9467],
        ],
        [
            'name' => 'position 5',
            'fen'  => 'rnbq1k1r/pp1Pbppp/2p5/8/2B5/8/PPP1NnPP/RNBQK2R w KQ - 1 8',
            'cases' => [1 => 44, 2 => 1486, 3 => 62379],
        ],
        [
            'name' => 'position 6',
            'fen'  => 'r4rk1/1pp1qppp/p1np1n2/2b1p1B1/2B1P1b1/P1NP1N2/1PP1QPPP/R4RK1 w - - 0 1',
            'cases' => [1 => 46, 2 => 2079, 3 => 89890],
        ],
    ];
}

/**
 * Run every perft case.
 *
 * @return array<int, array{name: string, depth: int, expected: int, actual: int, ms: float, pass: bool}>
 */
function runPerft(): array
{
    $rows = [];
    foreach (perftPositions() as $position) {
        foreach ($position['cases'] as $depth => $expected) {
            $engine = Position::fromFen($position['fen']);
            $start = microtime(true);
            $actual = $engine->perft($depth);
            $ms = (microtime(true) - $start) * 1000;
            $rows[] = [
                'name'     => $position['name'],
                'depth'    => $depth,
                'expected' => $expected,
                'actual'   => $actual,
                'ms'       => $ms,
                'pass'     => $actual === $expected,
            ];
        }
    }
    return $rows;
}

/** Print the perft table; returns true when all counts are correct. */
function printPerftTable(array $rows): bool
{
    printf("%-24s %5s %10s %10s %9s  %s\n", 'Position', 'Depth', 'Expected', 'Actual', 'Time', 'Status');
    printf("%s\n", str_repeat('-', 74));
    $passed = 0;
    foreach ($rows as $row) {
        printf(
            "%-24s %5d %10d %10d %7.0fms  %s\n",
            $row['name'],
            $row['depth'],
            $row['expected'],
            $row['actual'],
            $row['ms'],
            $row['pass'] ? 'PASS' : 'FAIL',
        );
        if ($row['pass']) {
            $passed++;
        }
    }
    printf("%s\n", str_repeat('-', 74));
    printf(
        "Perft summary: %d/%d passed (total nodes: %d, total time: %.1fs)\n",
        $passed,
        count($rows),
        array_sum(array_column($rows, 'actual')),
        array_sum(array_column($rows, 'ms')) / 1000,
    );
    return $passed === count($rows);
}

// ---------------------------------------------------------------------------
// CLI entry point (only when executed directly, not when included by the suite)
// ---------------------------------------------------------------------------
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    global $argv;
    if (isset($argv[1]) && $argv[1] === '--divide') {
        // debug helper: php perft.php --divide "<fen>" <depth>
        $fen = $argv[2] ?? '';
        $depth = (int) ($argv[3] ?? 1);
        $position = Position::fromFen($fen);
        $divide = $position->perftDivide($depth);
        arsort($divide);
        foreach ($divide as $san => $nodes) {
            printf("%-10s %d\n", $san, $nodes);
        }
        printf("total      %d\n", array_sum($divide));
        exit(0);
    }

    $rows = runPerft();
    exit(printPerftTable($rows) ? 0 : 1);
}
