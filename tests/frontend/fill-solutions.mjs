/**
 * Fill `solution` arrays for mate1 puzzles with the complete set of mating
 * moves, then run the full verification.
 *
 * Usage: node tests/frontend/fill-solutions.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Chess } from '../../app/src/main/assets/app/js/vendor/chess.js';
import { PUZZLES } from '../../app/src/main/assets/app/js/data/puzzles.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const target = path.resolve(here, '../../app/src/main/assets/app/js/data/puzzles.js');

const uci = (m) => `${m.from}${m.to}${m.promotion || ''}`;

const solved = PUZZLES.map((p) => {
  if (p.kind !== 'mate1' || (Array.isArray(p.solution) && p.solution.length)) return p;
  const chess = new Chess();
  chess.load(p.fen);
  const mates = chess.moves({ verbose: true }).filter((m) => {
    chess.move(m);
    const ok = chess.isCheckmate();
    chess.undo();
    return ok;
  });
  const solution = mates.map(uci);
  console.log(`${p.id}: ${solution.length ? solution.join(' ') : 'NO MATE FOUND'}`);
  return { ...p, solution };
});

if (PUZZLES.every((p, i) => JSON.stringify(p.solution) === JSON.stringify(solved[i].solution))) {
  console.log('No changes needed.');
  process.exit(0);
}

// Rewrite only the solution arrays that were empty, preserving file formatting.
let src = fs.readFileSync(target, 'utf8');
for (let i = 0; i < PUZZLES.length; i++) {
  const before = PUZZLES[i];
  const after = solved[i];
  if (before.solution.length === after.solution.length) continue;
  const idLine = new RegExp(`(id: '${before.id}',[\\s\\S]*?solution: \\[[^\\]]*\\])`);
  if (!idLine.test(src)) {
    console.error(`could not locate solution array for ${before.id}`);
    process.exit(1);
  }
  src = src.replace(idLine, (m, group1) => `${group1.slice(0, group1.lastIndexOf('[') + 1)}${after.solution.map((s) => `'${s}'`).join(', ')}]`);
}

fs.writeFileSync(target, src);
console.log(`\nWrote solutions for ${solved.filter((p, i) => p.solution.length !== PUZZLES[i].solution.length).length} puzzle(s).`);
