/**
 * Watch — live games published by the server.
 *
 * There is no local or sample source for this screen: every card comes from
 * GET /watch/games. No server, no connection, a 404 and "nothing is being
 * played" each get their own explained state, and the list refreshes on a
 * timer that stops the moment the screen is left.
 */

import { el, clear, relativeTime } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, section, emptyState, skeleton } from '../ui/components.js';
import { apiConfigured, api, isOfflineError } from '../api.js';
import { go } from '../router.js';

const REFRESH_MS = 15000;

/** Pull only fields the server actually sent — no defaults that look like data. */
function readGame(g) {
  if (!g || typeof g !== 'object') return null;
  const players = g.players && typeof g.players === 'object' ? g.players : {};
  const side = (key) => {
    const p = players[key] !== undefined ? players[key] : g[key];
    if (typeof p === 'string') return p;
    if (p && typeof p === 'object') {
      if (typeof p.display_name === 'string' && p.display_name) return p.display_name;
      if (typeof p.name === 'string' && p.name) return p.name;
    }
    return '';
  };
  const startedTs = g.started_at ? Date.parse(g.started_at) : NaN;
  return {
    id: typeof g.id !== 'undefined' ? String(g.id) : '',
    white: side('white'),
    black: side('black'),
    mode: typeof g.mode === 'string' ? g.mode : '',
    status: typeof g.status === 'string' ? g.status : '',
    timeControl: typeof g.initial_time === 'number'
      ? `${g.initial_time}s${typeof g.increment === 'number' ? `+${g.increment}` : ''}`
      : '',
    startedTs: Number.isNaN(startedTs) ? null : startedTs,
    rated: typeof g.rated === 'boolean' ? g.rated : null,
  };
}

