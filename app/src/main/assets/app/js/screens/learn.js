/**
 * Learn — bundled lessons with static board demonstrations.
 *
 * Progress (`lesson_progress` in IndexedDB) records only what the user
 * actually pressed: a lesson is completed when "Mark as completed" is used.
 * Lessons render entirely from data/lessons.js; the demo boards come from
 * the same vendored chess.js used everywhere else, so a position that would
 * not load simply fails the test suite instead of rendering nonsense.
 */

import { el, clear } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, section, emptyState, toast } from '../ui/components.js';
import { Board } from '../ui/board.js';
import { LESSONS } from '../data/lessons.js';
import { dbGet, dbPut } from '../db.js';
import { go } from '../router.js';

async function loadLessonProgress(id) {
  try {
    const rec = await dbGet('lesson_progress', id);
    return rec && typeof rec === 'object' ? rec : null;
  } catch {
    return null;
  }
}

function saveLessonProgress(id, rec) {
  return dbPut('lesson_progress', { id, ...rec }).catch(() => {});
}

function isCompleted(rec) {
  return Boolean(rec && (rec.completed === true || rec.done === true));
}

// ---------------------------------------------------------------- list view

export function learnScreen() {
  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });

  let cancelled = false;
  const progress = new Map();

  root.appendChild(topbar({ title: 'Learn' }));
  root.appendChild(scroll);

  const status = el('div', { class: 'puzzle-status' });
  scroll.appendChild(status);

  scroll.appendChild(section('Lessons', el('span', { class: 'tiny muted', text: 'bundled content · offline' })));
  const listHolder = el('div', { class: 'mt-3' });
  scroll.appendChild(listHolder);

  function paintStatus() {
    const done = LESSONS.filter((l) => isCompleted(progress.get(l.id))).length;
    clear(status);
    status.appendChild(el('span', {
      text: LESSONS.length
        ? `${done} of ${LESSONS.length} lessons completed on this device`
        : 'No lessons bundled',
    }));
    status.appendChild(icon('book', 18));
  }

  function paint() {
    clear(listHolder);
    paintStatus();

    if (!LESSONS.length) {
      listHolder.appendChild(emptyState({
        iconName: 'book',
        title: 'No lessons bundled',
        hint: 'This build ships without lesson content — nothing is fetched to fill the gap.',
      }));
      return;
    }

    LESSONS.forEach((lesson, i) => {
      const done = isCompleted(progress.get(lesson.id));
      const state = done ? 'Completed' : `${lesson.minutes} min read`;
      listHolder.appendChild(el('button', {
        class: `lesson ${done ? 'lesson--done' : ''}`.trim(),
        type: 'button',
        onClick: () => go(`/learn/${encodeURIComponent(lesson.id)}`),
      },
        el('span', { class: 'lesson__no', text: done ? '✓' : String(i + 1) }),
        el('span', { style: { flex: '1 1 auto', minWidth: '0' } },
          el('span', { class: 'lesson__title', text: lesson.title, style: { display: 'block' } }),
          el('span', { class: 'lesson__sub', text: lesson.sub, style: { display: 'block' } }),
          el('span', { class: 'lesson__state', text: state, style: { display: 'block' } }),
        ),
        icon('chevron', 18),
      ));
    });
  }

  async function load() {
    const recs = await Promise.all(LESSONS.map((l) => loadLessonProgress(l.id)));
    if (cancelled) return;
    LESSONS.forEach((l, i) => progress.set(l.id, recs[i]));
    paint();
  }

  paint();
  load();

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => { cancelled = true; },
  };
}

// --------------------------------------------------------------- detail view

