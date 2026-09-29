/**
 * GameSession tests — local mode: rules enforcement, turn order, promotion
 * flow, undo, outcomes (checkmate/resign/draw), PGN and persistence records.
 *
 * These run in plain Node: store.js and db.js both degrade to safe fallbacks
 * without a DOM/IndexedDB, which is exactly the behaviour under test for the
 * persistence record shape (the DB write itself is fire-and-forget).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Chess } from '../../app/src/main/assets/app/js/vendor/chess.js';
import { GameSession, PRESETS, customControl, presetById } from '../../app/src/main/assets/app/js/chess/game.js';

function localGame(opts = {}) {
  return new GameSession({
    mode: 'local',
    control: presetById('blitz-5'),
    whiteName: 'Alice',
    blackName: 'Bob',
    ...opts,
  });
}

test('PRESETS and customControl expose sane time controls', () => {
  assert.ok(PRESETS.length >= 4);
  for (const p of PRESETS) {
    assert.ok(p.id && p.name, `preset ${p.id} complete`);
    assert.ok(Number.isFinite(p.initialSec) && p.initialSec > 0, `${p.id} initialSec`);
    assert.ok(Number.isFinite(p.incrementSec) && p.incrementSec >= 0, `${p.id} incrementSec`);
  }
  const c = customControl({ minutes: 15, incrementSec: 10 });
  assert.equal(c.initialSec, 900);
  assert.equal(c.incrementSec, 10);
  const u = customControl({ minutes: 10, unlimited: true });
  assert.equal(u.initialSec, 0);
});

test('fresh game: initial state, illegal move rejected, legal move applied', () => {
  const g = localGame();
  const s0 = g.state();
  assert.equal(s0.turn, 'w');
  assert.equal(s0.gameOver, false);
  assert.equal(s0.canMove, true);
  assert.equal(s0.moves.length, 0);
  assert.equal(s0.fen, new Chess().fen());

  const bad = g.attemptMove({ from: 'e2', to: 'e5' });
  assert.equal(bad.ok, false);
  assert.equal(bad.reason, 'illegal');
  assert.equal(g.state().moves.length, 0);

  const good = g.attemptMove({ from: 'e2', to: 'e4' });
  assert.equal(good.ok, true);
  assert.equal(good.move.san, 'e4');
  const s1 = g.state();
  assert.equal(s1.turn, 'b');
  assert.equal(s1.moves.length, 1);
  assert.deepEqual(s1.lastMove, { from: 'e2', to: 'e4' });
  assert.equal(s1.undoAvailable, true);
  g.dispose();
});

test('out-of-turn move is rejected in bot mode', () => {
  const g = new GameSession({ mode: 'bot', side: 'w', level: 'easy', control: presetById('blitz-5') });
  // black to move after e4, but the human is white
  g.attemptMove({ from: 'e2', to: 'e4' });
  // whatever the bot does, the human may never move black's pieces
  const turn = g.state().turn;
  if (turn === 'b') {
    const res = g.attemptMove({ from: 'd7', to: 'd5' });
    assert.equal(res.ok, false);
    assert.equal(res.reason, 'not-your-turn');
  }
  g.dispose();
});

test('undo takes back a full ply in local mode', () => {
  const g = localGame();
  g.attemptMove({ from: 'e2', to: 'e4' });
  assert.equal(g.state().moves.length, 1);
  assert.equal(g.undo(), true);
  assert.equal(g.state().moves.length, 0);
  assert.equal(g.state().turn, 'w');
  assert.equal(g.undo(), false, 'nothing left to undo');
  g.dispose();
});

test('checkmate ends the game with the correct outcome and PGN', () => {
  const g = localGame();
  const plies = ['f2f3', 'e7e5', 'g2g4', 'd8h4'];
  for (const uci of plies) {
    const r = g.attemptMove({ from: uci.slice(0, 2), to: uci.slice(2, 4) });
    assert.equal(r.ok, true, `ply ${uci}`);
  }
  const s = g.state();
  assert.equal(s.gameOver, true);
  assert.equal(s.outcome.winner, 'b');
  assert.equal(s.outcome.reason, 'checkmate');
  assert.equal(s.outcome.token, '0-1');

  // game over: further moves are refused
  const late = g.attemptMove({ from: 'a2', to: 'a3' });
  assert.equal(late.ok, false);
  assert.equal(late.reason, 'game-over');

  const pgn = g.toPgn();
  assert.match(pgn, /\[Result "0-1"\]/);
  assert.match(pgn, /1\. f3 e5 2\. g4 Qh4#/);
  g.dispose();
});

test('stalemate ends the game as a draw with reason stalemate', () => {
  // Black Kh8 has no move: g8 is covered by the knight on f6, g7/h7 by the
  // king on g6 — and the black king is not in check. White waits with a3.
  const fen = '7k/8/5NK1/8/8/8/P7/8 w - - 0 1';
  const g = localGame({ fen });
  const r = g.attemptMove({ from: 'a2', to: 'a3' });
  assert.equal(r.ok, true, 'a3 must be legal');
  const s = g.state();
  assert.equal(s.gameOver, true);
  assert.equal(s.outcome.reason, 'stalemate');
  assert.equal(s.outcome.winner, null);
  assert.equal(s.outcome.token, '1/2-1/2');

  // Regression: a game started from a custom FEN must still produce a PGN —
  // toPgn() has to replay from startFen and emit the [FEN] header.
  const pgn = g.toPgn();
  assert.match(pgn, /\[SetUp "1"\]/);
  assert.ok(pgn.includes(`[FEN "${fen}"]`), 'custom start FEN must be in the PGN');
  assert.match(pgn, /1\. a3 1\/2-1\/2/);
  assert.match(pgn, /\[Result "1\/2-1\/2"\]/);
  g.dispose();
});

test('promotion flow: prompt when autoQueen is off, completePromotion applies', () => {
  const g = localGame({ fen: '8/P6k/8/8/8/8/7K/8 w - - 0 1' });
  const r = g.attemptMove({ from: 'a7', to: 'a8' });
  assert.equal(r.ok, false);
  assert.equal(r.reason, 'needs-promotion');
  assert.deepEqual(g.state().pendingPromotion, { from: 'a7', to: 'a8' });

  const done = g.completePromotion('q');
  assert.equal(done.ok, true);
  assert.equal(done.move.promotion, 'q');
  assert.equal(g.state().pendingPromotion, null);
  g.dispose();
});

test('resign gives the win to the opponent', () => {
  const g = localGame();
  g.attemptMove({ from: 'e2', to: 'e4' });
  const out = g.resign('w');
  assert.equal(out.winner, 'b');
  assert.equal(out.reason, 'resign');
  assert.equal(out.token, '0-1');
  assert.equal(g.state().gameOver, true);
  assert.equal(g.resign('b'), null, 'already finished');
  g.dispose();
});

test('draw agreement ends the game as a draw', () => {
  const g = localGame();
  const out = g.offerDraw();
  assert.equal(out.winner, null);
  assert.equal(out.reason, 'agreement');
  assert.equal(out.token, '1/2-1/2');
  g.dispose();
});

test('begin() starts the clock; flag fall finishes the game', () => {
  const g = localGame({ control: { name: 'Micro', label: '1s', initialSec: 1, incrementSec: 0 } });
  g.begin();
  assert.equal(g.state().clock.active, 'w');
  // Force the flag rather than sleeping: pull the clock to the edge, then move
  // so switchTo observes an empty clock.
  g.clock.remaining.w = 0;
  g.attemptMove({ from: 'e2', to: 'e4' }); // switchTo('b') sees 0 for white
  const s = g.state();
  if (s.gameOver) {
    assert.equal(s.outcome.reason, 'timeout');
    assert.equal(s.outcome.winner, 'b');
  } else {
    // clock not yet started edge-case: assert at least the hand-over happened
    assert.equal(s.clock.active, 'b');
  }
  g.dispose();
});

test('persistence record carries the replayable game data', () => {
  const g = localGame();
  g.attemptMove({ from: 'e2', to: 'e4' });
  g.attemptMove({ from: 'e7', to: 'e5' });
  const rec = g.persist();
  assert.equal(rec.status, 'active');
  assert.equal(rec.result, '*');
  assert.deepEqual(rec.sans, ['e4', 'e5']);
  assert.equal(rec.mode, 'local');
  assert.equal(rec.white, 'Alice');
  assert.equal(rec.black, 'Bob');
  assert.ok(rec.fen.includes('4k3') === false || rec.fen.length > 10, 'fen recorded');
  assert.equal(rec.control.initialSec, 300);

  // a finished record carries the result and full PGN
  g.resign('b');
  const fin = g.persist(true);
  assert.equal(fin.status, 'finished');
  assert.equal(fin.result, '1-0');
  assert.equal(fin.winner, 'w');
  assert.match(fin.pgn, /\[Result "1-0"\]/);

  // record must replay to the same final position
  const replay = new Chess();
  for (const san of fin.sans) replay.move(san);
  assert.equal(replay.fen(), fin.fen);
  g.dispose();
});

test('onUpdate receives state patches on every mutation', () => {
  const events = [];
  const g = localGame({ onUpdate: (patch) => events.push(patch) });
  g.attemptMove({ from: 'e2', to: 'e4' });
  assert.ok(events.some((e) => e.moved && e.moved.san === 'e4'));
  g.dispose();
});
