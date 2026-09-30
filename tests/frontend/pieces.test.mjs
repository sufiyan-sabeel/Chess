/**
 * Piece artwork contract (tests/frontend/pieces.test.mjs).
 *
 * The pieces are original flat SVG halfway between icon and Staunton. This
 * suite pins two properties with a recording fake DOM:
 *  1. well-formedness: every type × colour × theme yields a 45×45 svg whose
 *     paths are non-empty and whose circles have positive radius inside the
 *     grid (an r:0 or off-grid dot renders as a blob or not at all);
 *  2. regressions from on-device review (2026-09-30):
 *     - the pawn's ball head is merged into one continuous silhouette path
 *       (a separate head circle floated detached from the body);
 *     - the king carries no detached side dots (they rendered as ears).
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

class FakeNode {
  constructor(tag) {
    this.tag = tag;
    this.attrs = {};
    this.children = [];
  }

  setAttribute(k, v) { this.attrs[k] = String(v); }

  appendChild(c) { this.children.push(c); return c; }
}

globalThis.Node = FakeNode;
globalThis.document = {
  createElement: (t) => new FakeNode(t),
  createElementNS: (ns, t) => new FakeNode(t),
  createTextNode: (t) => Object.assign(new FakeNode('#text'), { text: String(t) }),
};

const JS_ROOT = fileURLToPath(new URL('../../app/src/main/assets/app/js/', import.meta.url));
const { pieceSvg, SHAPES, PIECE_NAMES } = await import(`file://${JS_ROOT}chess/pieces.js`);

const TYPES = Object.keys(PIECE_NAMES);
const THEMES = ['classic', 'outline', 'solid'];

function circles(svg) {
  const out = [];
  const walk = (n) => {
    if (n.tag === 'circle') out.push(n);
    for (const c of n.children) walk(c);
  };
  walk(svg);
  return out;
}

function paths(svg) {
  const out = [];
  const walk = (n) => {
    if (n.tag === 'path' && n.attrs.d) out.push(n);
    for (const c of n.children) walk(c);
  };
  walk(svg);
  return out;
}

test('every piece renders a well-formed 45x45 svg in all themes', () => {
  for (const type of TYPES) {
    for (const color of ['w', 'b']) {
      for (const theme of THEMES) {
        const svg = pieceSvg(type, color, theme);
        assert.equal(svg.tag, 'svg', `${type}/${color}/${theme} root`);
        assert.equal(svg.attrs.viewBox, '0 0 45 45', `${type}/${color}/${theme} grid`);
        assert.ok(paths(svg).length >= 3, `${type}/${color}/${theme} has body paths`);
        for (const c of circles(svg)) {
          const r = Number(c.attrs.r);
          assert.ok(r > 0, `${type}/${color}/${theme} circle radius positive (got ${c.attrs.r})`);
          for (const k of ['cx', 'cy']) {
            const v = Number(c.attrs[k]);
            assert.ok(v >= 0 && v <= 45, `${type}/${color}/${theme} circle ${k}=${v} inside grid`);
          }
        }
      }
    }
  }
});

test('pawn head is one silhouette with the body (no floating ball)', () => {
  assert.ok(!((SHAPES.p.dots || []).length), 'pawn has no separate head dot');
  assert.ok(/A/i.test(SHAPES.p.body[0]), 'pawn silhouette arcs over its own head');
});

test('king has no detached side dots', () => {
  assert.ok(!((SHAPES.k.dots || []).length), 'king carries no dots at all');
});

test('unknown piece letter falls back to the pawn', () => {
  const svg = pieceSvg('x', 'w', 'classic');
  assert.equal(svg.attrs['aria-label'], 'White Pawn');
});
