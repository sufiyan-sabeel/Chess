/**
 * Verify the bundled puzzle set with the vendored chess.js.
 *
 * Every shipped puzzle must be *provably* correct on this device:
 *   kind 'mate1' — side to move has ≥1 legal move that delivers checkmate,
 *                  and `solution` lists every such move;
 *   kind 'mate2' — after `solution[0]` the opponent has ≥1 legal reply and
 *                  EVERY legal reply allows a checkmate in one move.
 *
 * Usage: node tests/frontend/verify-puzzles.mjs
 */

import { Chess } from '../../app/src/main/assets/app/js/vendor/chess.js';
import { PUZZLES } from '../../app/src/main/assets/app/js/data/puzzles.js';

let failures = 0;
const problems = [];

function matingMoves(chess) {
  return chess.moves({ verbose: true }).filter((m) => {
    chess.move(m);
    const mate = chess.isCheckmate();
    chess.undo();
    return mate;
  });
}

for (const p of PUZZLES) {
  const chess = new Chess();
  try {
    chess.load(p.fen);
  } catch (e) {
    failures++;
    problems.push(`${p.id}: invalid FEN (${e.message})`);
    continue;
  }

  if (chess.turn() !== p.turn) {
    failures++;
    problems.push(`${p.id}: FEN turn ${chess.turn()} != declared ${p.turn}`);
    continue;
  }
  if (chess.isCheckmate() || chess.isStalemate() || chess.isDraw()) {
    failures++;
    problems.push(`${p.id}: start position is already terminal`);
    continue;
  }

  const legal = chess.moves({ verbose: true });
  const uci = (m) => `${m.from}${m.to}${m.promotion || ''}`;

  if (p.kind === 'mate1') {
    const mates = matingMoves(chess);
    if (!mates.length) {
      failures++;
      problems.push(`${p.id}: no checkmating move exists`);
      continue;
    }
    const expected = mates.map(uci).sort();
    const declared = [...p.solution].sort();
    if (JSON.stringify(expected) !== JSON.stringify(declared)) {
      failures++;
      problems.push(`${p.id}: solution mismatch\n    expected: ${expected.join(' ')}\n    declared: ${declared.join(' ')}`);
      continue;
    }
  } else if (p.kind === 'mate2') {
    const first = p.solution[0];
    const mv = legal.find((m) => uci(m) === first);
    if (!mv) {
      failures++;
      problems.push(`${p.id}: solution move ${first} is illegal`);
      continue;
    }
    chess.move(mv);
    const replies = chess.moves({ verbose: true });
    if (!replies.length) {
      failures++;
      problems.push(`${p.id}: first move ends the game — that is a mate in 1, not 2`);
      chess.undo();
      continue;
    }
    let answered = 0;
    for (const reply of replies) {
      chess.move(reply);
      const mates = matingMoves(chess);
      if (mates.length) answered++;
      chess.undo();
    }
    chess.undo();
    if (answered !== replies.length) {
      failures++;
      problems.push(`${p.id}: only ${answered}/${replies.length} replies allow mate in 1`);
      continue;
    }
    p.forcedReplies = replies.length;
  } else {
    failures++;
    problems.push(`${p.id}: unknown kind ${p.kind}`);
    continue;
  }

  if (!p.goal || !p.theme) {
    failures++;
    problems.push(`${p.id}: missing goal/theme`);
  }
}

// Duplicate FEN check — a set of duplicates would make progress counts lie.
const seen = new Map();
for (const p of PUZZLES) {
  if (seen.has(p.fen)) {
    failures++;
    problems.push(`${p.id}: duplicate FEN with ${seen.get(p.fen)}`);
  }
  seen.set(p.fen, p.id);
}

// Difficulty must be within the documented 1..3 range.
for (const p of PUZZLES) {
  if (!(p.difficulty >= 1 && p.difficulty <= 3)) {
    failures++;
    problems.push(`${p.id}: difficulty ${p.difficulty} outside 1..3`);
  }
}

if (failures) {
  console.error(`FAIL: ${failures} problem(s) across ${PUZZLES.length} puzzles`);
  for (const line of problems) console.error(`  - ${line}`);
  process.exit(1);
}

const byKind = PUZZLES.reduce((acc, p) => { acc[p.kind] = (acc[p.kind] || 0) + 1; return acc; }, {});
const byDiff = PUZZLES.reduce((acc, p) => { acc[p.difficulty] = (acc[p.difficulty] || 0) + 1; return acc; }, {});
console.log(`PASS: ${PUZZLES.length} puzzles verified against chess.js`);
console.log(`  by kind : ${JSON.stringify(byKind)}`);
console.log(`  by level: ${JSON.stringify(byDiff)}`);
console.log(`  ids     : ${PUZZLES.map((p) => p.id).join(', ')}`);
