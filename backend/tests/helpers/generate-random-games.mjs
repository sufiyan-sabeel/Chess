#!/usr/bin/env node
/**
 * Random-game generator for the PHP engine cross-check.
 *
 * Generates N random legal games (40-120 plies each, ~30% starting from a
 * random mid-game position) using the VENDORED reference implementation
 * (chess.js 1.4.0) and writes them to a JSON file that
 * `backend/tests/chess-random.php` replays with the PHP engine, asserting
 * identical FENs, legal-move SAN lists and draw/terminal flags after every
 * move.
 *
 * REQUIRES Node 18+.  This is an OPTIONAL developer tool - it is NOT run in
 * CI; the pure-PHP suite (backend/tests/chess-suite.php) skips the cross-check
 * when the generated JSON file is absent.
 *
 * Usage:
 *   node backend/tests/helpers/generate-random-games.mjs
 *     [--games 200] [--seed 42] [--out /tmp/opencode/chess-random-games.json]
 *
 * The output is deterministic for a given seed.
 */

import { copyFileSync } from 'node:fs';
import { writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import path from 'node:path';
import os from 'node:os';

// ---------------------------------------------------------------------------
// arguments
// ---------------------------------------------------------------------------
function arg(name, fallback) {
  const index = process.argv.indexOf(`--${name}`);
  return index !== -1 && process.argv[index + 1] ? process.argv[index + 1] : fallback;
}

const GAME_COUNT = parseInt(arg('games', '200'), 10);
const SEED = parseInt(arg('seed', '42'), 10);
const OUT = arg('out', '/tmp/opencode/chess-random-games.json');
const CHESS_JS = path.resolve(
  path.dirname(new URL(import.meta.url).pathname),
  '../../../app/src/main/assets/app/js/vendor/chess.js',
);

// ---------------------------------------------------------------------------
// load the vendored chess.js (ESM .js file: copy to a .mjs temp file so Node
// parses it as an ES module regardless of the nearest package.json "type")
// ---------------------------------------------------------------------------
const tmp = path.join(os.tmpdir(), `checkmate-chessjs-${process.pid}.mjs`);
copyFileSync(CHESS_JS, tmp);
const { Chess } = await import(pathToFileURL(tmp).href);

// ---------------------------------------------------------------------------
// deterministic RNG (mulberry32)
// ---------------------------------------------------------------------------
function mulberry32(seed) {
  let a = seed >>> 0;
  return function () {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const random = mulberry32(SEED);
const randInt = (n) => Math.floor(random() * n);

const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

function snapshot(chess) {
  return {
    fen: chess.fen(),
    legalSans: chess.moves().sort(),
    flags: {
      check: chess.isCheck(),
      checkmate: chess.isCheckmate(),
      stalemate: chess.isStalemate(),
      insufficient: chess.isInsufficientMaterial(),
      threefold: chess.isThreefoldRepetition(),
      fifty: chess.isDrawByFiftyMoves(),
      gameOver: chess.isGameOver(),
    },
  };
}

function generateGame(id) {
  const chess = new Chess(START_FEN);

  // ~30% of games start from a random mid-game position (a random playout
  // first); then reset chess.js so repetition counting starts from there,
  // exactly like PHP's Game::fromFen().
  if (random() < 0.3) {
    const preRoll = 4 + randInt(27);
    for (let i = 0; i < preRoll; i++) {
      if (chess.isGameOver()) {
        break;
      }
      const moves = chess.moves();
      chess.move(moves[randInt(moves.length)]);
    }
    if (chess.isGameOver()) {
      return null; // retry with a fresh game
    }
    const fen = chess.fen();
    chess.load(fen); // resets history + repetition counters
  }

  const targetPlies = 40 + randInt(81); // 40..120
  const moves = [];
  const ucis = [];
  const fens = [];
  const snapshots = [snapshot(chess)];

  while (moves.length < targetPlies && !chess.isGameOver()) {
    const legal = chess.moves({ verbose: true });
    const chosen = legal[randInt(legal.length)];
    chess.move(chosen.san);
    moves.push(chosen.san);
    ucis.push(chosen.lan); // from + to + promotion, i.e. UCI
    fens.push(chess.fen());
    snapshots.push(snapshot(chess));
  }

  return {
    id,
    startFen: snapshots[0].fen,
    moves,
    ucis,
    fens,
    legalSans: snapshots.map((s) => s.legalSans),
    flags: snapshots.map((s) => s.flags),
    terminated: snapshots[snapshots.length - 1].flags.gameOver ? 'game-over' : 'length-limit',
  };
}

// ---------------------------------------------------------------------------
// generate
// ---------------------------------------------------------------------------
const games = [];
let id = 0;
while (games.length < GAME_COUNT) {
  const game = generateGame(id++);
  if (game !== null) {
    games.push(game);
  }
  if (id > GAME_COUNT * 10) {
    throw new Error('too many retries generating games');
  }
}

const payload = {
  generator: 'chess.js 1.4.0 (vendored reference)',
  seed: SEED,
  count: games.length,
  games,
};

await writeFile(OUT, JSON.stringify(payload), 'utf8');

const plies = games.reduce((sum, g) => sum + g.moves.length, 0);
const over = games.filter((g) => g.terminated === 'game-over').length;
console.log(`wrote ${games.length} games (${plies} plies, ${over} ended in a terminal position) -> ${OUT}`);
console.log(`seed ${SEED}; reproduce with: node ${path.basename(process.argv[1])} --seed ${SEED} --games ${GAME_COUNT}`);