export function watchScreen(ctx = {}) {
  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });

  let cancelled = false;
  let seq = 0;
  let timer = null;

  root.appendChild(topbar({
    title: 'Watch',
    subtitle: 'Live games from your server',
    onBack: () => (ctx.router && typeof ctx.router.back === 'function' ? ctx.router.back() : go('/')),
    right: el('button', {
      class: 'btn btn--secondary btn--sm',
      type: 'button',
      onClick: () => load(),
    }, icon('refresh', 16), el('span', { text: 'Refresh' })),
  }));
  root.appendChild(scroll);

  const statusRow = el('div', { class: 'row justify-between mb-3' });
  const listHolder = el('div', {});
  scroll.appendChild(statusRow);
  scroll.appendChild(listHolder);

  function setConn(text, online = true) {
    clear(statusRow);
    statusRow.appendChild(el('div', { class: 'conn' },
      el('span', { class: `dot ${online ? 'dot--online' : 'dot--offline'}` }),
      el('span', { text }),
    ));
  }

  function card(g) {
    const names = [g.white, g.black].filter(Boolean);
    const title = names.length === 2 ? `${g.white} · ${g.black}` : (names[0] || (g.id ? `Match ${g.id}` : 'Live match'));
    const bits = [];
    if (g.mode) bits.push(g.mode);
    if (g.timeControl) bits.push(g.timeControl);
    if (g.rated === true) bits.push('rated');
    else if (g.rated === false) bits.push('unrated');
    if (g.startedTs) bits.push(`started ${relativeTime(g.startedTs)}`);
    if (names.length < 2) bits.push('opponent names not provided');

    return el('div', { class: 'watch-card' },
      el('div', { class: 'watch-card__mini', style: { background: 'var(--surface-raised)', display: 'grid', placeItems: 'center' } },
        icon('board', 26)),
      el('div', { class: 'gamerow__main' },
        el('div', { class: 'gamerow__opp', text: title }),
        el('div', { class: 'gamerow__meta', text: bits.join(' · ') || 'No details provided' }),
      ),
      g.status
        ? el('span', {
            class: `badge ${g.status === 'live' ? 'badge--green' : ''}`.trim(),
            text: g.status,
          })
        : null,
    );
  }

  async function load() {
    const mySeq = ++seq;
    clear(listHolder);
    listHolder.appendChild(skeleton(72, 4));
    setConn('Checking for live games…', false);

    if (!apiConfigured()) {
      if (cancelled) return;
      clear(listHolder);
      setConn('No server configured', false);
      listHolder.appendChild(emptyState({
        iconName: 'server',
        title: 'No server configured',
        hint: 'Live games come from a backend — set one in Settings → Network. Offline play against the engine needs no server.',
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
      const res = await api.watchGames();
      if (cancelled || mySeq !== seq) return;
      const data = res && res.data ? res.data : {};
      const games = Array.isArray(data.games) ? data.games : null;
      const now = Date.now();

      clear(listHolder);

      if (games === null) {
        setConn('Unexpected response', false);
        listHolder.appendChild(emptyState({
          iconName: 'info',
          title: 'Unexpected server response',
          hint: 'GET /watch/games returned no games list, so there is nothing real to show.',
          action: retryButton(),
        }));
        return;
      }

      if (!games.length) {
        setConn(`No live games · updated ${relativeTime(now)}`, true);
        listHolder.appendChild(emptyState({
          iconName: 'globe',
          title: 'No live games right now',
          hint: 'The server has no matches in progress. Games appear here the moment real players start one.',
          action: retryButton(),
        }));
        return;
      }

      setConn(`${games.length} live game${games.length === 1 ? '' : 's'} · updated ${relativeTime(now)}`, true);
      const cards = games.map(readGame).filter(Boolean);
      if (!cards.length) {
        listHolder.appendChild(emptyState({
          iconName: 'info',
          title: 'Nothing renderable in the response',
          hint: 'The server sent games, but none contained recognisable fields.',
          action: retryButton(),
        }));
        return;
      }
      listHolder.appendChild(section('In progress', el('span', { class: 'tiny muted', text: 'server-published' })));
      for (const g of cards) listHolder.appendChild(card(g));
    } catch (err) {
      if (cancelled || mySeq !== seq) return;
      clear(listHolder);
      const code = err && err.code ? err.code : 'UNKNOWN';

      if (isOfflineError(err)) {
        setConn('Offline', false);
        listHolder.appendChild(emptyState({
          iconName: 'wifiOff',
          title: navigator.onLine === false ? 'You are offline' : 'Could not reach the server',
          hint: 'Live games need a connection to your configured server.',
          action: retryButton(),
        }));
        return;
      }
      if (code === 'NOT_CONFIGURED') {
        setConn('No server configured', false);
        listHolder.appendChild(emptyState({
          iconName: 'server',
          title: 'No server configured',
          hint: 'Set a backend in Settings → Network to watch live games.',
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
        setConn('Watch unavailable', false);
        listHolder.appendChild(emptyState({
          iconName: 'server',
          title: 'This server does not publish live games',
          hint: 'GET /watch/games returned 404. Nothing is being hidden — there is simply no feed here.',
          action: retryButton(),
        }));
        return;
      }
      setConn('Request failed', false);
      listHolder.appendChild(emptyState({
        iconName: 'info',
        title: 'Could not reach the server',
        hint: `The request failed (${code}).`,
        action: retryButton(),
      }));
    }
  }

  function retryButton() {
    return el('button', {
      class: 'btn btn--primary mt-2',
      type: 'button',
      onClick: () => load(),
    }, icon('refresh', 18), el('span', { text: 'Retry' }));
  }

  // A live feed that never refreshes is a screenshot, not a feed.
  const onConnection = (ev) => { if (ev.detail && ev.detail.online) load(); };
  window.addEventListener('cm:connection', onConnection);
  timer = setInterval(() => {
    if (document.visibilityState === 'hidden') return;
    load();
  }, REFRESH_MS);

  load();

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => {
      cancelled = true;
      if (timer) clearInterval(timer);
      timer = null;
      window.removeEventListener('cm:connection', onConnection);
    },
  };
}
