/**
 * Board renderer + pointer input.
 *
 * Pure view: it never mutates game state. It reads a snapshot from the
 * controller (see `GameSession.state()`), draws it, and reports attempted
 * moves through `onMove({from,to})`.
 *
 * Interaction supports both tap-tap and drag, because both are expected on a
 * phone. Promotion is offered by the controller (`needsPromotion`) and drawn
 * here as an overlay.
 */

import { el, clear, svgEl } from './dom.js';
import { pieceSvg, FILES } from '../chess/pieces.js';

const RANKS = [8, 7, 6, 5, 4, 3, 2, 1];

export class Board {
  /**
   * @param {{
   *   root: HTMLElement,            // container (board-wrap)
   *   onMove: (m:{from:string,to:string})=>void,
   *   onPromotion?: (piece:'q'|'r'|'b'|'n')=>void,
   *   onCancelPromotion?: ()=>void,
   *   orientation?: 'w'|'b',
   *   interactive?: boolean,
   * }} opts
   */
  constructor({ root, onMove, onPromotion, onCancelPromotion, orientation = 'w', interactive = true }) {
    this.root = root;
    this.onMove = onMove;
    this.onPromotion = onPromotion || (() => {});
    this.onCancelPromotion = onCancelPromotion || (() => {});
    this.orientation = orientation;
    this.interactive = interactive;

    this.selected = null;
    this.targets = new Set();
    this.state = null;
    this.promo = null;
    this.drag = null;

    this.el = el('div', { class: 'board', role: 'grid', 'aria-label': 'Chessboard' });
    clear(this.root);
    this.root.appendChild(this.el);

    this.onPointerDown = this.onPointerDown.bind(this);
    this.onPointerMove = this.onPointerMove.bind(this);
    this.onPointerUp = this.onPointerUp.bind(this);
    this.el.addEventListener('pointerdown', this.onPointerDown);
    this.el.addEventListener('pointermove', this.onPointerMove);
    this.el.addEventListener('pointerup', this.onPointerUp);
    this.el.addEventListener('pointercancel', () => this.endDrag(null));
  }

  destroy() {
    this.el.removeEventListener('pointerdown', this.onPointerDown);
    this.el.removeEventListener('pointermove', this.onPointerMove);
    this.el.removeEventListener('pointerup', this.onPointerUp);
    clear(this.root);
  }

  flip() {
    this.orientation = this.orientation === 'w' ? 'b' : 'w';
    if (this.state) this.draw(this.state);
    return this.orientation;
  }

  // --------------------------------------------------------------- drawing

  /** @param {object} state snapshot from GameSession.state() */
  draw(state) {
    this.state = state;
    const board = boardFromFen(state.fen);
    const last = state.lastMove;
    const checkSquare = state.inCheck ? kingSquare(board, state.turn) : null;

    // selection survives only while it is still our turn and legal
    if (this.selected && !state.canMove) this.clearSelection();
    if (this.selected && !state.moves.some((m) => m.from === this.selected)) this.clearSelection();

    clear(this.el);

    const order = this.orientation === 'w' ? RANKS : [...RANKS].reverse();
    const files = this.orientation === 'w' ? FILES : [...FILES].reverse();

    for (const rank of order) {
      for (const file of files) {
        const square = `${file}${rank}`;
        const light = (FILES.indexOf(file) + rank) % 2 === 1;
        const piece = board[square];
        const classes = ['sq', light ? 'sq--light' : 'sq--dark'];
        if (last && (last.from === square || last.to === square)) classes.push('sq--last');
        if (this.selected === square) classes.push('sq--selected');
        if (checkSquare === square) classes.push('sq--check');
        if (this.targets.has(square)) classes.push(piece ? 'sq--target' : 'sq--hint');

        const cell = el('div', {
          class: classes.join(' '),
          'data-square': square,
          role: 'gridcell',
          'aria-label': ariaFor(square, piece),
        });

        if (piece) {
          cell.appendChild(el('div', { class: 'sq__piece' }, pieceSvg(piece.type, piece.color, pieceTheme())));
        }

        if (coordsEnabled()) {
          const showFile = this.orientation === 'w' ? rank === 1 : rank === 8;
          const showRank = this.orientation === 'w' ? file === 'a' : file === 'h';
          if (showFile) cell.appendChild(el('span', { class: 'sq__coord sq__coord--file', text: file }));
          if (showRank) cell.appendChild(el('span', { class: 'sq__coord sq__coord--rank', text: String(rank) }));
        }

        this.el.appendChild(cell);
      }
    }

    if (state.pendingPromotion) this.drawPromotion(state.pendingPromotion);
    else if (this.promo) this.clearPromotion();
  }

