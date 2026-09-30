/**
 * Original chess piece artwork — hand-built flat vector shapes (45x45 grid).
 *
 * Three visual themes share the same geometry:
 *   classic  — light/dark fills with a defining outline (default)
 *   outline  — stroke-only engraving style
 *   solid    — flat silhouette, no outline
 *
 * Licensing: designed for Checkmate, no third-party piece artwork bundled.
 */
import { svgEl } from '../ui/dom.js';

const BASE =
  'M13.2,37.6 h18.6 a1.6,1.6 0 0 0 1.6,-1.6 v-0.9 a2.1,2.1 0 0 0 -2.1,-2.1 ' +
  'h-17.6 a2.1,2.1 0 0 0 -2.1,2.1 v0.9 a1.6,1.6 0 0 0 1.6,1.6 z';
const COLLAR = 'M15.9,30.9 h13.2 a1,1 0 0 1 1,1 v1.1 h-15.2 v-1.1 a1,1 0 0 1 1,-1 z';

export const SHAPES = {
  p: {
    body: [
      // single continuous silhouette: the ball head is merged into the neck
      // (a separate head circle floated detached from the body on device).
      'M18.6,31.2 C19.4,26.5 20.0,22.8 20.6,19.6 A5.5,5.5 0 1 1 24.4,19.6 ' +
        'C25.0,22.8 25.6,26.5 26.4,31.2 Z',
      COLLAR,
      BASE,
    ],
    dots: [],
  },
  r: {
    body: [
      'M12,9.6 h4.5 v3.6 h3 V9.6 h6 v3.6 h3 V9.6 H33 v7.4 H12 z',
      'M15.4,17 h14.2 l-1.5,3.6 H16.9 z',
      'M17.5,20.6 h10 l-0.7,10.3 H18.2 z',
      COLLAR,
      BASE,
    ],
    dots: [],
  },
  n: {
    body: [
      // stylised horse head, original silhouette
      'M13.6,33.4 c0,-4.7 0.9,-7.9 3.6,-10.7 c1.7,-1.8 2.5,-3.3 2.3,-5.1 ' +
        'c-0.2,-1.9 0.7,-3.3 2.5,-4.2 l1.5,-4.6 l3.1,1.7 c4.8,1.5 7.9,4.6 9.3,8.9 ' +
        'c0.9,2.8 1.4,5.6 1.4,8.7 v5.3 z',
      COLLAR,
      BASE,
    ],
    dots: [{ cx: 19.4, cy: 16.4, r: 1.15, contrast: true }],
  },
  b: {
    body: [
      'M22.5,10.2 c-5.6,3.1 -8.7,7.8 -8.7,12.5 c0,3.3 1.7,5.6 4.2,6.8 h9 ' +
        'c2.5,-1.2 4.2,-3.5 4.2,-6.8 c0,-4.7 -3.1,-9.4 -8.7,-12.5 z',
      COLLAR,
      BASE,
    ],
    dots: [{ cx: 22.5, cy: 17.6, r: 0 }, { cx: 22.5, cy: 24.5, r: 0 }],
    lines: [{ d: 'M22.5,15.6 l3.6,-3.6', strokeKey: 'detail' }],
    finial: { cx: 22.5, cy: 7.4, r: 2.5 },
  },
  q: {
    body: [
      'M11,11.4 L13.9,19.4 L16.75,11.9 L19.6,19.4 L22.5,11 L25.4,19.4 ' +
        'L28.25,11.9 L31.1,19.4 L34,11.4 L30.6,27 H14.4 Z',
      'M14.4,27 H30.6 L29.6,31.9 H15.4 Z',
      COLLAR,
      BASE,
    ],
    dots: [
      { cx: 11, cy: 9.7, r: 2.1 },
      { cx: 16.75, cy: 10.2, r: 2.1 },
      { cx: 22.5, cy: 9.3, r: 2.1 },
      { cx: 28.25, cy: 10.2, r: 2.1 },
      { cx: 34, cy: 9.7, r: 2.1 },
    ],
  },
  k: {
    body: [
      // cross
      'M21.3,4.4 h2.4 v2.7 h2.7 v2.4 h-2.7 v2.7 h-2.4 v-2.7 h-2.7 V7.1 h2.7 z',
      'M11.6,26.7 L14,14.2 c1.1,-4.6 4.6,-7.7 8.5,-7.7 s7.4,3.1 8.5,7.7 l2.4,12.5 z',
      'M14.4,26.7 H30.6 L29.6,31.9 H15.4 Z',
      COLLAR,
      BASE,
    ],
    dots: [],   // no detached side blobs — the dome + cross read as a king alone
  },
};

const FILES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

export const PIECE_NAMES = { p: 'Pawn', r: 'Rook', n: 'Knight', b: 'Bishop', q: 'Queen', k: 'King' };

/**
 * Build an <svg> element for one piece.
 * @param {'p'|'r'|'n'|'b'|'q'|'k'} type
 * @param {'w'|'b'} color
 * @param {'classic'|'outline'|'solid'} theme
 */
export function pieceSvg(type, color, theme = 'classic') {
  const resolved = SHAPES[type] ? type : 'p';
  const shape = SHAPES[resolved];
  const white = color === 'w';

  const palette =
    theme === 'outline'
      ? { fill: 'none', stroke: white ? '#14140f' : '#f4f4ea', detail: white ? '#14140f' : '#f4f4ea' }
      : theme === 'solid'
        ? { fill: white ? '#fbfbf5' : '#22221d', stroke: 'none', detail: white ? '#14140f' : '#f4f4ea' }
        : {
            fill: white ? '#fdfdf8' : '#232320',
            stroke: '#14140f',
            detail: white ? '#14140f' : '#f0f0e6',
          };

  const svg = svgEl('svg', {
    viewBox: '0 0 45 45',
    role: 'img',
    'aria-label': `${white ? 'White' : 'Black'} ${PIECE_NAMES[resolved]}`,
  });

  const g = svgEl('g', {
    fill: palette.fill,
    stroke: palette.stroke === 'none' ? 'none' : palette.stroke,
    'stroke-width': theme === 'classic' ? 1.15 : 1.6,
    'stroke-linejoin': 'round',
    'stroke-linecap': 'round',
  });

  for (const d of shape.body) g.appendChild(svgEl('path', { d }));

  if (shape.finial) g.appendChild(svgEl('circle', { ...shape.finial }));

  for (const dot of shape.dots || []) {
    if (!dot.r) continue;
    g.appendChild(
      svgEl('circle', {
        cx: dot.cx,
        cy: dot.cy,
        r: dot.r,
        fill: dot.contrast ? palette.detail : palette.fill,
        stroke: dot.contrast ? 'none' : palette.stroke,
      }),
    );
  }

  for (const line of shape.lines || []) {
    g.appendChild(
      svgEl('path', {
        d: line.d,
        fill: 'none',
        stroke: palette.detail,
        'stroke-width': theme === 'outline' ? 1.1 : 1.4,
      }),
    );
  }

  svg.appendChild(g);
  return svg;
}

/** UCI piece letter from FEN char, e.g. 'Q' -> 'q' */
export function fenToType(ch) {
  const c = String(ch).toLowerCase();
  return 'prnbqk'.includes(c) ? c : 'p';
}

export function squareName(file, rank) {
  return `${FILES[file]}${rank}`;
}

export function squareToCoords(square) {
  const file = FILES.indexOf(square[0]);
  const rank = Number(square[1]);
  return { file, rank };
}

export { FILES };
