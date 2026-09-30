/**
 * Screen module graph + settings/lesson contracts.
 *
 * The app is a WebView shell: if any module in main.js's import graph is
 * missing or throws at load time, the app shows a blank splash forever.
 * This suite imports the real graph under a minimal DOM stub, so a missing
 * screen file (the exact bug class CI caught with js/data/) fails here.
 *
 * It also pins the settings board swatches to css/tokens.css so the
 * swatch previews cannot drift from the real board colours.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const noop = () => {};

// ------------------------------------------------ minimal DOM/browser stub
globalThis.window = {
  addEventListener: noop,
  removeEventListener: noop,
  dispatchEvent: noop,
  matchMedia: () => ({ matches: false, addEventListener: noop, removeEventListener: noop }),
};
globalThis.document = {
  readyState: 'loading', // => main.js registers a listener instead of booting
  addEventListener: noop,
  removeEventListener: noop,
  getElementById: () => null,
  documentElement: { dataset: {}, style: {} },
  createElement: () => ({
    style: {}, dataset: {}, classList: { add: noop, remove: noop },
    setAttribute: noop, appendChild: noop, addEventListener: noop,
  }),
};
globalThis.localStorage = {
  getItem: () => null,
  setItem: noop,
  removeItem: noop,
  clear: noop,
};
if (typeof globalThis.navigator === 'undefined') {
  globalThis.navigator = { userAgent: 'node-test', vibrate: noop };
}

const JS_ROOT = fileURLToPath(new URL('../../app/src/main/assets/app/js/', import.meta.url));
const importJs = (rel) => import(`file://${JS_ROOT}${rel}`);

// ------------------------------------------------------------ import graph

test('main.js import graph loads (all screens present)', async () => {
  const main = await importJs('main.js');
  assert.ok(Array.isArray(main.ROUTES) && main.ROUTES.length >= 10, 'route table registered');
  const paths = main.ROUTES.map((r) => r.path);
  for (const p of ['/', '/play', '/puzzles', '/learn', '/settings', '/review/:id', '/game']) {
    assert.ok(paths.includes(p), `route ${p} present`);
  }
  // every route points at a function screen
  for (const r of main.ROUTES) {
    assert.equal(typeof r.screen, 'function', `screen for ${r.path} is a function`);
  }
});

test('the four rebuilt screens expose their contracted exports', async () => {
  const checks = [
    ['screens/puzzles.js', ['puzzlesScreen', 'puzzleDetailScreen']],
    ['screens/learn.js', ['learnScreen', 'lessonDetailScreen']],
    ['screens/review.js', ['reviewScreen']],
    ['screens/settings.js', ['settingsScreen']],
  ];
  for (const [file, names] of checks) {
    const mod = await importJs(file);
    for (const name of names) {
      assert.ok(name in mod, `${file} exports ${name}`);
      assert.equal(typeof mod[name], 'function', `${file}.${name} is a function`);
    }
  }
  // data exports are arrays, not functions
  const settings = await importJs('screens/settings.js');
  assert.ok(Array.isArray(settings.BOARD_THEMES) && settings.BOARD_THEMES.length === 6, 'BOARD_THEMES lists six themes');
  assert.ok(Array.isArray(settings.PIECE_THEMES) && settings.PIECE_THEMES.length === 3, 'PIECE_THEMES is an array of three');
});

// ------------------------------------------------------ settings contracts

test('board swatches match css/tokens.css exactly', async () => {
  const { BOARD_THEMES } = await importJs('screens/settings.js');
  const css = readFileSync(`${JS_ROOT}../css/tokens.css`, 'utf8');
  assert.equal(BOARD_THEMES.length, 6, 'six board themes (incl. slate)');
  const ids = new Set();
  for (const t of BOARD_THEMES) {
    assert.ok(!ids.has(t.id), `duplicate theme id ${t.id}`);
    ids.add(t.id);
    const re = new RegExp(
      `\\[data-board-theme="${t.id}"\\]\\s*\\{\\s*--board-light:\\s*${t.light};\\s*--board-dark:\\s*${t.dark};\\s*\\}`,
      'i',
    );
    assert.ok(re.test(css), `theme ${t.id} (${t.light}/${t.dark}) exists in tokens.css`);
  }
});

test('every settings toggle key exists in DEFAULT_SETTINGS', async () => {
  const { SWITCHES, BOARD_THEMES, PIECE_THEMES } = await importJs('screens/settings.js');
  const { DEFAULT_SETTINGS } = await importJs('store.js');
  assert.ok(SWITCHES.length >= 5, 'gameplay toggles present');
  for (const s of SWITCHES) {
    assert.ok(s.key in DEFAULT_SETTINGS, `${s.key} is a known setting`);
    assert.ok(typeof s.label === 'string' && s.label.length > 0, `${s.key} has a label`);
  }
  // theme ids are valid settings values
  assert.ok(BOARD_THEMES.some((t) => t.id === DEFAULT_SETTINGS.boardTheme), 'default board theme is bundled');
  assert.ok(PIECE_THEMES.some((t) => t.id === DEFAULT_SETTINGS.pieceTheme), 'default piece theme is bundled');
  for (const key of ['sound', 'haptics', 'coords', 'reduceMotion', 'confirmResign', 'autoQueen']) {
    assert.ok(key in DEFAULT_SETTINGS, `${key} setting exists`);
  }
});
