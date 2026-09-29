/**
 * Statistics — on-device results first, server account stats second.
 *
 * The "On-device games" block is computed entirely from the IndexedDB records
 * this app itself wrote; the "Your account" block is only ever painted from a
 * real /stats/summary response. Every block names its source, the period
 * filter re-derives both sides, and empty periods explain themselves instead
 * of inventing numbers.
 */

import { el, clear, svgEl } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, section, toast, emptyState, skeleton, statTile } from '../ui/components.js';
import { getSession, isLoggedIn, setSession } from '../store.js';
import { api, apiConfigured, accessToken, isOfflineError } from '../api.js';
import { listGames } from '../db.js';
import { go } from '../router.js';

const PERIODS = [
  { id: '7d', label: '7d' },
  { id: '30d', label: '30d' },
  { id: 'all', label: 'All time' },
];

const PERIOD_MS = { '7d': 7 * 864e5, '30d': 30 * 864e5, all: 0 };
const PERIOD_TEXT = { '7d': 'last 7 days', '30d': 'last 30 days', all: 'all time' };

const MODE_LABELS = { local: 'Local (2 players)', bot: 'Computer', online: 'Online' };

/**
 * Classify a stored record from the single human player's point of view.
 * Returns 'win' | 'loss' | 'draw', or null when no honest answer exists
 * (unfinished game, shared-device game, or a record without a known side).
 */
function classify(record) {
  if (!record || !record.result || record.result === '*') return null;
  // Some writers store the verdict directly.
  if (record.result === 'win' || record.result === 'loss' || record.result === 'draw') return record.result;
  if (record.result === '1/2-1/2') return 'draw';
  const winner = record.winner || (record.result === '1-0' ? 'w' : record.result === '0-1' ? 'b' : null);
  if (!winner) return null;
  // Two humans on one device: there is no single "you" to attribute it to.
  if (record.mode === 'local') return null;
  if (!record.humanSide) return null;
  return winner === record.humanSide ? 'win' : 'loss';
}

function inPeriod(record, period) {
  const window = PERIOD_MS[period] || 0;
  if (!window) return true;
  const ts = record.finishedAt || record.savedAt || 0;
  return ts >= Date.now() - window;
}

function modeLabel(mode) {
  return MODE_LABELS[mode] || mode || 'Unknown';
}

/** Derive every local number once; rendering only reads these. */
function computeLocal(games) {
  const finished = games.filter((g) => g.result && g.result !== '*');
  const rows = finished.map((g) => ({ g, verdict: classify(g) }));

  let wins = 0;
  let losses = 0;
  let draws = 0;
  let unattributed = 0;
  for (const { verdict } of rows) {
    if (verdict === 'win') wins++;
    else if (verdict === 'loss') losses++;
    else if (verdict === 'draw') draws++;
    else unattributed++;
  }

  // Current streak: consecutive identical verdicts from the newest game back.
  let streak = null;
  for (const { verdict } of rows) {
    if (!verdict) break;
    if (streak && streak.verdict === verdict) streak.count++;
    else streak = { verdict, count: 1 };
  }

  const modes = new Map();
  for (const { g } of rows) modes.set(g.mode || 'unknown', (modes.get(g.mode || 'unknown') || 0) + 1);
  const modeRows = Array.from(modes, ([mode, count]) => ({ mode, count }))
    .sort((a, b) => b.count - a.count || a.mode.localeCompare(b.mode));

  // Move counts come from the recorded SAN list (ceil → full moves, as on Home).
  let moveSum = 0;
  let moveGames = 0;
  for (const { g } of rows) {
    if (Array.isArray(g.sans) && g.sans.length) {
      moveSum += Math.ceil(g.sans.length / 2);
      moveGames++;
    }
  }

  const decided = wins + losses + draws;
  return {
    total: finished.length,
    wins, losses, draws, unattributed,
    winPct: decided ? Math.round((wins / decided) * 100) : null,
    streak,
    modeRows,
    mostPlayed: modeRows[0] || null,
    avgMoves: moveGames ? Math.round(moveSum / moveGames) : null,
    moveGames,
  };
}

