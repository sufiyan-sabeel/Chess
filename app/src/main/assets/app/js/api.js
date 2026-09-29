/**
 * REST client for the Checkmate API (docs/API.md).
 *
 * Design notes:
 *  - every call goes through here so envelope/error handling is consistent;
 *  - tokens live in the secure store (native bridge when available) and are
 *    attached automatically; a 401 triggers ONE refresh attempt and a retry;
 *  - when no backend is configured (Settings > Network) or the device is
 *    offline, calls fail fast with `offline: true` so screens can render an
 *    honest "not available" state instead of fabricating data;
 *  - no request is ever made to anything but the configured base URL.
 */

import { secureGet, secureSet, secureDelete, getSetting } from './store.js';

const ACCESS_KEY = 'access';
const REFRESH_KEY = 'refresh';

export class ApiError extends Error {
  constructor(code, message, status = 0) {
    super(message || code);
    this.code = code || 'INTERNAL_ERROR';
    this.status = status;
  }
}

export function apiConfigured() {
  const base = String(getSetting('backendUrl') || '').trim();
  return base.length > 0;
}

export function apiBase() {
  return String(getSetting('backendUrl') || '').trim().replace(/\/+$/, '');
}

export function isOfflineError(err) {
  return Boolean(err && (err.code === 'NETWORK_ERROR' || err.code === 'OFFLINE'));
}

// ---------------------------------------------------------------- tokens

export function setTokens({ access, refresh }) {
  if (access) secureSet(ACCESS_KEY, access);
  if (refresh) secureSet(REFRESH_KEY, refresh);
}

export function clearTokens() {
  secureDelete(ACCESS_KEY);
  secureDelete(REFRESH_KEY);
}

export function accessToken() {
  return secureGet(ACCESS_KEY) || '';
}

export function refreshToken() {
  return secureGet(REFRESH_KEY) || '';
}

// --------------------------------------------------------------- requests

