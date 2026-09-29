/**
 * App-wide preferences + session state.
 *
 * Persistence rules:
 *  - settings/preferences  -> localStorage          (non-sensitive, allowed)
 *  - auth tokens           -> Android Keystore via the narrow native bridge,
 *                             falling back to localStorage only when the bridge
 *                             is unavailable (browser/dev builds).
 */

const SETTINGS_KEY = 'cm.settings.v1';

export const DEFAULT_SETTINGS = {
  boardTheme: 'classic',      // classic|ice|walnut|midnight|tournament
  pieceTheme: 'classic',      // classic|outline|solid
  sound: true,
  haptics: true,
  coords: true,
  confirmResign: true,
  reduceMotion: false,
  moveAnimation: true,
  showRatings: true,
  autoQueen: false,           // promotion helper (still offers all pieces when off)
  boardFlipped: false,
  backendUrl: '',             // empty => not configured; set in Settings > Network
  language: 'en',
  seenOnboarding: false,
  guestId: '',
};

function readJSON(key, fallback) {
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return fallback;
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : fallback;
  } catch {
    return fallback;
  }
}

let settings = { ...DEFAULT_SETTINGS, ...readJSON(SETTINGS_KEY, {}) };
const listeners = new Set();

function persistSettings() {
  try {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
  } catch {
    /* storage full/blocked: keep running with in-memory settings */
  }
}

export function getSettings() {
  return settings;
}

export function getSetting(key) {
  return settings[key];
}

export function updateSettings(patch) {
  let changed = false;
  for (const [k, v] of Object.entries(patch)) {
    if (settings[k] !== v) {
      settings[k] = v;
      changed = true;
    }
  }
  if (!changed) return settings;
  persistSettings();
  applyDocumentSettings();
  for (const fn of listeners) fn(settings, patch);
  return settings;
}

export function resetSettings() {
  settings = { ...DEFAULT_SETTINGS, guestId: settings.guestId };
  persistSettings();
  applyDocumentSettings();
  for (const fn of listeners) fn(settings, '*');
  return settings;
}

export function subscribe(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

/** Mirror settings into <html data-*> so CSS can react. */
export function applyDocumentSettings() {
  const root = document.documentElement;
  root.dataset.boardTheme = settings.boardTheme;
  root.dataset.pieceTheme = settings.pieceTheme;
  root.dataset.coords = settings.coords ? '1' : '0';
  root.dataset.reduceMotion = settings.reduceMotion ? '1' : '0';
}

/** Stable per-install guest id (not a credential). */
export function ensureGuestId() {
  if (settings.guestId) return settings.guestId;
  const id = `guest_${Math.random().toString(36).slice(2, 10)}${Date.now().toString(36).slice(-4)}`;
  updateSettings({ guestId: id });
  return id;
}

// ---------------------------------------------------------------- session

let session = null; // { id, email, displayName, emailVerified, ratings }

export function getSession() {
  return session;
}

export function setSession(next) {
  session = next;
  for (const fn of listeners) fn(settings, 'session');
  return session;
}

export function isLoggedIn() {
  return Boolean(session && session.id);
}

// ------------------------------------------------------------ secure store

const nativeStore = typeof CheckmateNative !== 'undefined' ? CheckmateNative : null;

export function secureSet(key, value) {
  try {
    if (nativeStore && typeof nativeStore.secureSet === 'function') {
      nativeStore.secureSet(key, value === null || value === undefined ? '' : String(value));
      return true;
    }
    localStorage.setItem(`cm.sec.${key}`, String(value ?? ''));
    return true;
  } catch {
    return false;
  }
}

export function secureGet(key) {
  try {
    if (nativeStore && typeof nativeStore.secureGet === 'function') {
      const v = nativeStore.secureGet(key);
      return v === null || v === undefined ? '' : String(v);
    }
    return localStorage.getItem(`cm.sec.${key}`) || '';
  } catch {
    return '';
  }
}

export function secureDelete(key) {
  try {
    if (nativeStore && typeof nativeStore.secureDelete === 'function') {
      nativeStore.secureDelete(key);
    }
    localStorage.removeItem(`cm.sec.${key}`);
    return true;
  } catch {
    return false;
  }
}

// ----------------------------------------------------------------- audio

let audioCtx = null;

function ctx() {
  if (audioCtx) return audioCtx;
  try {
    const AC = window.AudioContext || window.webkitAudioContext;
    audioCtx = AC ? new AC() : null;
  } catch {
    audioCtx = null;
  }
  return audioCtx;
}

const TONES = {
  move: [420, 0.05, 'sine'],
  capture: [300, 0.07, 'triangle'],
  check: [660, 0.14, 'square'],
  low: [880, 0.06, 'sine'],
  end: [520, 0.35, 'triangle'],
  tap: [520, 0.03, 'sine'],
  error: [180, 0.16, 'sawtooth'],
  success: [740, 0.18, 'sine'],
};

/** Small synthesised feedback — no audio assets are bundled. */
export function playSound(name) {
  if (!settings.sound) return;
  const spec = TONES[name];
  if (!spec) return;
  const ac = ctx();
  if (!ac) return;
  try {
    if (ac.state === 'suspended') ac.resume();
    const [freq, dur, type] = spec;
    const osc = ac.createOscillator();
    const gain = ac.createGain();
    osc.type = type;
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.0001, ac.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.16, ac.currentTime + 0.008);
    gain.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + dur);
    osc.connect(gain).connect(ac.destination);
    osc.start();
    osc.stop(ac.currentTime + dur + 0.02);
  } catch {
    /* audio blocked until first gesture — ignore */
  }
}

/** Haptics via native bridge, with navigator.vibrate fallback. */
export function haptic(ms = 12) {
  if (!settings.haptics) return;
  try {
    if (nativeStore && typeof nativeStore.vibrate === 'function') {
      nativeStore.vibrate(ms);
      return;
    }
    if (navigator.vibrate) navigator.vibrate(ms);
  } catch {
    /* not supported */
  }
}

export function appVersion() {
  try {
    if (nativeStore && typeof nativeStore.versionName === 'function') return nativeStore.versionName();
  } catch { /* ignore */ }
  return '1.0.0';
}

export function platformInfo() {
  try {
    if (nativeStore && typeof nativeStore.platform === 'function') return nativeStore.platform();
  } catch { /* ignore */ }
  return 'browser';
}