/** Server rating: last history point wins, then the documented numeric shapes. */
function pickRating(data) {
  const history = Array.isArray(data && data.history)
    ? data.history.filter((p) => p && typeof p.rating === 'number')
    : [];
  if (history.length) return history[history.length - 1].rating;
  if (typeof data?.rating === 'number') return data.rating;
  if (data?.rating && typeof data.rating === 'object' && typeof data.rating.rating === 'number') {
    return data.rating.rating;
  }
  return null;
}

function historyPoints(data) {
  return Array.isArray(data && data.history)
    ? data.history.filter((p) => p && typeof p.rating === 'number' && !Number.isNaN(Date.parse(p.ts || '')))
    : [];
}

/** Rating over time. Uniform scale, no fake smoothing, no invented points. */
function sparkline(points) {
  const w = 300;
  const h = 90;
  const pad = 8;
  const values = points.map((p) => p.rating);
  const min = Math.min(...values);
  const max = Math.max(...values);
  const span = max - min || 1;
  const px = (i) => pad + (i * (w - pad * 2)) / Math.max(1, points.length - 1);
  const py = (v) => h - pad - ((v - min) / span) * (h - pad * 2);
  const line = points.map((p, i) => `${i ? 'L' : 'M'}${px(i).toFixed(1)} ${py(p.rating).toFixed(1)}`).join(' ');
  const area = points.length > 1
    ? `${line} L${px(points.length - 1).toFixed(1)} ${h} L${px(0).toFixed(1)} ${h} Z`
    : '';
  return svgEl('svg', {
    class: 'spark',
    viewBox: `0 0 ${w} ${h}`,
    preserveAspectRatio: 'none',
    role: 'img',
    'aria-label': `Rating history over ${points.length} recorded points`,
  },
    svgEl('line', { class: 'spark__grid', x1: 0, y1: h / 2, x2: w, y2: h / 2, 'vector-effect': 'non-scaling-stroke' }),
    area ? svgEl('path', { class: 'spark__area', d: area }) : null,
    svgEl('path', { class: 'spark__line', d: line, 'vector-effect': 'non-scaling-stroke' }),
  );
}