  drawPromotion({ from, to }) {
    this.clearPromotion();
    const color = this.state ? this.state.turn : 'w';
    const wrap = el('div', { class: 'promo', role: 'dialog', 'aria-label': 'Choose promotion piece' });
    const list = el('div', { class: 'promo__list' });
    for (const p of ['q', 'r', 'b', 'n']) {
      const btn = el(
        'button',
        {
          class: 'promo__opt',
          type: 'button',
          'aria-label': `Promote to ${PIECE_LABEL[p]}`,
          onClick: () => { this.clearPromotion(); this.onPromotion(p); },
        },
        pieceSvg(p, color, pieceTheme()),
      );
      list.appendChild(btn);
    }
    list.appendChild(
      el('button', {
        class: 'promo__cancel',
        type: 'button',
        text: 'Cancel',
        onClick: () => { this.clearPromotion(); this.onCancelPromotion(); },
      }),
    );
    wrap.appendChild(list);
    this.root.appendChild(wrap);
    this.promo = wrap;
  }

  clearPromotion() {
    if (this.promo && this.promo.parentNode) this.promo.parentNode.removeChild(this.promo);
    this.promo = null;
  }

  // ---------------------------------------------------------------- input

  squareFromEvent(ev) {
    const node = document.elementFromPoint(ev.clientX, ev.clientY);
    if (!node) return null;
    const cell = node.closest ? node.closest('.sq') : null;
    return cell ? cell.dataset.square : null;
  }

  onPointerDown(ev) {
    if (!this.interactive || !this.state || !this.state.canMove) return;
    const square = this.squareFromEvent(ev);
    if (!square) return;
    const piece = this.pieceOn(square);

    const isTarget = this.selected !== null && this.targets.has(square);
    if (!isTarget && (!piece || piece.color !== this.state.turn)) {
      // tapping a square we cannot act on simply clears the selection
      this.clearSelection();
      this.redraw();
      return;
    }

    // begin a potential drag (tap completion is decided on pointerup).
    // When the press lands on a legal target of the current selection the
    // origin is the selected square, not the target itself.
    const origin = isTarget && this.selected ? this.selected : square;
    this.drag = { from: origin, startX: ev.clientX, startY: ev.clientY, moved: false, ghost: null };
    try { this.el.setPointerCapture(ev.pointerId); } catch { /* optional */ }
  }

  onPointerMove(ev) {
    if (!this.drag) return;
    const dx = ev.clientX - this.drag.startX;
    const dy = ev.clientY - this.drag.startY;
    if (!this.drag.moved && Math.hypot(dx, dy) < 8) return;

    if (!this.drag.moved) {
      this.drag.moved = true;
      this.select(this.drag.from);
      const cell = this.cellAt(this.drag.from);
      if (cell) cell.classList.add('sq--dragging');
      // floating piece that follows the finger
      const piece = this.pieceOn(this.drag.from);
      if (piece) {
        const ghost = el('div', { class: 'sq__piece', style: { position: 'fixed', width: '48px', height: '48px', zIndex: '60', pointerEvents: 'none' } },
          pieceSvg(piece.type, piece.color, pieceTheme()));
        ghost.style.left = `${ev.clientX - 24}px`;
        ghost.style.top = `${ev.clientY - 24}px`;
        document.body.appendChild(ghost);
        this.drag.ghost = ghost;
      }
    }
    if (this.drag.ghost) {
      this.drag.ghost.style.left = `${ev.clientX - 24}px`;
      this.drag.ghost.style.top = `${ev.clientY - 24}px`;
    }
    ev.preventDefault();
  }

