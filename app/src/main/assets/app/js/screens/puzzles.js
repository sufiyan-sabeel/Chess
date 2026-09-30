/**
 * Puzzles — bundled tactical positions, fully playable offline.
 *
 * List screen  : filterable rows with per-puzzle difficulty and progress
 *                read from IndexedDB (`puzzle_progress`).
 * Detail screen: an interactive board driven by the vendored chess.js.
 *
 * Honesty rules enforced here:
 *  - a wrong move is NEVER applied to the board — the position stays legal
 *    and the feedback says the move was wrong;
 *  - `solution` lists every mating move (see data/puzzles.js), so accepting
 *    any of them is correct by construction;
 *  - progress is written from real attempts only; nothing is pre-filled;
 *  - if the bundled data ever disagrees with chess.js at runtime, the screen
 *    says so instead of pretending the puzzle was solved.
 */

import { el, clear } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, section, emptyState } from '../ui/components.js';
import { Board } from '../ui/board.js';
import { Chess } from '../vendor/chess.js';
import { PUZZLES } from '../data/puzzles.js';
import { dbGet, dbPut } from '../db.js';
import { playSound, haptic } from '../store.js';
import { go } from '../router.js';

const KIND_LABEL = { mate1: 'Mate in 1', mate2: 'Mate in 2' };
const FILTERS = [
  { id: 'all', label: 'All' },
  { id: 'todo', label: 'To solve' },
  { id: 'done', label: 'Solved' },
];

function diffDots(level) {
  const wrap = el('span', { class: 'puzzle-diff', 'aria-label': `Difficulty ${level} of 3` });
  for (let i = 1; i <= 3; i++) wrap.appendChild(el('i', { class: i <= level ? 'on' : '' }));
  return wrap;
}

async function loadProgress(id) {
  try {
    const rec = await dbGet('puzzle_progress', id);
    return rec && typeof rec === 'object' ? rec : null;
  } catch {
    return null;
  }
}

function saveProgress(id, rec) {
  return dbPut('puzzle_progress', { id, ...rec }).catch(() => {});
}

// ---------------------------------------------------------------- list view

export function puzzlesScreen() {
  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });

  let cancelled = false;
  let filter = 'all';
  let progress = new Map();   // id -> record, loaded from the device store

  root.appendChild(topbar({ title: 'Puzzles' }));
  root.appendChild(scroll);

  const status = el('div', { class: 'puzzle-status' });
  scroll.appendChild(status);

  scroll.appendChild(section('Filter', el('span', { class: 'tiny muted', text: 'bundled · works offline' })));
  const chips = FILTERS.map((f) => el('button', {
    class: 'chip',
    type: 'button',
    'aria-pressed': String(f.id === filter),
    text: f.label,
    onClick: () => {
      if (filter === f.id) return;
      filter = f.id;
      for (const [i, c] of chips.entries()) c.setAttribute('aria-pressed', String(FILTERS[i].id === filter));
      paintList();
    },
  }));
  scroll.appendChild(el('div', { class: 'chips' }, chips));

  const listHolder = el('div', { class: 'mt-4' });
  scroll.appendChild(listHolder);

  function isDone(id) {
    const rec = progress.get(id);
    return Boolean(rec && rec.solved === true);
  }

  function paintStatus() {
    const solved = PUZZLES.filter((p) => isDone(p.id)).length;
    clear(status);
    status.appendChild(el('span', {
      text: PUZZLES.length
        ? `${solved} of ${PUZZLES.length} solved on this device`
        : 'No puzzles bundled',
    }));
    status.appendChild(icon('target', 18));
  }

  function paintList() {
    clear(listHolder);
    paintStatus();

    if (!PUZZLES.length) {
      listHolder.appendChild(emptyState({
        iconName: 'puzzle',
        title: 'No puzzles bundled',
        hint: 'The puzzle dataset is empty in this build — nothing is loaded from the network to fill the gap.',
      }));
      return;
    }

    const shown = PUZZLES.filter((p) => filter === 'all'
      || (filter === 'done' && isDone(p.id))
      || (filter === 'todo' && !isDone(p.id)));

    if (!shown.length) {
      listHolder.appendChild(emptyState({
        iconName: filter === 'done' ? 'check' : 'puzzle',
        title: filter === 'done' ? 'Nothing solved yet' : 'All puzzles solved',
        hint: filter === 'done'
          ? 'Solved puzzles are marked on the spot they were finished on this device.'
          : 'Every bundled puzzle has been completed — replay any of them from the All filter.',
      }));
      return;
    }

    for (const p of shown) {
      const done = isDone(p.id);
      const rec = progress.get(p.id);
      const attempts = rec && typeof rec.attempts === 'number' ? rec.attempts : 0;
      listHolder.appendChild(el('button', {
        class: 'list__row',
        type: 'button',
        onClick: () => go(`/puzzles/${encodeURIComponent(p.id)}`),
      },
        el('span', { class: 'list__row__label' },
          el('div', { text: p.theme }),
          el('div', { class: 'tiny muted', text: `${KIND_LABEL[p.kind] || p.kind}${attempts ? ` · ${attempts} attempt${attempts === 1 ? '' : 's'}` : ''}` }),
        ),
        diffDots(p.difficulty),
        done
          ? el('span', { style: { color: 'var(--success)' } }, icon('check', 18))
          : icon('chevron', 18),
      ));
    }
  }

  async function load() {
    const ids = PUZZLES.map((p) => p.id);
    const recs = await Promise.all(ids.map(loadProgress));
    if (cancelled) return;
    progress = new Map(ids.map((id, i) => [id, recs[i]]).filter(([, r]) => r));
    paintList();
  }

  paintList();          // paint instantly from whatever is on device…
  load();               // …then refresh with stored progress

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => { cancelled = true; },
  };
}

