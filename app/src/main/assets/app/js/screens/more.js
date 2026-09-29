/**
 * More — the secondary menu, plus an honest status panel.
 *
 * Version, platform, backend, account and on-device record counts are all
 * read from the real stores at render time; "Clear local data" actually
 * clears them (behind a confirmation) instead of claiming to.
 */

import { el, clear, formatDateTime } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, section, toast, modal, confirm, emptyState, skeleton, avatarNode } from '../ui/components.js';
import { appVersion, platformInfo, resetSettings, getSession } from '../store.js';
import { apiConfigured, apiBase, ping, isOfflineError } from '../api.js';
import { dbCount, dbGetAll, dbClearAll, listGames } from '../db.js';
import { go } from '../router.js';

export function moreScreen(ctx = {}) {
  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });

  let cancelled = false;

  root.appendChild(topbar({ title: 'More' }));
  root.appendChild(scroll);

  // ------------------------------------------------------------- identity
  function paintProfile() {
    const holder = scroll.querySelector('[data-profile]');
    if (!holder) return;
    clear(holder);
    const session = getSession();
    const name = session && session.display_name ? session.display_name : 'Guest on this device';
    const seed = session ? (session.avatar_seed || String(session.id ?? name)) : 'guest';

    holder.appendChild(el('button', { class: 'more-profile', type: 'button', onClick: () => go('/profile') },
      avatarNode(name, seed, 'lg'),
      el('div', { class: 'gamerow__main' },
        el('div', { class: 'profile-head__name', text: name }),
        el('div', { class: 'profile-head__mail', text: session ? (session.email || 'Signed in') : 'Not signed in — tap to learn more' }),
      ),
      icon('chevron', 18, 'chev'),
    ));
  }

  // --------------------------------------------------------------- status
  function statusRow(label, value) {
    return el('div', { class: 'list__row' },
      el('span', { class: 'list__row__label', text: label }),
      el('span', { class: 'list__row__value', text: value }),
    );
  }

  async function paintStatus() {
    const holder = scroll.querySelector('[data-status]');
    if (!holder) return;
    clear(holder);
    holder.appendChild(skeleton(52, 4));

    const [gameCount, puzzles, lessons] = await Promise.all([
      dbCount('games'),
      dbGetAll('puzzle_progress'),
      dbGetAll('lesson_progress'),
    ]);
    if (cancelled) return;

    const solved = (Array.isArray(puzzles) ? puzzles : []).filter((p) => p && p.solved === true).length;
    const attempted = (Array.isArray(puzzles) ? puzzles : []).length;
    const lessonRows = Array.isArray(lessons) ? lessons : [];
    const finishedLessons = lessonRows.filter((l) => l && (l.completed === true || l.done === true)).length;

    const session = getSession();
    const puzzleText = attempted ? `${solved} solved of ${attempted} attempted` : 'none yet';
    const lessonText = lessonRows.length
      ? (finishedLessons ? `${finishedLessons} of ${lessonRows.length} completed` : `${lessonRows.length} started`)
      : 'none yet';

    clear(holder);
    holder.appendChild(el('div', { class: 'list' },
      statusRow('Version', appVersion()),
      statusRow('Platform', platformInfo()),
      statusRow('Backend', apiConfigured() ? apiBase() : 'Not configured'),
      statusRow('Account', session ? (session.display_name || session.email || 'Signed in') : 'Not signed in'),
      statusRow('Games stored locally', String(gameCount)),
      statusRow('Puzzles', puzzleText),
      statusRow('Lessons', lessonText),
    ));
    holder.appendChild(el('div', { class: 'row gap-2 mt-2' },
      el('button', { class: 'btn btn--secondary btn--sm', type: 'button', onClick: (ev) => checkServer(ev.currentTarget) },
        icon('wifi', 16), el('span', { text: 'Check server' })),
      el('span', { class: 'tiny muted', text: apiConfigured() ? 'Pings /health on the configured backend' : 'No backend set (Settings → Network)' }),
    ));
  }

  async function checkServer(btn) {
    if (!apiConfigured()) {
      toast('No server configured — set one in Settings → Network', { type: 'error' });
      return;
    }
    btn.disabled = true;
    try {
      const res = await ping();
      if (cancelled) return;
      toast(`Server reachable · ${res.ms} ms${res.version ? ` · v${res.version}` : ''}`, { type: 'success' });
    } catch (err) {
      if (cancelled) return;
      if (isOfflineError(err)) toast('You are offline — the server cannot be reached', { type: 'error' });
      else if (err && err.code === 'HTTP_503') toast('Server is up but not ready (database unreachable)', { type: 'error' });
      else if (err && (err.code === 'NOT_FOUND' || err.code === 'HTTP_404')) toast('The configured URL does not look like a Checkmate API (404)', { type: 'error' });
      else toast(`Could not reach the server (${err && err.code ? err.code : 'unknown'})`, { type: 'error' });
    } finally {
      if (!cancelled) btn.disabled = false;
    }
  }

  // ---------------------------------------------------------- menu rows
  function menuRow({ iconName, label, value = '', onClick }) {
    return el('button', { class: 'list__row', type: 'button', onClick },
      icon(iconName, 20),
      el('span', { class: 'list__row__label', text: label }),
      value ? el('span', { class: 'list__row__value', text: value }) : null,
      icon('chevron', 18, 'chev'),
    );
  }

  function paintMenu() {
    scroll.appendChild(section('Go to'));
    scroll.appendChild(el('div', { class: 'list' },
      menuRow({ iconName: 'user', label: 'Profile', onClick: () => go('/profile') }),
      menuRow({ iconName: 'chart', label: 'Statistics', onClick: () => go('/stats') }),
      menuRow({ iconName: 'trophy', label: 'Leaderboard', onClick: () => go('/leaderboard') }),
      menuRow({ iconName: 'globe', label: 'Watch live games', onClick: () => go('/watch') }),
      menuRow({ iconName: 'gear', label: 'Settings', onClick: () => go('/settings') }),
      menuRow({ iconName: 'info', label: 'About & legal', onClick: showAbout }),
    ));
  }

  function showAbout() {
    const handle = modal({
      title: 'About CHECKMATE',
      body: el('div', {},
        el('p', { class: 'muted small', text: `Version ${appVersion()} · ${platformInfo()}` }),
        el('p', { class: 'muted small mt-2', text: 'CHECKMATE runs from assets bundled in the app — no remote web view, no trackers, no third-party requests. Sound effects are synthesised; icons and artwork are original.' }),
        el('p', { class: 'muted small mt-2', text: 'Games, puzzle progress and preferences are stored on this device. With a backend configured, accounts, ratings and uploaded games live on that server; auth tokens are kept in the Android Keystore when the native bridge is available.' }),
        el('p', { class: 'muted small mt-2', text: 'Deleting your account (Profile) removes the server-side record; clearing local data (More) removes everything stored on this device. The two are independent.' }),
      ),
      actions: [el('button', { class: 'btn btn--primary', type: 'button', text: 'Close', onClick: () => handle.close() })],
    });
  }

  // ------------------------------------------------------ recent games
  async function paintRecent() {
    const holder = scroll.querySelector('[data-review]');
    if (!holder) return;
    clear(holder);
    holder.appendChild(skeleton(56, 2));

    let games = [];
    try {
      games = await listGames({ limit: 5 });
    } catch {
      games = [];
    }
    if (cancelled) return;
    clear(holder);

    if (!games.length) {
      holder.appendChild(emptyState({
        iconName: 'history',
        title: 'Nothing to review yet',
        hint: 'Finished games are listed here so you can step through them move by move.',
        action: el('button', {
          class: 'btn btn--primary mt-2',
          type: 'button',
          text: 'Start a game',
          onClick: () => go('/play'),
        }),
      }));
      return;
    }

    for (const g of games) {
      const finished = g.result && g.result !== '*';
      const glyph = g.result === '1/2-1/2' || g.result === 'draw' ? '½'
        : g.result === '1-0' ? '1–0'
        : g.result === '0-1' ? '0–1'
        : finished ? '?' : '…';
      // Shared-device games get a neutral chip: there is no single "you", so
      // a win/loss colour would invent a perspective.
      const cls = g.mode === 'local' ? 'draw'
        : g.result === '1/2-1/2' || g.result === 'draw' ? 'draw'
        : g.result === 'win' ? 'win'
        : g.result === 'loss' ? 'loss'
        : (g.result === '1-0' && g.humanSide === 'w') || (g.result === '0-1' && g.humanSide === 'b') ? 'win'
        : (g.result === '1-0' || g.result === '0-1') ? 'loss'
        : 'draw';

      holder.appendChild(el('button', {
        class: 'gamerow',
        type: 'button',
        onClick: () => go(`/review/${encodeURIComponent(g.id)}`),
      },
        el('div', { class: `gamerow__result gamerow__result--${cls}`, text: glyph }),
        el('div', { class: 'gamerow__main' },
          el('div', { class: 'gamerow__opp', text: `${g.white || 'White'} vs ${g.black || 'Black'}` }),
          el('div', { class: 'gamerow__meta', text: `${g.mode || 'game'}${Array.isArray(g.sans) ? ` · ${Math.ceil(g.sans.length / 2)} moves` : ''}` }),
        ),
        el('div', { class: 'gamerow__right', text: formatDateTime(g.finishedAt || g.savedAt || Date.now()) }),
      ));
    }
  }

  // ------------------------------------------------------- clear data
  async function clearLocalData() {
    const ok = await confirm({
      title: 'Clear local data?',
      message: 'Deletes every game, puzzle and lesson record on this device and resets preferences to their defaults (you will see the welcome screen again). Your server account and its ratings are not touched.',
      confirmLabel: 'Clear everything',
      danger: true,
    });
    if (!ok || cancelled) return;
    await dbClearAll();
    resetSettings();
    if (cancelled) return;
    toast('Local data cleared', { type: 'success' });
    paintProfile();
    paintStatus().catch(() => {});
    paintRecent().catch(() => {});
  }

  // ------------------------------------------------------------- layout
  const profileHolder = el('div', { 'data-profile': '1' });
  const statusHolder = el('div', { 'data-status': '1' });
  const reviewHolder = el('div', { 'data-review': '1' });

  scroll.appendChild(profileHolder);
  scroll.appendChild(section('This install'));
  scroll.appendChild(statusHolder);
  paintMenu();
  scroll.appendChild(section('Review your games', el('button', {
    class: 'link',
    type: 'button',
    text: 'All statistics',
    onClick: () => go('/stats'),
  })));
  scroll.appendChild(reviewHolder);

  scroll.appendChild(section('Storage'));
  scroll.appendChild(el('div', { class: 'list' },
    el('button', {
      class: 'list__row',
      type: 'button',
      style: { color: 'var(--danger)' },
      onClick: clearLocalData,
    },
      icon('trash', 20),
      el('span', { class: 'list__row__label', text: 'Clear local data' }),
      icon('chevron', 18, 'chev'),
    ),
  ));
  scroll.appendChild(el('p', { class: 'tiny muted mt-2',
    text: 'Clearing affects this device only — accounts, ratings and uploaded games live on your server.' }));

  paintProfile();
  paintStatus().catch(() => {});
  paintRecent().catch(() => {});

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => { cancelled = true; },
  };
}
