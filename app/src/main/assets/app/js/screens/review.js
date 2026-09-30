/**
 * Game review — step through a stored game move by move.
 *
 * Everything on this screen derives from the stored record (IndexedDB) and
 * the vendored chess.js replay:
 *  - frames are produced by replaying the record's own SAN moves, so the
 *    positions are rules-checked while they are built;
 *  - notes are facts only (result, reason, counts, participants, time
 *    control). There is no engine here, so no move is ever labelled
 *    good/bad by guesswork.
 *
 * Distinguishing record states is deliberate: missing record, record with
 * no moves, PGN that fails to replay and a finished game are four different
 * messages — none of them pretends data exists that does not.
 */

import { el, clear } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, section, emptyState } from '../ui/components.js';
import { Board } from '../ui/board.js';
import { Chess } from '../vendor/chess.js';
import { parsePgn, buildPgn } from '../chess/pgn.js';
import { OUTCOME_REASONS } from '../chess/game.js';
import { dbGet } from '../db.js';
import { go } from '../router.js';

const PLAY_MS = 1100;

export function reviewScreen(ctx = {}) {
  const id = ctx.params ? ctx.params.id : '';

  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });
  root.appendChild(topbar({
    title: 'Game review',
    onBack: () => (ctx.router && typeof ctx.router.back === 'function' ? ctx.router.back() : go('/')),
  }));
  root.appendChild(scroll);

  let cancelled = false;
  let playTimer = null;
  let board = null;
  let frames = [];
  let sans = [];
  let pos = 0;
  let playing = false;

  const holder = el('div', {});
  scroll.appendChild(holder);

  // ------------------------------------------------------------- helpers
  function failState({ iconName, title, hint, action }) {
    clear(holder);
    holder.appendChild(emptyState({ iconName, title, hint, action: action || null }));
  }

  function backToList() {
    return el('button', {
      class: 'btn btn--secondary mt-2',
      type: 'button',
      onClick: () => go('/'),
    }, icon('history', 18), el('span', { text: 'Back home' }));
  }

  // ---------------------------------------------------------------- load
  async function load() {
    clear(holder);
    holder.appendChild(emptyState({ iconName: 'history', title: 'Loading game…', hint: '' }));

    let record = null;
    try {
      record = await dbGet('games', id);
    } catch {
      if (!cancelled) failState({
        iconName: 'info',
        title: 'Could not read local storage',
        hint: 'The review data lives on this device; storage refused the read.',
        action: backToList(),
      });
      return;
    }
    if (cancelled) return;

    if (!record || typeof record !== 'object') {
      failState({
        iconName: 'info',
        title: 'Game not found',
        hint: `No game with id “${id}” is stored on this device. It may have been deleted from the history.`,
        action: backToList(),
      });
      return;
    }

    // ------------------------------------------------- build a PGN source
    let pgnText = typeof record.pgn === 'string' && record.pgn.trim() ? record.pgn : '';
    let rebuilt = false;
    if (!pgnText && Array.isArray(record.sans) && record.sans.length) {
      try {
        pgnText = buildPgn({
          moves: record.sans,
          fen: record.startFen || '',
          white: record.white || 'White',
          black: record.black || 'Black',
          result: record.result || '*',
          timeControl: record.control && record.control.initialSec
            ? `${record.control.initialSec}+${record.control.incrementSec || 0}`
            : '',
          date: record.startedAt ? new Date(record.startedAt) : undefined,
        });
        rebuilt = true;
      } catch {
        pgnText = '';
      }
    }

    if (!pgnText) {
      failState({
        iconName: 'info',
        title: 'No moves recorded',
        hint: Array.isArray(record.sans) && record.sans.length === 0
          ? 'This game ended before the first move was played, so there is nothing to replay.'
          : 'This record carries no move list, so there is nothing to replay. Nothing was invented to fill it.',
        action: backToList(),
      });
      return;
    }

    let parsed = null;
    try {
      parsed = parsePgn(pgnText);
    } catch {
      parsed = null;
    }
    if (!parsed || !parsed.moves.length) {
      failState({
        iconName: 'info',
        title: 'Could not replay this game',
        hint: 'The stored PGN did not contain a replayable move list.',
        action: backToList(),
      });
      return;
    }

    // ------------------------------------------------ replay into frames
    const chess = parsed.startFen ? new Chess(parsed.startFen) : new Chess();
    frames = [{
      fen: chess.fen(),
      turn: chess.turn(),
      inCheck: chess.inCheck(),
      last: null,
    }];
    sans = [];
    let captures = 0;
    let checks = 0;
    let replayBrokenAt = -1;

    for (let i = 0; i < parsed.moves.length; i++) {
      let mv = null;
      try {
        mv = chess.move(parsed.moves[i]);
      } catch {
        mv = null;
      }
      if (!mv) { replayBrokenAt = i; break; }
      sans.push(mv.san);
      if (mv.captured) captures += 1;
      if (mv.san.endsWith('+') || mv.san.endsWith('#')) checks += 1;
      frames.push({
        fen: chess.fen(),
        turn: chess.turn(),
        inCheck: chess.inCheck(),
        last: { from: mv.from, to: mv.to },
      });
    }

    if (replayBrokenAt === 0 || frames.length <= 1) {
      failState({
        iconName: 'info',
        title: 'Could not replay this game',
        hint: 'The first stored move is not legal from the recorded start position — the record is inconsistent and was not partially drawn.',
        action: backToList(),
      });
      return;
    }

    paint({ record, parsed, rebuilt, replayBrokenAt, captures, checks });
  }

  // -------------------------------------------------------------- render
  function paint({ record, parsed, rebuilt, replayBrokenAt, captures, checks }) {
    clear(holder);

    pos = frames.length - 1;   // open at the final position

    // board
    const boardWrap = el('div', { class: 'board-wrap' });
    holder.appendChild(boardWrap);
    board = new Board({
      root: boardWrap,
      interactive: false,
      orientation: record.humanSide === 'b' ? 'b' : 'w',
      onMove: () => {},
    });

    // transport strip
    const strip = el('div', { class: 'review-strip' });
    // first/last are arrows, prev/next are chevrons — visually distinct pairs
    const btnFirst = el('button', { class: 'btn btn--icon', type: 'button', 'aria-label': 'First position', onClick: () => { stopPlay(); jump(0); } }, icon('back', 18));
    const btnPrev = el('button', { class: 'btn btn--icon', type: 'button', 'aria-label': 'Previous move', onClick: () => { stopPlay(); jump(pos - 1); } }, icon('chevronDown', 18));
    const btnPlay = el('button', { class: 'btn btn--icon', type: 'button', 'aria-label': 'Auto-play', onClick: () => togglePlay() }, icon('play', 18));
    const btnNext = el('button', { class: 'btn btn--icon', type: 'button', 'aria-label': 'Next move', onClick: () => { stopPlay(); jump(pos + 1); } }, icon('chevron', 18));
    const btnLast = el('button', { class: 'btn btn--icon', type: 'button', 'aria-label': 'Final position', onClick: () => { stopPlay(); jump(frames.length - 1); } }, icon('back', 18));
    btnPrev.style.transform = 'rotate(90deg)';    // down chevron -> points left
    btnLast.style.transform = 'rotate(180deg)';   // back arrow  -> points right
    const posLabel = el('span', { class: 'review-strip__pos', text: '' });
    strip.append(btnFirst, btnPrev, btnPlay, btnNext, btnLast, posLabel);
    holder.appendChild(strip);

    // move sequence
    const seqWrap = el('div', { class: 'san-seq mt-2' });
    holder.appendChild(seqWrap);
    const seqCells = [];
    {
      let moveNo = 1;
      sans.forEach((san, i) => {
        const ply = i + 1;              // frames index of this move
        if (i % 2 === 0) {
          seqWrap.appendChild(el('span', { text: `${moveNo}. ` }));
          moveNo += 1;
        }
        const cell = el('b', { text: san, style: { cursor: 'pointer' } });
        cell.addEventListener('click', () => { stopPlay(); jump(ply); });
        seqWrap.appendChild(cell);
        seqWrap.appendChild(el('span', { text: ' ' }));
        seqCells.push({ ply, cell });
      });
    }

    // factual notes — no engine verdicts, ever
    const notes = el('div', { class: 'mt-3' });
    holder.appendChild(notes);

    const finished = record.status === 'finished' || Boolean(record.reason);
    const reasonLabel = record.reason
      ? (OUTCOME_REASONS[record.reason] || record.reason)
      : (parsed.termination || '');
    const tone = !finished ? 'warn'
      : record.reason === 'abort' ? 'warn'
        : 'good';
    notes.appendChild(el('div', { class: `analysis-note analysis-note--${tone}` },
      icon(finished ? 'flag' : 'clock', 16),
      el('div', {},
        el('b', { text: finished ? `${record.result || parsed.result}` : 'Unfinished game' }),
        el('span', {
          text: finished
            ? (reasonLabel ? ` — ${reasonLabel.toLowerCase()}` : '')
            : ` — reviewing ${frames.length - 1} plies; no result was recorded.`,
        }),
      ),
    ));

    notes.appendChild(el('div', { class: 'analysis-note mt-2' },
      icon('gauge', 16),
      el('div', { text: `${sans.length} plies · ${captures} capture${captures === 1 ? '' : 's'} · ${checks} check${checks === 1 ? '' : 's'} · final position ${frames[frames.length - 1].turn === 'w' ? 'White' : 'Black'} to move` }),
    ));

    const tc = record.control && record.control.initialSec
      ? `${record.control.initialSec}+${record.control.incrementSec || 0}`
      : ((parsed.headers && parsed.headers.TimeControl) || '');
    const modeLabel = record.mode
      ? `${record.mode}${record.mode === 'bot' && record.level ? ` · ${record.level}` : ''}`
      : '';
    const facts = [
      `${parsed.white} vs ${parsed.black}`,
      modeLabel,
      tc ? `time control ${tc}` : '',
    ].filter(Boolean).join(' · ');
    notes.appendChild(el('div', { class: 'analysis-note mt-2' },
      icon('info', 16),
      el('div', { text: facts }),
    ));

    if (replayBrokenAt >= 0) {
      notes.appendChild(el('div', { class: 'analysis-note analysis-note--warn mt-2' },
        icon('info', 16),
        el('div', {
          text: `Replay stopped at ply ${replayBrokenAt + 1}: the stored history continues but move ${replayBrokenAt + 1} is not legal here. ${sans.length} plies are shown.`,
        }),
      ));
    }
    if (rebuilt) {
      notes.appendChild(el('div', { class: 'analysis-note mt-2' },
        icon('info', 16),
        el('div', { text: 'This record had no PGN yet; the move list was rebuilt from the stored SAN moves for review.' }),
      ));
    }

    holder.appendChild(section('Moves', el('span', { class: 'tiny muted', text: 'tap a move to jump' })));

    function jump(next) {
      if (!frames.length) return;
      pos = Math.max(0, Math.min(frames.length - 1, next));
      render();
    }

    function togglePlay() {
      if (playing) { stopPlay(); return; }
      if (pos >= frames.length - 1) pos = 0;
      playing = true;
      btnPlay.setAttribute('aria-label', 'Stop auto-play');
      clear(btnPlay);
      btnPlay.appendChild(icon('close', 18));
      playTimer = setInterval(() => {
        if (pos >= frames.length - 1) { stopPlay(); return; }
        pos += 1;
        render();
      }, PLAY_MS);
      render();
    }

    function stopPlay() {
      if (playTimer !== null) { clearInterval(playTimer); playTimer = null; }
      if (playing) {
        playing = false;
        btnPlay.setAttribute('aria-label', 'Auto-play');
        clear(btnPlay);
        btnPlay.appendChild(icon('play', 18));
      }
    }

    function render() {
      const f = frames[pos];
      board.draw({
        fen: f.fen,
        turn: f.turn,
        moves: [],
        canMove: false,
        lastMove: f.last,
        inCheck: f.inCheck,
        pendingPromotion: null,
      });
      posLabel.textContent = `${pos} / ${frames.length - 1}`;
      for (const { ply, cell } of seqCells) {
        cell.style.background = ply === pos ? 'rgba(129, 182, 76, .2)' : '';
        cell.style.color = ply === pos ? 'var(--text-primary)' : '';
      }
      btnPrev.disabled = pos <= 0;
      btnFirst.disabled = pos <= 0;
      btnNext.disabled = pos >= frames.length - 1;
      btnLast.disabled = pos >= frames.length - 1;
    }

    render();
    stopPlay();
  }

  load();

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => {
      cancelled = true;
      if (playTimer !== null) { clearInterval(playTimer); playTimer = null; }
      if (board) board.destroy();
    },
  };
}