  onPointerUp(ev) {
    if (!this.drag) return;
    const drag = this.drag;
    const target = this.squareFromEvent(ev);
    this.endDrag(null);

    if (drag.moved) {
      if (target && target !== drag.from && this.targets.has(target)) {
        this.attempt(drag.from, target);
      } else {
        this.clearSelection();
        this.redraw();
      }
      return;
    }

    // plain tap
    if (this.selected && this.targets.has(target)) {
      this.attempt(this.selected, target);
      return;
    }
    const piece = this.pieceOn(target);
    if (piece && piece.color === this.state.turn) {
      this.select(target);
    } else {
      this.clearSelection();
    }
    this.redraw();
  }

  endDrag() {
    if (this.drag) {
      if (this.drag.ghost && this.drag.ghost.parentNode) this.drag.ghost.parentNode.removeChild(this.drag.ghost);
      const cell = this.cellAt(this.drag.from);
      if (cell) cell.classList.remove('sq--dragging');
    }
    this.drag = null;
  }

  select(square) {
    if (!this.state) return;
    this.selected = square;
    this.targets = new Set(this.state.moves.filter((m) => m.from === square).map((m) => m.to));
  }

  clearSelection() {
    this.selected = null;
    this.targets = new Set();
  }

  attempt(from, to) {
    this.clearSelection();
    this.onMove({ from, to });
    this.redraw();
  }

  // --------------------------------------------------------------- helpers

  redraw() {
    if (this.state) this.draw(this.state);
  }

  cellAt(square) {
    return this.el.querySelector(`[data-square="${square}"]`);
  }

  pieceOn(square) {
    if (!this.state) return null;
    return pieceAtFen(boardFromFen(this.state.fen), square);
  }
}

const PIECE_LABEL = { q: 'Queen', r: 'Rook', b: 'Bishop', n: 'Knight' };

function pieceTheme() {
  const t = document.documentElement.dataset.pieceTheme;
  return t === 'outline' || t === 'solid' ? t : 'classic';
}

/** Coordinate labels honour the `coords` setting mirrored onto <html>. */
function coordsEnabled() {
  return document.documentElement.dataset.coords !== '0';
}

/**
 * FEN board part -> `{ a1: {type,color}, ... }`.
 * Pure string parsing: no chess.js dependency, cheap enough for every frame.
 */
export function boardFromFen(fen) {
  const out = {};
  const rows = String(fen || '').split(' ')[0].split('/');
  if (rows.length !== 8) return out;
  for (let r = 0; r < 8; r++) {
    const rank = 8 - r;
    let file = 0;
    for (const ch of rows[r]) {
      if (ch >= '1' && ch <= '8') {
        file += Number(ch);
      } else {
        const color = ch === ch.toUpperCase() ? 'w' : 'b';
        const type = ch.toLowerCase();
        if ('prnbqk'.includes(type)) out[`${FILES[file]}${rank}`] = { type, color };
        file += 1;
      }
    }
  }
  return out;
}

function pieceAtFen(board, square) {
  return board[square] || null;
}

function kingSquare(board, color) {
  for (const [square, piece] of Object.entries(board)) {
    if (piece.type === 'k' && piece.color === color) return square;
  }
  return null;
}

function ariaFor(square, piece) {
  if (!piece) return square;
  return `${square}: ${piece.color === 'w' ? 'white' : 'black'} ${PIECE_LABEL[piece.type] || 'piece'}`;
}

/** Exposed for tests: build the 64 cells without pointer wiring. */
export function squaresFromFen(fen, orientation = 'w') {
  const board = boardFromFen(fen);
  const order = orientation === 'w' ? RANKS : [...RANKS].reverse();
  const files = orientation === 'w' ? FILES : [...FILES].reverse();
  const cells = [];
  for (const rank of order) {
    for (const file of files) {
      const square = `${file}${rank}`;
      cells.push({ square, piece: board[square] || null });
    }
  }
  return cells;
}

export { svgEl };
