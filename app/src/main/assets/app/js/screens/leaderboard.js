/**
 * Leaderboard — server rankings only.
 *
 * The server is the single source of truth here: an unconfigured backend, an
 * unreachable one and a genuinely empty ranking are three different states
 * with three different explanations. Rows are only ever rendered from the
 * response — there is no sample data to fall back on.
 */

import { el, clear } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, section, emptyState, skeleton, avatarNode } from '../ui/components.js';
import { getSession, isLoggedIn } from '../store.js';
import { api, apiConfigured, isOfflineError } from '../api.js';
import { go } from '../router.js';

const MODES = ['bullet', 'blitz', 'rapid', 'classical'];
const PER_PAGE = 50;

export function leaderboardScreen(ctx = {}) {
  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });

  let cancelled = false;
  let mode = 'blitz';
  let page = 1;
  let seq = 0;          // stale-response guard when mode/page change quickly

  root.appendChild(topbar({
    title: 'Leaderboard',
    onBack: () => (ctx.router && typeof ctx.router.back === 'function' ? ctx.router.back() : go('/')),
    right: el('button', {
      class: 'btn btn--secondary btn--sm',
      type: 'button',
      onClick: () => load(),
    }, icon('refresh', 16), el('span', { text: 'Refresh' })),
  }));
  root.appendChild(scroll);

  // ---------------------------------------------------------------- modes
  const chips = MODES.map((m) => el('button', {
    class: 'chip',
    type: 'button',
    'aria-pressed': String(m === mode),
    text: m,
    onClick: () => {
      if (mode === m) return;
      mode = m;
      page = 1;
      for (const [i, chip] of chips.entries()) chip.setAttribute('aria-pressed', String(MODES[i] === mode));
      load();
    },
  }));
  scroll.appendChild(section('Mode', el('span', { class: 'tiny muted', text: 'rated games only' })));
  scroll.appendChild(el('div', { class: 'chips' }, chips));

  const listHolder = el('div', { class: 'mt-4' });
  const pagerHolder = el('div', {});
  scroll.appendChild(listHolder);
  scroll.appendChild(pagerHolder);

  // --------------------------------------------------------------- paint
  function row(entry, index) {
    const rank = typeof entry.rank === 'number'
      ? entry.rank
      : (page - 1) * PER_PAGE + index + 1;
    const name = typeof entry.display_name === 'string' && entry.display_name ? entry.display_name : '—';
    const seed = entry.avatar_seed || String(entry.id ?? name);
    const games = typeof entry.games === 'number' ? `${entry.games} rated game${entry.games === 1 ? '' : 's'}` : '';
    const rating = typeof entry.rating === 'number' ? entry.rating : '—';

    return el('div', { class: 'lb-row' },
      el('div', { class: `lb-row__rank ${rank <= 3 ? `lb-row__rank--${rank}` : ''}`.trim(), text: String(rank) }),
      avatarNode(name, seed, 'sm'),
      el('div', { class: 'lb-row__main' },
        el('div', { class: 'lb-row__name', text: name }),
        games ? el('div', { class: 'lb-row__sub', text: games }) : null,
      ),
      el('div', { class: 'lb-row__rating', text: String(rating) }),
    );
  }

  function paintMe(me) {
    if (!me || typeof me !== 'object') {
      if (!isLoggedIn()) return;
      pagerHolder.prepend(el('div', { class: 'notice mt-3' },
        icon('info', 16),
        el('div', { text: 'You are not ranked yet — a rank appears after your first rated game.' }),
      ));
      return;
    }
    const session = getSession();
    const name = session && session.display_name ? session.display_name : 'You';
    pagerHolder.prepend(el('div', { class: 'lb-row lb-row--me mt-3' },
      el('div', { class: 'lb-row__rank', text: typeof me.rank === 'number' ? String(me.rank) : '—' }),
      avatarNode(name, session && session.avatar_seed ? session.avatar_seed : String(session?.id ?? 'me'), 'sm'),
      el('div', { class: 'lb-row__main' },
        el('div', { class: 'lb-row__name', text: name }),
        el('div', { class: 'lb-row__sub', text: 'Your position' }),
      ),
      el('div', { class: 'lb-row__rating', text: typeof me.rating === 'number' ? String(me.rating) : '—' }),
    ));
  }

  function paintPager(total) {
    clear(pagerHolder);
    const pages = Math.max(1, Math.ceil(total / PER_PAGE));
    if (pages > 1) {
      pagerHolder.appendChild(el('div', { class: 'pager' },
        el('button', {
          class: 'btn btn--icon',
          type: 'button',
          'aria-label': 'Previous page',
          disabled: page <= 1,
          onClick: () => { if (page > 1) { page--; load(); } },
        }, icon('back', 18)),
        el('span', { class: 'pager__info', text: `Page ${page} of ${pages} · ${total} players` }),
        el('button', {
          class: 'btn btn--icon',
          type: 'button',
          'aria-label': 'Next page',
          disabled: page >= pages,
          onClick: () => { if (page < pages) { page++; load(); } },
        }, icon('chevron', 18)),
      ));
    } else if (total) {
      pagerHolder.appendChild(el('p', { class: 'tiny muted center mt-3', text: `${total} ranked player${total === 1 ? '' : 's'}` }));
    }
  }

  // ---------------------------------------------------------------- load
  async function load() {
    const mySeq = ++seq;
    clear(listHolder);
    clear(pagerHolder);
    listHolder.appendChild(skeleton(64, 6));

    if (!apiConfigured()) {
      if (cancelled) return;
      clear(listHolder);
      listHolder.appendChild(emptyState({
        iconName: 'server',
        title: 'No server configured',
        hint: 'Rankings live on the server — set one in Settings → Network to see them.',
        action: el('button', {
          class: 'btn btn--secondary mt-2',
          type: 'button',
          text: 'Open Settings',
          onClick: () => go('/settings'),
        }),
      }));
      return;
    }

    try {
      const res = await api.leaderboard({ mode, page, per_page: PER_PAGE });
      if (cancelled || mySeq !== seq) return;
      const data = res && res.data ? res.data : {};
      const entries = Array.isArray(data.entries) ? data.entries : null;
      const total = typeof data.total === 'number'
        ? data.total
        : entries ? entries.length : 0;

      clear(listHolder);

      if (entries === null) {
        // A response without an `entries` array is a contract mismatch, not a
        // ranking — say so rather than guessing at rows.
        listHolder.appendChild(emptyState({
          iconName: 'info',
          title: 'Unexpected server response',
          hint: 'The leaderboard response had no entries list, so there is nothing safe to render.',
          action: retry(),
        }));
        return;
      }

      if (!entries.length) {
        listHolder.appendChild(emptyState({
          iconName: 'trophy',
          title: 'No ranked players yet',
          hint: 'A fresh install starts with an empty leaderboard. Ranked games appear here as soon as they are played.',
          action: retry(),
        }));
        return;
      }

      const table = el('div', {});
      entries.forEach((e, i) => table.appendChild(row(e, i)));
      listHolder.appendChild(table);
      paintPager(total);
      paintMe(data.me);
    } catch (err) {
      if (cancelled || mySeq !== seq) return;
      clear(listHolder);
      const code = err && err.code ? err.code : 'UNKNOWN';

      if (isOfflineError(err)) {
        listHolder.appendChild(emptyState({
          iconName: 'wifiOff',
          title: navigator.onLine === false ? 'You are offline' : 'Could not reach the server',
          hint: 'Rankings need a connection to your configured server.',
          action: retry(),
        }));
        return;
      }
      if (code === 'NOT_CONFIGURED') {
        listHolder.appendChild(emptyState({
          iconName: 'server',
          title: 'No server configured',
          hint: 'Set a backend in Settings → Network to see ranked players.',
          action: el('button', {
            class: 'btn btn--secondary mt-2',
            type: 'button',
            text: 'Open Settings',
            onClick: () => go('/settings'),
          }),
        }));
        return;
      }
      if (code === 'NOT_FOUND' || code === 'HTTP_404') {
        listHolder.appendChild(emptyState({
          iconName: 'server',
          title: 'This server has no leaderboards',
          hint: 'GET /leaderboard returned 404 — the configured server does not offer rankings yet. Nothing was invented to fill the gap.',
          action: retry(),
        }));
        return;
      }
      listHolder.appendChild(emptyState({
        iconName: 'info',
        title: 'Could not reach the server',
        hint: `The request failed (${code}).`,
        action: retry(),
      }));
    }
  }

  function retry() {
    return el('button', {
      class: 'btn btn--primary mt-2',
      type: 'button',
      onClick: () => load(),
    }, icon('refresh', 18), el('span', { text: 'Retry' }));
  }

  load();

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => { cancelled = true; },
  };
}