function newRequestId() {
  const bytes = new Uint8Array(12);
  (globalThis.crypto || {}).getRandomValues?.(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

async function rawRequest(method, path, { body = null, auth = true, retried = false } = {}) {
  const base = apiBase();
  if (!base) throw new ApiError('NOT_CONFIGURED', 'No server configured.', 0);

  const headers = {
    Accept: 'application/json',
    'X-Request-Id': newRequestId(),
  };
  if (body !== null && body !== undefined) headers['Content-Type'] = 'application/json';
  if (auth) {
    const token = accessToken();
    if (token) headers.Authorization = `Bearer ${token}`;
  }

  let res;
  const started = Date.now();
  try {
    res = await fetch(`${base}${path}`, {
      method,
      headers,
      body: body === null || body === undefined ? undefined : JSON.stringify(body),
      credentials: 'omit',
      cache: 'no-store',
      mode: 'cors',
    });
  } catch (e) {
    // Distinguish "no network" from "server refused".
    const offline = typeof navigator !== 'undefined' && navigator.onLine === false;
    throw new ApiError(offline ? 'OFFLINE' : 'NETWORK_ERROR',
      offline ? 'You are offline.' : 'Could not reach the server.', 0);
  }

  let payload = null;
  const text = await res.text().catch(() => '');
  if (text) {
    try { payload = JSON.parse(text); } catch { payload = null; }
  }

  const envelope = payload && typeof payload === 'object' ? payload : {};
  const requestId = res.headers.get('X-Request-Id') || '';

  if (res.status === 401 && auth && !retried && refreshToken()) {
    const refreshed = await tryRefresh();
    if (refreshed) return rawRequest(method, path, { body, auth, retried: true });
  }

  if (!res.ok || envelope.success === false) {
    const err = envelope && envelope.error ? envelope.error : {};
    throw new ApiError(err.code || `HTTP_${res.status}`, err.message || `Request failed (${res.status}).`, res.status);
  }

  return {
    data: envelope.data === undefined ? null : envelope.data,
    status: res.status,
    requestId,
    elapsedMs: Date.now() - started,
  };
}

let refreshInFlight = null;

async function tryRefresh() {
  if (refreshInFlight) return refreshInFlight;
  refreshInFlight = (async () => {
    try {
      const rt = refreshToken();
      if (!rt) return false;
      const res = await rawRequest('POST', '/auth/refresh', {
        body: { refresh_token: rt },
        auth: false,
        retried: true,
      });
      const d = res.data || {};
      if (!d.access_token) return false;
      setTokens({ access: d.access_token, refresh: d.refresh_token });
      return true;
    } catch (e) {
      clearTokens();
      return false;
    } finally {
      refreshInFlight = null;
    }
  })();
  return refreshInFlight;
}

/** Generic call. `path` must start with `/`. */
export function request(method, path, body = null, opts = {}) {
  return rawRequest(method.toUpperCase(), path, { body, ...opts });
}

// ------------------------------------------------------------- endpoints

export const api = {
  // ---- auth (docs/API.md §1)
  register: (payload) => request('POST', '/auth/register', payload, { auth: false }),
  login: (payload) => request('POST', '/auth/login', payload, { auth: false }),
  refresh: () => request('POST', '/auth/refresh', { refresh_token: refreshToken() }, { auth: false }),
  logout: (refresh = refreshToken()) => request('POST', '/auth/logout', { refresh_token: refresh }),
  me: () => request('GET', '/auth/me'),
  verifyEmail: (token) => request('POST', '/auth/verify-email', { token }, { auth: false }),
  resendVerification: (email) => request('POST', '/auth/resend-verification', email ? { email } : null),
  forgotPassword: (email) => request('POST', '/auth/forgot-password', { email }, { auth: false }),
  resetPassword: (token, password) => request('POST', '/auth/reset-password', { token, password }, { auth: false }),
  deleteAccount: (password) => request('DELETE', '/account', { password }),

  // ---- health
  health: () => request('GET', '/health', null, { auth: false }),
  ready: () => request('GET', '/ready', null, { auth: false }),

  // ---- matchmaking & matches (§3)
  queueQuick: (payload) => request('POST', '/matchmaking/quick', payload),
  queueCancel: (queueId) => request('POST', '/matchmaking/cancel', { queue_id: queueId }),
  queueStatus: () => request('GET', '/matchmaking/status'),
  createMatch: (payload) => request('POST', '/matches', payload),
  joinMatch: (inviteCode) => request('POST', '/matches/join', { invite_code: inviteCode }),
  getMatch: (id) => request('GET', `/matches/${encodeURIComponent(id)}`),
  sendSignal: (id, payload) => request('POST', `/matches/${encodeURIComponent(id)}/signal`, payload),
  pollSignal: (id, since = 0) => request('GET', `/matches/${encodeURIComponent(id)}/signal?since=${encodeURIComponent(since)}`),
  sendEvent: (id, payload) => request('POST', `/matches/${encodeURIComponent(id)}/events`, payload),
  matchHistory: (id) => request('GET', `/matches/${encodeURIComponent(id)}/history`),

  // ---- games / history sync (§4)
  uploadGame: (payload) => request('POST', '/games', payload),
  listGames: (params = {}) => request('GET', `/games${qs(params)}`),
  getGame: (id) => request('GET', `/games/${encodeURIComponent(id)}`),
  getGamePgn: (id) => request('GET', `/games/${encodeURIComponent(id)}/pgn`),

  // ---- statistics / leaderboard / players (§5-§7)
  statsSummary: (period = '30d') => request('GET', `/stats/summary?period=${encodeURIComponent(period)}`),
  leaderboard: (params = {}) => request('GET', `/leaderboard${qs(params)}`),
  leaderboardMe: (mode = 'blitz') => request('GET', `/leaderboard/me?mode=${encodeURIComponent(mode)}`),
  player: (id) => request('GET', `/players/${encodeURIComponent(id)}`),

  // ---- puzzles / watch (§8-§9)
  puzzles: (params = {}) => request('GET', `/puzzles${qs(params)}`),
  attemptPuzzle: (id, payload) => request('POST', `/puzzles/${encodeURIComponent(id)}/attempt`, payload),
  watchGames: () => request('GET', '/watch/games'),
};

function qs(params) {
  const parts = Object.entries(params || {})
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`);
  return parts.length ? `?${parts.join('&')}` : '';
}

/** Health probe used by Settings and by the online screens. */
export async function ping() {
  const started = Date.now();
  const res = await api.health();
  return { ok: true, ms: Date.now() - started, ...res.data };
}