export function statsScreen(ctx = {}) {
  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });

  let cancelled = false;
  let period = '30d';
  let allGames = [];      // every local record, loaded once, filtered per render
  let localLoaded = false;

  root.appendChild(topbar({
    title: 'Statistics',
    onBack: () => (ctx.router && typeof ctx.router.back === 'function' ? ctx.router.back() : go('/')),
  }));
  root.appendChild(scroll);

  // ------------------------------------------------------------- period
  const periodFilter = el('div', { class: 'period-filter mt-2' });
  const periodChips = PERIODS.map((p) => el('button', {
    class: 'chip',
    type: 'button',
    'aria-pressed': String(p.id === period),
    text: p.label,
    onClick: () => {
      if (period === p.id) return;
      period = p.id;
      for (const [i, chip] of periodChips.entries()) {
        chip.setAttribute('aria-pressed', String(PERIODS[i].id === period));
      }
      paintLocal();
      loadServer();
    },
  }));
  periodFilter.appendChild(el('span', { class: 'tiny muted', text: 'Period' }));
  periodFilter.appendChild(...periodChips);

  const localHolder = el('div', {});
  const serverHolder = el('div', {});

  scroll.appendChild(periodFilter);
  scroll.appendChild(section('On-device games', el('span', { class: 'tiny muted', text: 'this device' })));
  scroll.appendChild(localHolder);
  scroll.appendChild(section('Your account', el('span', { class: 'tiny muted', text: 'server' })));
  scroll.appendChild(serverHolder);

  // ------------------------------------------------------- local block
  async function loadLocal() {
    clear(localHolder);
    localHolder.appendChild(skeleton(72, 3));
    try {
      const games = await listGames({});
      if (cancelled) return;
      allGames = Array.isArray(games) ? games : [];
      localLoaded = true;
      paintLocal();
    } catch {
      if (cancelled) return;
      clear(localHolder);
      localHolder.appendChild(emptyState({
        iconName: 'board',
        title: 'Could not read local games',
        hint: 'The on-device database did not answer. Try reopening this screen.',
      }));
    }
  }

  function paintLocal() {
    if (!localLoaded) return;
    const games = allGames.filter((g) => inPeriod(g, period));
    const stats = computeLocal(games);
    clear(localHolder);

    if (!stats.total) {
      const anyAtAll = computeLocal(allGames).total;
      localHolder.appendChild(emptyState({
        iconName: 'board',
        title: anyAtAll ? 'No finished games in this period' : 'No games yet',
        hint: anyAtAll
          ? `Nothing finished in the ${PERIOD_TEXT[period]}. Switch to “All time” to see your full history.`
          : 'Finished games are stored on this device and appear here — nothing is shown until you actually play.',
        action: el('button', {
          class: 'btn btn--primary mt-2',
          type: 'button',
          text: 'Start a game',
          onClick: () => go('/play'),
        }),
      }));
      return;
    }

    const decided = stats.wins + stats.losses + stats.draws;
    const streakText = stats.streak
      ? `${stats.streak.count}${stats.streak.verdict === 'win' ? 'W' : stats.streak.verdict === 'loss' ? 'L' : 'D'}`
      : '—';

    localHolder.appendChild(el('div', { class: 'stat-grid' },
      statTile({ label: 'Games', value: String(stats.total) }),
      statTile({ label: 'Wins', value: String(stats.wins), tone: 'green' }),
      statTile({ label: 'Losses', value: String(stats.losses), tone: 'danger' }),
      statTile({ label: 'Draws', value: String(stats.draws) }),
      statTile({ label: 'Win rate', value: stats.winPct === null ? '—' : `${stats.winPct}%` }),
      statTile({ label: 'Current streak', value: streakText }),
    ));

    // Win/loss/draw share of the *attributed* games only.
    if (decided) {
      localHolder.appendChild(el('div', { class: 'mt-3' },
        el('div', { class: 'wdl' },
          el('i', { class: 'wdl__w', style: { width: `${(stats.wins / decided) * 100}%` } }),
          el('i', { class: 'wdl__d', style: { width: `${(stats.draws / decided) * 100}%` } }),
          el('i', { class: 'wdl__l', style: { width: `${(stats.losses / decided) * 100}%` } }),
        ),
        el('div', { class: 'wdl-legend' },
          el('span', { text: `${stats.wins} won` }),
          el('span', { text: `${stats.draws} drawn` }),
          el('span', { text: `${stats.losses} lost` }),
        ),
      ));
    }

    localHolder.appendChild(el('div', { class: 'stat-grid mt-3' },
      statTile({ label: 'Most played', value: stats.mostPlayed ? modeLabel(stats.mostPlayed.mode) : '—' }),
      statTile({ label: 'Avg. moves', value: stats.avgMoves === null ? '—' : String(stats.avgMoves) }),
      statTile({ label: 'Two-player', value: String(stats.modeRows.find((m) => m.mode === 'local')?.count || 0) }),
    ));

    // Mode breakdown (includes every finished game, attributed or not).
    const maxCount = stats.modeRows[0] ? stats.modeRows[0].count : 1;
    const bars = el('div', { class: 'mt-3' },
      stats.modeRows.map((m) => el('div', { class: 'bar-row mb-2' },
        el('div', { class: 'bar-row__label', text: modeLabel(m.mode) }),
        el('div', { class: 'bar-row__track' },
          el('i', {
            class: 'bar-row__fill',
            style: { width: `${(m.count / maxCount) * 100}%`, background: 'var(--accent-green)' },
          }),
        ),
        el('div', { class: 'bar-row__num', text: String(m.count) }),
      )),
    );
    localHolder.appendChild(bars);

    localHolder.appendChild(el('p', { class: 'tiny muted mt-2' },
      stats.avgMoves === null
        ? 'No recorded moves yet, so an average is not shown.'
        : `Average over ${stats.moveGames} game${stats.moveGames === 1 ? '' : 's'} with recorded moves, from the ${PERIOD_TEXT[period]}.`,
    ));

    if (stats.unattributed) {
      localHolder.appendChild(el('div', { class: 'notice mt-2' },
        icon('info', 16),
        el('div', {}, `${stats.unattributed} finished game${stats.unattributed === 1 ? '' : 's'} ${
          stats.unattributed === 1 ? 'is' : 'are'} not counted in wins/losses: ${
          stats.modeRows.find((m) => m.mode === 'local') ? 'shared-device games have no single winner "you", ' : ''}and older records may not name the side you played.`),
      ));
    }
  }

  // ------------------------------------------------------ server block
  /**
   * There is no boot-time session restore anywhere else in the app, so a
   * stored access token is redeemed here once before deciding the player is
   * signed out.
   */
  async function ensureSession() {
    if (isLoggedIn()) return getSession();
    if (!accessToken() || !apiConfigured()) return null;
    try {
      const res = await api.me();
      const user = res && res.data ? res.data.user : null;
      if (user) {
        setSession(user);
        return user;
      }
    } catch {
      // Expired/revoked token: stay signed out and say so below.
    }
    return null;
  }

  function serverNotice(text, action = null, tone = '') {
    return el('div', { class: `notice ${tone}`.trim() },
      icon(tone === 'notice--warn' ? 'info' : 'wifiOff', 16),
      el('div', {},
        el('div', { text }),
        action),
    );
  }

  function retryButton(onClick) {
    return el('button', { class: 'btn btn--secondary btn--sm mt-2', type: 'button', onClick },
      icon('refresh', 16), el('span', { text: 'Retry' }));
  }

  async function loadServer() {
    clear(serverHolder);
    serverHolder.appendChild(skeleton(72, 3));

    if (!apiConfigured()) {
      if (cancelled) return;
      clear(serverHolder);
      serverHolder.appendChild(emptyState({
        iconName: 'server',
        title: 'No server configured',
        hint: 'Server ratings and history need a backend — set one in Settings → Network. Local games above work without it.',
        action: el('button', {
          class: 'btn btn--secondary mt-2',
          type: 'button',
          text: 'Open Settings',
          onClick: () => go('/settings'),
        }),
      }));
      return;
    }

    const user = await ensureSession();
    if (cancelled) return;
    if (!user) {
      clear(serverHolder);
      serverHolder.appendChild(emptyState({
        iconName: 'user',
        title: 'Not signed in',
        hint: 'Signing in keeps rated results, ratings and history on your account — synced across devices. Your on-device games above already work without it.',
        action: el('button', {
          class: 'btn btn--primary mt-2',
          type: 'button',
          text: 'Sign in',
          onClick: () => go('/auth'),
        }),
      }));
      return;
    }

    try {
      const res = await api.statsSummary(period);
      if (cancelled) return;
      clear(serverHolder);
      paintServer(res && res.data ? res.data : {});
    } catch (err) {
      if (cancelled) return;
      clear(serverHolder);
      paintServerError(err);
    }
  }

  function paintServer(data) {
    const points = historyPoints(data);
    const rating = pickRating(data);
    const totals = data && typeof data.totals === 'object' && data.totals ? data.totals : null;
    const periodText = PERIOD_TEXT[period];

    if (rating === null && !totals && !points.length) {
      serverHolder.appendChild(emptyState({
        iconName: 'chart',
        title: 'Nothing recorded yet',
        hint: `Your server account has no statistics for the ${periodText}. Play rated games and they will appear here.`,
      }));
      return;
    }

    if (rating !== null) {
      const delta = points.length > 1 ? points[points.length - 1].rating - points[0].rating : 0;
      serverHolder.appendChild(el('div', { class: 'rating-hero' },
        el('div', {},
          el('div', { class: 'rating-hero__label', text: `Rating · server · ${periodText}` }),
          el('div', { class: 'rating-hero__value', text: String(rating) }),
          points.length > 1 && delta !== 0
            ? el('div', {
                class: `rating-hero__delta ${delta > 0 ? 'rating-hero__delta--up' : 'rating-hero__delta--down'}`,
                text: `${delta > 0 ? '+' : ''}${delta} across the ${periodText}`,
              })
            : null,
        ),
      ));
    }

    if (points.length > 1) {
      serverHolder.appendChild(el('div', { class: 'card mt-3' },
        el('div', { class: 'tiny muted mb-2', text: `Rating history · ${periodText} · server account` }),
        sparkline(points),
      ));
    } else if (points.length === 1) {
      serverHolder.appendChild(el('p', { class: 'tiny muted mt-2',
        text: 'Only one rating point in this period — not enough for a line yet.' }));
    }

    const tiles = [];
    const numeric = (key) => (typeof totals?.[key] === 'number' ? totals[key] : null);
    if (numeric('played') !== null) tiles.push(statTile({ label: 'Played', value: String(numeric('played')) }));
    if (numeric('wins') !== null) tiles.push(statTile({ label: 'Wins', value: String(numeric('wins')), tone: 'green' }));
    if (numeric('losses') !== null) tiles.push(statTile({ label: 'Losses', value: String(numeric('losses')), tone: 'danger' }));
    if (numeric('draws') !== null) tiles.push(statTile({ label: 'Draws', value: String(numeric('draws')) }));
    if (numeric('win_pct') !== null) {
      // The API documents an integer percentage; a fractional value is a ratio.
      const pct = numeric('win_pct');
      const shown = Number.isInteger(pct) ? pct : Math.round(pct * 100);
      tiles.push(statTile({ label: 'Win %', value: `${shown}%` }));
    }
    if (numeric('streak') !== null) tiles.push(statTile({ label: 'Streak', value: `${numeric('streak')}` }));
    if (tiles.length) serverHolder.appendChild(el('div', { class: 'stat-grid mt-3' }, tiles));

    serverHolder.appendChild(el('p', { class: 'tiny muted mt-2',
      text: `Figures above come from your server account for the ${periodText}; the block above is on-device.` }));
  }

  function paintServerError(err) {
    const code = err && err.code ? err.code : 'UNKNOWN';

    if (isOfflineError(err)) {
      serverHolder.appendChild(emptyState({
        iconName: 'wifiOff',
        title: navigator.onLine === false ? 'You are offline' : 'Could not reach the server',
        hint: 'Server statistics need a connection. Your on-device games above are unaffected.',
        action: retryButton(() => loadServer()),
      }));
      return;
    }
    if (code === 'NOT_CONFIGURED') {
      serverHolder.appendChild(emptyState({
        iconName: 'server',
        title: 'No server configured',
        hint: 'Set a backend in Settings → Network to see account statistics.',
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
      serverHolder.appendChild(serverNotice(
        'This server does not offer statistics yet (GET /stats/summary returned 404). Nothing to show — and nothing invented.',
        null,
        'notice--warn',
      ));
      return;
    }
    if (code === 'UNAUTHORIZED' || code === 'TOKEN_EXPIRED' || code === 'TOKEN_INVALID') {
      serverHolder.appendChild(serverNotice(
        'Your session has expired. Sign in again to see account statistics.',
        el('button', {
          class: 'btn btn--secondary btn--sm mt-2',
          type: 'button',
          text: 'Sign in',
          onClick: () => go('/auth'),
        }),
        'notice--warn',
      ));
      return;
    }
    serverHolder.appendChild(emptyState({
      iconName: 'info',
      title: 'Statistics unavailable',
      hint: `The server responded with an error (${code}). Your on-device games above are unaffected.`,
      action: retryButton(() => loadServer()),
    }));
  }

  loadLocal();
  loadServer();

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => { cancelled = true; },
  };
}
