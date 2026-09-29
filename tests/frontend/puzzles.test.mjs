/**
 * Puzzle dataset structural tests.
 *
 * The chess-truth verification (every mate-in-1 listed, every mate-in-2
 * holding against all defences) lives in `tests/frontend/verify-puzzles.mjs`,
 * which runs as its own step. This file guards the *schema* so the app can
 * rely on the shape at runtime.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PUZZLES } from '../../app/src/main/assets/app/js/data/puzzles.js';

const UCI = /^[a-h][1-8][a-h][1-8](q|r|b|n)?$/;

test('dataset is non-empty and every entry is complete', () => {
  assert.ok(PUZZLES.length >= 10, 'expected a real set');
  for (const p of PUZZLES) {
    assert.match(p.id, /^p\d{2}-/, `${p.id}: id format`);
    assert.match(p.fen, / [wb] /, `${p.id}: FEN has a turn field`);
    assert.ok(p.turn === 'w' || p.turn === 'b', `${p.id}: turn`);
    assert.ok(['mate1', 'mate2'].includes(p.kind), `${p.id}: kind`);
    assert.ok(p.solution.length >= 1, `${p.id}: non-empty solution`);
    assert.ok(Number.isInteger(p.difficulty) && p.difficulty >= 1 && p.difficulty <= 3, `${p.id}: difficulty 1..3`);
    assert.ok(p.theme && p.goal && p.source, `${p.id}: text fields`);
    for (const mv of p.solution) assert.match(mv, UCI, `${p.id}: solution uci ${mv}`);
    if (p.kind === 'mate1') {
      assert.equal(p.line, undefined, `${p.id}: mate1 must not carry a scripted reply`);
    } else {
      assert.ok(Array.isArray(p.line) && p.line.length >= 1, `${p.id}: mate2 needs a reply line`);
      for (const mv of p.line) assert.match(mv, UCI, `${p.id}: line uci ${mv}`);
    }
  }
});

test('ids and FENs are unique', () => {
  const ids = new Set();
  const fens = new Set();
  for (const p of PUZZLES) {
    assert.equal(ids.has(p.id), false, `duplicate id ${p.id}`);
    assert.equal(fens.has(p.fen), false, `duplicate FEN ${p.fen}`);
    ids.add(p.id);
    fens.add(p.fen);
  }
});

test('goals name the side to move (no ambiguous prompts)', () => {
  for (const p of PUZZLES) {
    const side = p.turn === 'w' ? 'White' : 'Black';
    assert.ok(p.goal.includes(side), `${p.id}: goal should say ${side} to move`);
  }
});