// --------------------------------------------------------------- detail view

export function puzzleDetailScreen(ctx = {}) {
  const id = ctx.params ? ctx.params.id : '';
  const index = PUZZLES.findIndex((p) => p.id === id);
  const puzzle = index >= 0 ? PUZZLES[index] : null;

  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });
  root.appendChild(scroll);

  if (!puzzle) {
    scroll.appendChild(emptyState({
      iconName: 'info',
      title: 'Puzzle not found',
      hint: `No bundled puzzle has the id “${id}”.`,
      action: el('button', {
        class: 'btn btn--primary mt-2',
        type: 'button',
        onClick: () => go('/puzzles'),
      }, icon('back', 18), el('span', { text: 'Back to puzzles' })),
    }));
    return { el: root, keepScreenOn: false };
  }

  let cancelled = false;
  let replyTimer = null;

  // ---------------------------------------------------------- game state
  const chess = new Chess(puzzle.fen);
  const side = puzzle.turn;
  const sideName = side === 'w' ? 'White' : 'Black';
  let phase = 0;              // mate2: 0 = our move, 1 = after scripted reply
  let busy = false;           // while the scripted reply is being played
  let done = false;
  let revealed = false;
  let lastMove = null;
  let pendingPromo = null;
  let attempts = 0;
  let solved = false;
  let solvedAt = null;
  let firstSolvedAt = null;

  root.insertBefore(topbar({
    title: puzzle.theme,
    onBack: () => go('/puzzles'),
    right: el('button', {
      class: 'btn btn--icon',
      type: 'button',
      'aria-label': 'Restart puzzle',
      onClick: () => restart(),
    }, icon('refresh', 18)),
  }), scroll);

  // ------------------------------------------------------------- chrome
  const statusBar = el('div', { class: 'puzzle-status' },
    el('span', { text: `Puzzle ${index + 1} of ${PUZZLES.length} · ${KIND_LABEL[puzzle.kind] || puzzle.kind}` }),
    diffDots(puzzle.difficulty),
  );
  scroll.appendChild(statusBar);

  const boardWrap = el('div', { class: 'board-wrap mt-3' });
  scroll.appendChild(boardWrap);

  scroll.appendChild(el('div', { class: 'puzzle-goal mt-3' },
    el('b', { text: `${puzzle.theme}. ` }),
    el('span', { text: puzzle.goal }),
  ));

  const feedback = el('div', { class: 'feedback feedback--idle mt-3' });
  scroll.appendChild(feedback);

  const actions = el('div', { class: 'flex gap-2 mt-3' });
  scroll.appendChild(actions);

  function setFeedback(kind, iconName, text) {
    clear(feedback);
    feedback.className = `feedback feedback--${kind} mt-3`;
    feedback.appendChild(icon(iconName, 16));
    feedback.appendChild(el('span', { text }));
  }

  // ------------------------------------------------------------- board
  const board = new Board({
    root: boardWrap,
    orientation: side,
    onMove: (m) => attempt(m.from, m.to, null),
    onPromotion: (piece) => {
      if (!pendingPromo) return;
      const { from, to } = pendingPromo;
      pendingPromo = null;
      board.clearPromotion();
      attempt(from, to, piece);
    },
    onCancelPromotion: () => {
      pendingPromo = null;
      board.clearPromotion();
      redraw();
    },
  });

  function snap(canMove) {
    return {
      fen: chess.fen(),
      turn: chess.turn(),
      moves: chess.moves({ verbose: true }),
      canMove: Boolean(canMove) && !busy && !done,
      lastMove,
      inCheck: chess.inCheck(),
      pendingPromotion: pendingPromo,
    };
  }

  function redraw(canMove = true) {
    board.draw(snap(canMove));
  }

  // ------------------------------------------------------------ progress
  loadProgress(puzzle.id).then((rec) => {
    if (cancelled || !rec) return;
    attempts = typeof rec.attempts === 'number' ? rec.attempts : 0;
    solved = rec.solved === true;
    firstSolvedAt = typeof rec.firstSolvedAt === 'number' ? rec.firstSolvedAt : null;
    if (solved) setFeedback('ok', 'check', 'Solved before on this device — play it again to replay the mate.');
  });

  function persist() {
    return saveProgress(puzzle.id, {
      solved,
      revealed,
      attempts,
      theme: puzzle.theme,
      kind: puzzle.kind,
      lastTriedAt: Date.now(),
      // never rewrite the original solve time on replays
      firstSolvedAt: firstSolvedAt || (solved ? Date.now() : undefined),
    });
  }

  // ------------------------------------------------------------ attempts
  function attempt(from, to, promotion) {
    if (busy || done) return;

    const candidates = chess.moves({ square: from, verbose: true }).filter((m) => m.to === to);
    if (!candidates.length) {
      playSound('error');
      return;
    }
    if (candidates.some((m) => m.promotion) && !promotion) {
      pendingPromo = { from, to };
      board.draw(snap(true));
      return;
    }

    const uci = `${from}${to}${promotion || ''}`;
    const correct = puzzle.solution.includes(uci);

    if (!correct) {
      attempts += 1;
      playSound('error');
      haptic(40);
      setFeedback('bad', 'close', 'Not the forcing move — the position was left untouched. Try again.');
      persist();
      return;
    }

    // Correct move: apply it for real.
    const move = chess.move({ from, to, promotion: promotion || null });
    if (!move) {
      // Should not happen (chess.js approved the same move above) — surface it.
      setFeedback('bad', 'info', 'The bundled position disagreed with the rules engine — puzzle not marked solved.');
      return;
    }
    lastMove = { from: move.from, to: move.to };
    pendingPromo = null;
    attempts += 1;
    playSound(move.san.endsWith('#') || move.san.endsWith('+') ? 'check'
      : move.captured ? 'capture' : 'move');

    const isMate = chess.isCheckmate();
    if (puzzle.kind === 'mate1') {
      if (isMate) return finishSolved(move);
      // Every solution move is a mate by construction; anything else is data drift.
      setFeedback('bad', 'info', 'That move was in the solution list but does not mate — puzzle data rejected.');
      return;
    }

    // mate2
    if (phase === 0) {
      if (isMate) return finishSolved(move);   // solved immediately, no reply needed
      phase = 1;
      busy = true;
      setFeedback('idle', 'clock', `${sideName === 'White' ? 'Black' : 'White'} replies…`);
      redraw(false);
      replyTimer = setTimeout(playScriptedReply, 450);
      persist();
      return;
    }
    if (isMate) return finishSolved(move);
    setFeedback('bad', 'info', 'Not the mate in two — play on from this position.');
    redraw(true);
    persist();
  }

  function playScriptedReply() {
    replyTimer = null;
    if (cancelled || done) return;
    const uci = puzzle.line && puzzle.line.length ? puzzle.line[0] : null;
    let reply = null;
    if (uci) {
      try {
        reply = chess.move({ from: uci.slice(0, 2), to: uci.slice(2, 4) });
      } catch {
        reply = null;
      }
    }
    busy = false;
    if (!reply) {
      // The verify script proves these replies are legal; if that ever breaks,
      // say it rather than inventing a board state.
      setFeedback('bad', 'info', 'The scripted defence could not be played — puzzle halted, not marked solved.');
      redraw(false);
      return;
    }
    lastMove = { from: reply.from, to: reply.to };
    setFeedback('idle', 'target', `After ${reply.san} — deliver the mate. ${sideName} to move.`);
    redraw(true);
    persist();
  }

  function finishSolved(move) {
    done = true;
    solved = true;
    const san = move.san;
    playSound('success');
    haptic([25, 60, 25]);
    setFeedback('ok', 'check', `Solved: ${san} — checkmate.${puzzle.kind === 'mate2' ? ' Mate in two complete.' : ''}`);
    redraw(false);
    persist();
    paintActions();
  }

  function restart() {
    if (replyTimer !== null) {
      clearTimeout(replyTimer);
      replyTimer = null;
    }
    chess.load(puzzle.fen);
    phase = 0;
    busy = false;
    done = false;
    lastMove = null;
    pendingPromo = null;
    setFeedback('idle', 'target', `${sideName} to move — find the forcing move.`);
    redraw(true);
    paintActions();
  }

  // -------------------------------------------------------------- actions
  function showHint() {
    if (done) return;
    if (puzzle.kind === 'mate1') {
      setFeedback('idle', 'lightbulb', 'A mating move is a check — start by listing every check.');
    } else {
      setFeedback('idle', 'lightbulb', 'Look for the check that forces a specific reply, then mate.');
    }
  }

  function showSolution() {
    if (done) return;
    revealed = true;
    let san = null;
    try {
      const clone = new Chess(chess.fen());
      const uci = puzzle.solution[0];
      const mv = clone.move({ from: uci.slice(0, 2), to: uci.slice(2, 4) });
      san = mv ? mv.san : null;
    } catch {
      san = null;
    }
    if (!san) {
      setFeedback('bad', 'info', 'The solution is not legal in the current position — restart the puzzle.');
      return;
    }
    playSound('tap');
    setFeedback('idle', 'eye', `Solution: ${san}. This attempt stays unsolved — play it out to record a solve.`);
    persist();
  }

  function nextPuzzle() {
    const next = PUZZLES[(index + 1) % PUZZLES.length];
    if (next.id === puzzle.id) restart();   // single-puzzle dataset edge case
    else go(`/puzzles/${encodeURIComponent(next.id)}`);
  }

  function paintActions() {
    clear(actions);
    if (done) {
      actions.appendChild(el('button', {
        class: 'btn btn--secondary',
        type: 'button',
        onClick: () => restart(),
      }, icon('refresh', 18), el('span', { text: 'Replay' })));
      actions.appendChild(el('button', {
        class: 'btn btn--primary',
        type: 'button',
        onClick: () => nextPuzzle(),
      }, icon('chevron', 18), el('span', { text: 'Next puzzle' })));
      return;
    }
    actions.appendChild(el('button', {
      class: 'btn btn--secondary',
      type: 'button',
      onClick: () => showHint(),
    }, icon('lightbulb', 18), el('span', { text: 'Hint' })));
    actions.appendChild(el('button', {
      class: 'btn btn--ghost',
      type: 'button',
      onClick: () => showSolution(),
    }, icon('eye', 18), el('span', { text: 'Show solution' })));
  }

  setFeedback('idle', 'target', `${sideName} to move — find the forcing move.`);
  paintActions();
  redraw(true);

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => {
      cancelled = true;
      if (replyTimer !== null) clearTimeout(replyTimer);
      board.destroy();
    },
  };
}
