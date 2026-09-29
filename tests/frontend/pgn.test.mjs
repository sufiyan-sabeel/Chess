/**
 * PGN tests — build/parse round-trips plus a cross-check that the vendored
 * chess.js itself loads every PGN we emit.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Chess } from '../../app/src/main/assets/app/js/vendor/chess.js';
import {
  buildPgn,
  parsePgn,
  looksLikePgn,
  buildScrubber,
  RESULTS,
  pgnResultToken,
} from '../../app/src/main/assets/app/js/chess/pgn.js';

const GAME = ['e4', 'e5', 'Nf3', 'Nc6', 'Bb5', 'a6', 'Ba4', 'Nf6', 'O-O'];

test('buildPgn emits headers and movetext', () => {
  const pgn = buildPgn({
    moves: GAME,
    white: 'Alice',
    black: 'Bob',
    event: 'Test Cup',
    result: RESULTS.white,
    timeControl: '300+5',
    termination: 'normal',
    date: new Date(Date.UTC(2026, 0, 15)),
  });
  assert.match(pgn, /\[Event "Test Cup"\]/);
  assert.match(pgn, /\[White "Alice"\]/);
  assert.match(pgn, /\[Black "Bob"\]/);
  assert.match(pgn, /\[Result "1-0"\]/);
  assert.match(pgn, /\[TimeControl "300\+5"\]/);
  assert.match(pgn, /\[Termination "normal"\]/);
  assert.match(pgn, /\[Date "2026\.01\.15"\]/);
  assert.match(pgn, /1\. e4 e5 2\. Nf3 Nc6/);
  assert.match(pgn, /1-0/);
});

test('parsePgn round-trips what buildPgn produced', () => {
  const pgn = buildPgn({ moves: GAME, white: 'A', black: 'B', result: RESULTS.draw });
  const parsed = parsePgn(pgn);
  assert.deepEqual(parsed.moves, GAME);
  assert.equal(parsed.headers.White, 'A');
  assert.equal(parsed.headers.Black, 'B');
  assert.equal(parsed.result, '1/2-1/2');
  assert.equal(parsed.startFen, '');
});

test('buildPgn rejects an illegal SAN list', () => {
  // chess.js 1.4.0 throws on unknown SAN; pgn.js lets that propagate (or
  // raises its own guard). Either way the contract is "throws".
  assert.throws(() => buildPgn({ moves: ['e4', 'e5', 'Qz9'] }), /illegal SAN|Invalid move/);
  assert.throws(() => buildPgn({ moves: ['e4'], fen: 'not-a-fen' }), /invalid start FEN/);
});

test('buildScrubber produces one FEN per ply plus the start position', () => {
  const pgn = buildPgn({ moves: GAME });
  const scrub = buildScrubber(pgn);
  assert.equal(scrub.sans.length, GAME.length);
  assert.equal(scrub.fens.length, GAME.length + 1);
  assert.equal(scrub.fens[0], scrub.startsWithFen);
  assert.equal(scrub.startsWithFen, new Chess().fen());

  // each snapshot equals an independent replay of the same prefix
  const ref = new Chess();
  for (let i = 0; i < GAME.length; i++) {
    ref.move(GAME[i]);
    assert.equal(scrub.fens[i + 1], ref.fen(), `mismatch at ply ${i + 1}`);
  }
});

test('buildScrubber honours a custom start FEN', () => {
  const start = '8/P6k/8/8/8/8/7K/8 w - - 0 1';
  const pgn = buildPgn({ moves: ['a7a8=Q'], fen: start });
  const scrub = buildScrubber(pgn);
  assert.equal(scrub.startsWithFen, new Chess(start).fen());
  assert.deepEqual(scrub.sans, ['a8=Q']);
  assert.equal(scrub.fens.length, 2);
});

test('the vendored chess.js loads our PGN verbatim', () => {
  const pgn = buildPgn({ moves: GAME, white: 'A', black: 'B', result: RESULTS.black });
  const chess = new Chess();
  const ok = chess.loadPgn(pgn);
  assert.notEqual(ok, false, 'chess.js rejected our PGN');
  assert.deepEqual(chess.history(), GAME);
  assert.equal(chess.getHeaders().Result, '0-1');
});

test('looksLikePgn is a cheap and correct pre-filter', () => {
  assert.equal(looksLikePgn(buildPgn({ moves: ['e4'] })), true);
  assert.equal(looksLikePgn('1. e4 e5'), false);
  assert.equal(looksLikePgn(''), false);
  assert.equal(looksLikePgn(null), false);
});

test('pgnResultToken maps outcomes to tokens', () => {
  assert.equal(pgnResultToken(null), '*');
  assert.equal(pgnResultToken({ winner: 'w' }), '1-0');
  assert.equal(pgnResultToken({ winner: 'b' }), '0-1');
  assert.equal(pgnResultToken({ winner: null }), '1/2-1/2');
  assert.equal(RESULTS.ongoing, '*');
});
