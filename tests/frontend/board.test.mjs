/**
 * Board interaction contract (tests/frontend/board.test.mjs).
 *
 * The Board is presentation-only: given a GameSession state snapshot it must
 * render 64 addressed squares, honour orientation, and mark last-move, check
 * and selection states. A recording fake DOM stands in for the WebView.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

class FakeNode {
  constructor(tag) {
    this.tag = tag;
    this.attrs = {};
    this.children = [];
    this.style = {};
    this.dataset = {};
    this.listeners = {};
  }

  get firstChild() { return this.children[0] || null; }

  setAttribute(k, v) { this.attrs[k] = String(v); }

  appendChild(c) { this.children.push(c); return c; }

  removeChild(c) { this.children = this.children.filter((x) => x !== c); return c; }

  addEventListener(t, fn) { (this.listeners[t] ||= []).push(fn); }

  removeEventListener(t, fn) {
    this.listeners[t] = (this.listeners[t] || []).filter((f) => f !== fn);
  }
}

globalThis.Node = FakeNode;
globalThis.document = {
  documentElement: { dataset: {}, style: {} },
  createElement: (t) => new FakeNode(t),
  createElementNS: (ns, t) => new FakeNode(t),
  createTextNode: (t) => Object.assign(new FakeNode('#text'), { text: String(t) }),
};
globalThis.localStorage = {
  getItem: () => null, setItem: () => {}, removeItem: () => {}, clear: () => {},
};
globalThis.window = { matchMedia: () => ({ matches: false }) };
if (typeof globalThis.navigator === 'undefined') globalThis.navigator = { userAgent: 'node-test' };

const JS_ROOT = fileURLToPath(new URL('../../app/src/main/assets/app/js/', import.meta.url));
const { Board } = await import(`file://${JS_ROOT}ui/board.js`);
const { Chess } = await import(`file://${JS_ROOT}vendor/chess.js`);

const START = new Chess().fen();

function snap(over = {}) {
  return {
    fen: START,
    turn: 'w',
    moves: [],
    canMove: true,
    lastMove: null,
    inCheck: false,
    pendingPromotion: null,
    ...over,
  };
}

function squares(board) {
  return board.el.children;
}

function cellSq(cell) {
  return cell.attrs['data-square'];
}

test('draw renders 64 addressed squares in white orientation', () => {
  const root = new FakeNode('div');
  const board = new Board({ root, onMove: () => {} });
  board.draw(snap());
  const cells = squares(board);
  assert.equal(cells.length, 64);
  assert.equal(cellSq(cells[0]), 'a8');
  assert.equal(cellSq(cells[63]), 'h1');
  const got = new Set(cells.map(cellSq));
  assert.equal(got.size, 64, 'every square exactly once');
  board.destroy();
});

test('flip reverses the orientation and re-renders', () => {
  const root = new FakeNode('div');
  const board = new Board({ root, onMove: () => {}, orientation: 'w' });
  board.draw(snap());
  assert.equal(board.flip(), 'b');
  assert.equal(cellSq(squares(board)[0]), 'h1');
  assert.equal(cellSq(squares(board)[63]), 'a8');
  assert.equal(board.flip(), 'w');
  assert.equal(cellSq(squares(board)[0]), 'a8');
  board.destroy();
});

test('last-move squares are marked, check finds the king', () => {
  const root = new FakeNode('div');
  const board = new Board({ root, onMove: () => {} });
  board.draw(snap({ lastMove: { from: 'e2', to: 'e4' } }));
  const marked = squares(board).filter((c) => (c.className || '').includes('sq--last'));
  assert.deepEqual(marked.map(cellSq).sort(), ['e2', 'e4']);

  // Fool's mate final: white mated, king on e1 in check.
  const mate = 'rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3';
  board.draw(snap({ fen: mate, inCheck: true }));
  const checks = squares(board).filter((c) => (c.className || '').includes('sq--check'));
  assert.equal(checks.length, 1);
  assert.equal(cellSq(checks[0]), 'e1');
  board.destroy();
});

test('selection marks the square; illegal selection state clears', () => {
  const root = new FakeNode('div');
  const board = new Board({ root, onMove: () => {} });
  const withE2 = snap({ moves: [{ from: 'e2', to: 'e4' }] });
  board.draw(withE2);
  board.select('e2');
  board.draw(withE2); // selection paints on the next frame, as in the UI
  let sel = squares(board).filter((c) => (c.className || '').includes('sq--selected'));
  assert.deepEqual(sel.map(cellSq), ['e2']);
  // a snapshot where e2 has no legal moves clears the stale selection
  board.draw(snap({ moves: [{ from: 'd2', to: 'd4' }] }));
  sel = squares(board).filter((c) => (c.className || '').includes('sq--selected'));
  assert.equal(sel.length, 0);
  board.destroy();
});
