/**
 * Bot tests — every returned move must be legal for the position and level,
 * terminal/invalid positions must return null, and the searching levels must
 * find a forced mate in one.
 *
 * The bot itself is only checked for legality and tactic-finding here; its
 * strength claims (documented as easy/medium/hard) are behavioural, not
 * rating-based, so no rating numbers are asserted anywhere.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Chess } from '../../app/src/main/assets/app/js/vendor/chess.js';
import { chooseMove, LEVELS, evaluate } from '../../app/src/main/assets/app/js/chess/bot.js';

const START = new Chess().fen();

function assertLegal(fen, mv, label) {
  assert.ok(mv, `${label}: expected a move`);
  assert.ok(typeof mv.from === 'string' && typeof mv.to === 'string', `${label}: move shape`);
  const chess = new Chess(fen);
  const applied = chess.move({ from: mv.from, to: mv.to, promotion: mv.promotion || 'q' });
  assert.ok(applied, `${label}: ${mv.from}${mv.to} must be legal`);
  return applied;
}

test('LEVELS exposes exactly the documented difficulties', () => {
  assert.deepEqual([...LEVELS], ['easy', 'medium', 'hard']);
});

for (const level of LEVELS) {
  test(`${level}: returns a legal move from the start position`, () => {
    for (let i = 0; i < 5; i++) {
      const mv = chooseMove({ fen: START, level, timeBudgetMs: 1000 });
      assertLegal(START, mv, level);
      assert.equal(mv.level, level);
    }
  });
}

test('all levels return null on a terminal position (no legal moves)', () => {
  // Fool's mate, white to move and checkmated.
  const mated = new Chess();
  for (const san of ['f3', 'e5', 'g4', 'Qh4#']) mated.move(san);
  assert.equal(mated.moves().length, 0);
  for (const level of LEVELS) {
    assert.equal(chooseMove({ fen: mated.fen(), level }), null, `${level} on mated position`);
  }
});

test('all levels return null on stalemate', () => {
  const stale = '7k/5Q2/6K1/8/8/8/8/8 b - - 0 1';
  const chess = new Chess(stale);
  assert.equal(chess.isStalemate(), true);
  for (const level of LEVELS) {
    assert.equal(chooseMove({ fen: stale, level }), null, `${level} on stalemate`);
  }
});

test('invalid FEN returns null instead of throwing', () => {
  assert.equal(chooseMove({ fen: 'definitely not a fen', level: 'hard' }), null);
});

test('medium and hard find the forced mate in one', () => {
  const mateInOne = '6k1/5ppp/8/8/8/8/5PPP/R5K1 w - - 0 1'; // Ra8#
  for (const level of ['medium', 'hard']) {
    const mv = chooseMove({ fen: mateInOne, level, timeBudgetMs: 1200 });
    assert.ok(mv, `${level}: no move returned`);
    assert.equal(mv.from, 'a1', `${level}: wrong from-square`);
    assert.equal(mv.to, 'a8', `${level}: missed mate in one`);
    const after = new Chess(mateInOne);
    after.move({ from: mv.from, to: mv.to });
    assert.equal(after.isCheckmate(), true, `${level}: move must actually mate`);
  }
});

test('promotion moves carry a promotion piece', () => {
  const promo = '8/P6k/8/8/8/8/7K/8 w - - 0 1'; // a7 pawn + free king moves
  for (const level of LEVELS) {
    for (let i = 0; i < 5; i++) {
      const mv = chooseMove({ fen: promo, level, timeBudgetMs: 5000 });
      assertLegal(promo, mv, `${level} promotion`);
      if (mv.to === 'a8') {
        // a promotion move must name its piece — never silently under-promote
        assert.ok(['q', 'r', 'b', 'n'].includes(mv.promotion), `${level}: promotion piece`);
      } else {
        assert.equal(mv.promotion, undefined, `${level}: quiet move must not claim a promotion`);
      }
    }
  }
  // The searching levels value a queen promotion far above any quiet move, so
  // they must pick it (orderMoves also ranks promotions first, so even a
  // budget-aborted search returns one of them).
  for (const level of ['medium', 'hard']) {
    const mv = chooseMove({ fen: promo, level, timeBudgetMs: 5000 });
    assert.equal(mv.to, 'a8', `${level} must prefer the promotion`);
    assert.ok(['q', 'r', 'b', 'n'].includes(mv.promotion), `${level}: promotion piece`);
  }
});

test('evaluate is side-to-move relative and material-sensitive', () => {
  const start = new Chess();
  const base = evaluate(start);
  assert.equal(base, evaluate(start), 'deterministic');

  // White up a queen must score positive for white to move, negative for black.
  const wonForWhite = new Chess('rnb1kbnr/pppp1ppp/8/4p3/6q1/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3');
  assert.ok(evaluate(wonForWhite) < 0, 'queen down is negative for the side to move');
  const wonForBlack = new Chess('rnb1kbnr/pppp1ppp/8/4p3/6q1/5P2/PPPPP2P/RNBQKBNR b KQkq - 1 3');
  assert.ok(evaluate(wonForBlack) > 0, 'queen up is positive for the side to move');
});
