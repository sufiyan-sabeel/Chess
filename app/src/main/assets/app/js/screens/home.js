/**
 * Home — greeting, quick play entry, real recent games from local storage.
 *
 * Every number on this screen comes from IndexedDB records the app itself
 * wrote; nothing is synthesised.
 */

import { el, clear, formatDateTime } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { avatarNode, emptyState, section, skeleton, toast } from '../ui/components.js';
import { getSettings, getSession, isLoggedIn, ensureGuestId } from '../store.js';
import { listGames, dbCount } from '../db.js';
import { go } from '../router.js';

export function homeScreen() {
  const root = el('div', { class: 'screen' });
  const body = el('div', { class: 'scroll' });
  let cancelled = false;

  root.appendChild(body);

  function greeting() {
    const session = getSession();
    if (session && session.display_name) return session.display_name;
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
  }

  function renderHeader() {
    const session = getSession();
    const sub = session
      ? (session.email_verified ? 'Signed in · verified' : 'Signed in · email unverified')
      : 'Playing as guest on this device';

    return el('div', { class: 'home' },
      el('div', { class: 'home__greet' },
        avatarNode(session ? session.display_name : 'Guest', session ? session.id : ensureGuestId(), 'lg'),
        el('div', { class: 'home__greet-text' },
          el('div', { class: 'home__hello', text: greeting() }),
          el('div', { class: 'home__sub' },
            el('span', {
              class: `dot ${isLoggedIn() ? 'dot--online' : 'dot--offline'}`.trim(),
              'aria-hidden': 'true',
            }),
            el('span', { text: sub }),
          ),
        ),
        el('button', {
          class: 'btn btn--icon',
          type: 'button',
          'aria-label': 'Settings',
          onClick: () => go('/settings'),
        }, icon('gear', 20)),
      ),
    );
  }

  function renderHero() {
    const settings = getSettings();
    return el('button', {
      class: 'play-hero',
      type: 'button',
      onClick: () => go('/play'),
    },
      el('div', { class: 'play-hero__label', text: 'Quick match' }),
      el('div', { class: 'play-hero__title', text: 'Play now' }),
      el('div', { class: 'play-hero__sub', text: 'Local game vs the engine, or a rated online match.' }),
      el('div', { class: 'play-hero__cta' },
        icon('play', 18),
        el('span', { text: settings.seenOnboarding ? 'New game' : 'Start' }),
      ),
      el('div', { class: 'play-hero__art', 'aria-hidden': 'true' },
        icon('crown', 132),
      ),
    );
  }

  function renderQuick() {
    const items = [
      { icon: 'puzzle', label: 'Puzzles', route: '/puzzles' },
      { icon: 'chart', label: 'Stats', route: '/stats' },
      { icon: 'trophy', label: 'Leaderboard', route: '/leaderboard' },
      { icon: 'user', label: 'Profile', route: '/profile' },
    ];
    return el('div', { class: 'quick-grid' },
      items.map((it) => el('button', {
        class: 'quick',
        type: 'button',
        onClick: () => go(it.route),
      }, icon(it.icon, 22), el('span', { text: it.label }))),
    );
  }

  function renderModes() {
    return section('Play', el('button', {
      class: 'link',
      type: 'button',
      text: 'All modes',
      onClick: () => go('/play'),
    }));
  }

  function gameRow(record) {
    const isWin = record.result === 'win';
    const isLoss = record.result === 'loss';
    const cls = record.result === 'draw' ? 'draw' : isWin ? 'win' : 'loss';
    const glyph = record.result === 'draw' ? '½' : isWin ? 'W' : 'L';
    const opponent = opponentName(record);

    return el('button', {
      class: 'gamerow',
      type: 'button',
      onClick: () => go(`/review/${encodeURIComponent(record.id)}`),
    },
      el('div', { class: `gamerow__result gamerow__result--${cls}`, text: glyph }),
      el('div', { class: 'gamerow__main' },
        el('div', { class: 'gamerow__opp', text: opponent }),
        el('div', { class: 'gamerow__meta', text: metaLine(record) }),
      ),
      el('div', { class: 'gamerow__right' },
        el('div', { text: formatDateTime(record.finishedAt || record.savedAt || Date.now()) }),
        el('div', { text: record.control ? record.control.name : '' }),
      ),
    );
  }

  function opponentName(record) {
    const session = getSession();
    const myName = session ? session.display_name : null;
    if (record.mode === 'bot') return `${record.control ? record.control.name : ''} · ${record.level || 'medium'} bot`;
    if (myName && record.white === myName) return record.black || 'Opponent';
    return record.white || 'Opponent';
  }

  function metaLine(record) {
    const bits = [];
    bits.push(record.mode === 'bot' ? 'vs computer' : record.mode === 'online' ? 'online' : 'local');
    if (record.reason) bits.push(reasonLabel(record.reason));
    if (record.pgn) bits.push(`${Math.ceil(record.sans.length / 2)} moves`);
    return bits.join(' · ');
  }

  function reasonLabel(reason) {
    return ({
      checkmate: 'checkmate', stalemate: 'stalemate', timeout: 'time', resign: 'resign',
      insufficient: 'material', threefold: 'repetition', fiftyMove: '50-move',
      agreement: 'agreement', abort: 'aborted',
    })[reason] || reason;
  }

  async function renderRecent() {
    const holder = el('div', {});
    holder.appendChild(skeleton(56, 2));
    const [games, total] = await Promise.all([listGames({ limit: 4 }), dbCount('games')]);
    if (cancelled) return;
    clear(holder);

    if (!games.length) {
      holder.appendChild(emptyState({
        iconName: 'board',
        title: 'No games yet',
        hint: 'Your finished games will appear here, stored on this device.',
        action: el('button', {
          class: 'btn btn--primary mt-2',
          type: 'button',
          text: 'Start a game',
          onClick: () => go('/play'),
        }),
      }));
    } else {
      for (const g of games) holder.appendChild(gameRow(g));
      holder.appendChild(el('div', { class: 'row justify-between mt-3' },
        el('span', { class: 'tiny muted', text: `${total} game${total === 1 ? '' : 's'} stored locally` }),
        el('button', { class: 'link', type: 'button', text: 'Statistics', onClick: () => go('/stats') }),
      ));
    }
    const target = body.querySelector('[data-recent]');
    if (target && !cancelled) { clear(target); target.appendChild(holder); }
  }

  body.appendChild(renderHeader());
  body.appendChild(renderHero());
  body.appendChild(renderModes());
  body.appendChild(renderQuick());

  const recent = el('div', { 'data-recent': '1' });
  body.appendChild(section('Recent games'));
  body.appendChild(recent);
  body.appendChild(el('div', { class: 'mt-3' },
    el('button', {
      class: 'btn btn--secondary btn--block',
      type: 'button',
      onClick: () => go('/watch'),
    }, icon('globe', 18), el('span', { text: 'Watch live games' })),
  ));

  renderRecent().catch(() => {
    if (!cancelled) toast('Could not read local game history', { type: 'error' });
  });

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => { cancelled = true; },
  };
}
