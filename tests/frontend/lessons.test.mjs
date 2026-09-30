/**
 * Lessons dataset contract.
 *
 * Every demo FEN must load in the vendored chess.js, and every claim a
 * caption makes about its position is asserted here:
 *   - l02 must genuinely be checkmate,
 *   - l03 must genuinely offer castling,
 *   - l04 must genuinely offer an en-passant capture,
 *   - l05 must genuinely offer promotions,
 *   - l01 must genuinely be the starting position.
 * If someone edits the text but not the FEN (or vice versa) this fails.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const JS_ROOT = fileURLToPath(new URL('../../app/src/main/assets/app/js/', import.meta.url));
const { LESSONS } = await import(`file://${JS_ROOT}data/lessons.js`);
const { Chess } = await import(`file://${JS_ROOT}vendor/chess.js`);

const byId = (id) => LESSONS.find((l) => l.id === id);

test('lesson set is well-formed', () => {
  assert.ok(LESSONS.length >= 6, 'at least six bundled lessons');
  const ids = new Set();
  for (const l of LESSONS) {
    assert.ok(!ids.has(l.id), `duplicate lesson id ${l.id}`);
    ids.add(l.id);
    assert.ok(typeof l.title === 'string' && l.title.length > 3, `${l.id} has a title`);
    assert.ok(typeof l.sub === 'string' && l.sub.length > 3, `${l.id} has a subtitle`);
    assert.ok(Number.isInteger(l.minutes) && l.minutes > 0, `${l.id} has a reading time`);
    assert.ok(Array.isArray(l.sections) && l.sections.length >= 2, `${l.id} has sections`);
    for (const s of l.sections) {
      assert.ok(typeof s.h3 === 'string' && s.h3.length > 0, `${l.id}: section heading`);
      assert.ok(
        (typeof s.p === 'string' && s.p.length > 20)
        || (Array.isArray(s.ul) && s.ul.length >= 2 && s.ul.every((x) => typeof x === 'string' && x.length > 5)),
        `${l.id}: section "${s.h3}" has prose or a list`,
      );
    }
    assert.ok(l.demo, `${l.id} has a demo position`);
    assert.ok(typeof l.demo.caption === 'string' && l.demo.caption.length > 5, `${l.id} demo caption`);
  }
});

test('every demo FEN loads through the vendored chess.js', () => {
  for (const l of LESSONS) {
    const chess = new Chess(l.demo.fen); // throws on any invalid FEN
    assert.ok(chess.fen().length > 10, `${l.id} demo fen round-trips`);
    assert.ok(
      chess.moves().length > 0 || chess.isCheckmate() || chess.isStalemate(),
      `${l.id} demo position has legal moves (or is a final position)`,
    );
  }
});

test('l01 demo is the real starting position', () => {
  const l = byId('l01-board-pieces');
  assert.ok(l, 'l01 exists');
  assert.equal(new Chess(l.demo.fen).fen(), new Chess().fen());
});

test('l02 demo is genuinely checkmate (Fool\'s mate)', () => {
  const l = byId('l02-check-mate-stalemate');
  assert.ok(l, 'l02 exists');
  const chess = new Chess(l.demo.fen);
  assert.ok(chess.isCheckmate(), 'demo FEN is checkmate');
  assert.equal(chess.turn(), 'w', 'mated side (White) is to move');
});

test('l03 demo genuinely offers castling both ways', () => {
  const l = byId('l03-castling');
  assert.ok(l, 'l03 exists');
  const chess = new Chess(l.demo.fen);
  const from = chess.moves({ square: 'e1', verbose: true });
  assert.ok(from.some((m) => m.to === 'g1'), 'kingside castling e1g1 available');
  assert.ok(from.some((m) => m.to === 'c1'), 'queenside castling e1c1 available');
});

test('l04 demo genuinely offers an en-passant capture', () => {
  const l = byId('l04-en-passant');
  assert.ok(l, 'l04 exists');
  const chess = new Chess(l.demo.fen);
  const ep = chess.moves({ verbose: true }).filter((m) => m.flags.includes('e'));
  assert.ok(ep.length >= 1, 'en-passant move exists');
  assert.equal(ep[0].to, 'd6', 'capture lands on d6 as the caption says');
});

test('l05 demo genuinely offers four promotions', () => {
  const l = byId('l05-promotion');
  assert.ok(l, 'l05 exists');
  const chess = new Chess(l.demo.fen);
  const promos = chess.moves({ verbose: true }).filter((m) => m.promotion);
  const pieces = new Set(promos.map((m) => m.promotion));
  assert.equal(promos.length, 4, 'exactly one move per promoting piece');
  assert.deepEqual([...pieces].sort(), ['b', 'n', 'q', 'r'], 'queen, rook, bishop, knight choices');
});

test('l06 demo is material-equal as the caption states', () => {
  const l = byId('l06-piece-values');
  assert.ok(l, 'l06 exists');
  const chess = new Chess(l.demo.fen);
  // No captures have happened yet: both sides still have all eight pawns
  // and both minors/rooks/queen — the count cannot separate them.
  const count = (color) => {
    const values = { p: 1, n: 3, b: 3, r: 5, q: 9, k: 0 };
    let total = 0;
    for (const row of chess.fen().split(' ')[0].split('/')) {
      for (const ch of row) {
        if (ch >= '1' && ch <= '8') continue; // rank gaps
        const isWhite = ch === ch.toUpperCase();
        if (isWhite !== (color === 'w')) continue;
        total += values[ch.toLowerCase()] ?? 0;
      }
    }
    return total;
  };
  assert.equal(count('w'), count('b'), 'material is level');
});