export function lessonDetailScreen(ctx = {}) {
  const id = ctx.params ? ctx.params.id : '';
  const index = LESSONS.findIndex((l) => l.id === id);
  const lesson = index >= 0 ? LESSONS[index] : null;

  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });
  root.appendChild(scroll);

  if (!lesson) {
    scroll.appendChild(emptyState({
      iconName: 'info',
      title: 'Lesson not found',
      hint: `No bundled lesson has the id “${id}”.`,
      action: el('button', {
        class: 'btn btn--primary mt-2',
        type: 'button',
        onClick: () => go('/learn'),
      }, icon('back', 18), el('span', { text: 'Back to lessons' })),
    }));
    return { el: root, keepScreenOn: false };
  }

  let cancelled = false;
  let completed = false;
  let board = null;

  root.insertBefore(topbar({
    title: lesson.title,
    onBack: () => go('/learn'),
  }), scroll);

  scroll.appendChild(el('p', { class: 'tiny muted', text: `${lesson.sub} · ${lesson.minutes} min read` }));

  const body = el('div', { class: 'lesson-body mt-3' });
  scroll.appendChild(body);

  const actions = el('div', { class: 'flex gap-2 mt-4' });
  scroll.appendChild(actions);
  const pagerHolder = el('div', {});
  scroll.appendChild(pagerHolder);

  // ------------------------------------------------------------ sections
  for (const s of lesson.sections) {
    if (s.h3) body.appendChild(el('h3', { text: s.h3 }));
    if (s.p) body.appendChild(el('p', { text: s.p }));
    if (Array.isArray(s.ul) && s.ul.length) {
      body.appendChild(el('ul', {}, s.ul.map((li) => el('li', { text: li }))));
    }
  }

  // ------------------------------------------------------------ demo board
  if (lesson.demo) {
    const demoWrap = el('div', { class: 'demo mt-3' });
    const boardWrap = el('div', { class: 'board-wrap' });
    demoWrap.appendChild(boardWrap);
    body.appendChild(demoWrap);
    body.appendChild(el('p', { class: 'tiny muted center', text: lesson.demo.caption }));
    try {
      board = new Board({
        root: boardWrap,
        interactive: false,
        onMove: () => {},
      });
      board.draw({
        fen: lesson.demo.fen,
        turn: (lesson.demo.fen.split(' ')[1] || 'w'),
        moves: [],
        canMove: false,
        lastMove: null,
        inCheck: false,
        pendingPromotion: null,
      });
    } catch (err) {
      board = null;
      demoWrap.remove();
      body.appendChild(el('div', { class: 'notice notice--warn' },
        icon('info', 16),
        el('span', { text: `The demo position could not be displayed (${String(err && err.message || err)}).` }),
      ));
    }
  }

  // ------------------------------------------------------------ completion
  function paintActions() {
    clear(actions);
    if (completed) {
      actions.appendChild(el('span', {
        class: 'feedback feedback--ok',
        style: { flex: '1' },
      }, icon('check', 16), el('span', { text: 'Completed on this device' })));
    } else {
      actions.appendChild(el('button', {
        class: 'btn btn--primary',
        type: 'button',
        onClick: async () => {
          completed = true;
          await saveLessonProgress(lesson.id, {
            completed: true,
            completedAt: Date.now(),
            title: lesson.title,
          }).catch(() => {});
          toast('Lesson completed', { type: 'success', durationMs: 1600 });
          paintActions();
          paintStatusRow();
        },
      }, icon('check', 18), el('span', { text: 'Mark as completed' })));
    }
  }

  const statusRow = el('div', { class: 'puzzle-status mt-3' });
  scroll.insertBefore(statusRow, actions);

  function paintStatusRow() {
    clear(statusRow);
    statusRow.appendChild(el('span', {
      text: completed
        ? 'This lesson is marked completed.'
        : 'Not completed yet — mark it when the idea clicks.',
    }));
    statusRow.appendChild(el('span', {
      class: 'lesson__state',
      text: `${index + 1} / ${LESSONS.length}`,
    }));
  }

  // ---------------------------------------------------------------- pager
  function paintPager() {
    clear(pagerHolder);
    if (LESSONS.length < 2) return;
    const prev = LESSONS[(index - 1 + LESSONS.length) % LESSONS.length];
    const next = LESSONS[(index + 1) % LESSONS.length];
    pagerHolder.appendChild(el('div', { class: 'pager' },
      el('button', {
        class: 'btn btn--icon',
        type: 'button',
        'aria-label': `Previous lesson: ${prev.title}`,
        onClick: () => go(`/learn/${encodeURIComponent(prev.id)}`),
      }, icon('back', 18)),
      el('span', { class: 'pager__info', text: `${index + 1} of ${LESSONS.length}` }),
      el('button', {
        class: 'btn btn--icon',
        type: 'button',
        'aria-label': `Next lesson: ${next.title}`,
        onClick: () => go(`/learn/${encodeURIComponent(next.id)}`),
      }, icon('chevron', 18)),
    ));
  }

  loadLessonProgress(lesson.id).then((rec) => {
    if (cancelled) return;
    completed = isCompleted(rec);
    paintActions();
    paintStatusRow();
  });

  paintActions();
  paintStatusRow();
  paintPager();

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => {
      cancelled = true;
      if (board) board.destroy();
    },
  };
}
