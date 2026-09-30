/**
 * Settings — every preference the app actually supports, applied live.
 *
 * Rules honoured here:
 *  - each control writes through store.updateSettings(), which persists to
 *    localStorage and re-applies the document attributes immediately;
 *  - the board/piece swatches are the exact token values (the test suite
 *    asserts BOARD_THEMES against css/tokens.css so they cannot drift);
 *  - the Network test calls the real /health endpoint and reports exactly
 *    what happened: unconfigured, unreachable, HTTP error or latency —
 *    never a generic "connected";
 *  - language shows the only bundled locale instead of a fake picker.
 */

import { el, clear } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, toast, confirm, modal } from '../ui/components.js';
import {
  getSetting, updateSettings, resetSettings, DEFAULT_SETTINGS,
  appVersion, platformInfo, playSound, haptic,
} from '../store.js';
import { ping, apiConfigured, isOfflineError } from '../api.js';
import { pieceSvg } from '../chess/pieces.js';
import { go } from '../router.js';

/** Must match css/tokens.css [data-board-theme=…] exactly (asserted by tests). */
export const BOARD_THEMES = [
  { id: 'classic', label: 'Classic', light: '#eeeed2', dark: '#769656' },
  { id: 'ice', label: 'Ice', light: '#dee3e6', dark: '#8ca2ad' },
  { id: 'walnut', label: 'Walnut', light: '#f0d9b5', dark: '#b58863' },
  { id: 'midnight', label: 'Midnight', light: '#c8d0e0', dark: '#4a5570' },
  { id: 'tournament', label: 'Tournament', light: '#ffffdd', dark: '#86a666' },
];

/** Must match pieces.js theme ids (asserted by tests). */
export const PIECE_THEMES = [
  { id: 'classic', label: 'Classic' },
  { id: 'outline', label: 'Outline' },
  { id: 'solid', label: 'Solid' },
];

/** Exported for tests: every toggle key must exist in DEFAULT_SETTINGS. */
export const SWITCHES = [
  { key: 'confirmResign', label: 'Confirm before resigning', hint: 'A tap never ends a game on its own.' },
  { key: 'autoQueen', label: 'Auto-queen', hint: 'Promotions become queens instantly; turn off to choose the piece.' },
  { key: 'moveAnimation', label: 'Animate moves', hint: '' },
  { key: 'coords', label: 'Show board coordinates', hint: '' },
  { key: 'showRatings', label: 'Show ratings', hint: '' },
  { key: 'boardFlipped', label: 'Start games with Black at the bottom', hint: 'Play as White still places you at the bottom.' },
];

export function settingsScreen(ctx = {}) {
  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });

  root.appendChild(topbar({
    title: 'Settings',
    onBack: () => (ctx.router && typeof ctx.router.back === 'function' ? ctx.router.back() : go('/')),
  }));
  root.appendChild(scroll);

  // ------------------------------------------------------------- helpers
  function group(title, note = '') {
    const g = el('div', { class: 'setting-group' });
    g.appendChild(el('div', { class: 'setting-group__title' },
      el('span', { text: title }),
      note ? el('span', { class: 'tiny muted', text: note }) : null,
    ));
    const list = el('div', { class: 'list' });
    g.appendChild(list);
    scroll.appendChild(g);
    return list;
  }

  function switchRow(list, { key, label, hint }) {
    const initial = Boolean(getSetting(key));
    const row = el('button', {
      class: 'list__row',
      type: 'button',
      role: 'switch',
      'aria-checked': String(initial),
    });
    row.appendChild(el('span', { class: 'list__row__label' },
      el('div', { text: label }),
      hint ? el('div', { class: 'tiny muted', text: hint }) : null,
    ));
    const sw = el('span', { class: 'switch', 'aria-checked': String(initial), 'aria-hidden': 'true' });
    row.appendChild(sw);
    row.addEventListener('click', () => {
      const next = !getSetting(key);
      updateSettings({ [key]: next });
      row.setAttribute('aria-checked', String(next));
      sw.setAttribute('aria-checked', String(next));
      // Turning something on gives immediate, honest feedback.
      if (next && key === 'sound') playSound('tap');
      if (next && key === 'haptics') haptic(30);
    });
    list.appendChild(row);
    return row;
  }

  function valueRow(list, label, value, hint = '') {
    list.appendChild(el('div', { class: 'list__row' },
      el('span', { class: 'list__row__label' },
        el('div', { text: label }),
        hint ? el('div', { class: 'tiny muted', text: hint }) : null,
      ),
      el('span', { class: 'list__row__value', text: value }),
    ));
  }

  // -------------------------------------------------------------- paint
  function paint() {
    clear(scroll);

    // ---------------------------------------------------------- Board
    const boardList = group('Board', BOARD_THEMES.find((t) => t.id === getSetting('boardTheme'))?.label || '');
    const swatchRow = el('div', { class: 'chips' });
    boardList.appendChild(swatchRow);
    const swatchLabel = el('span', { class: 'tiny muted', style: { display: 'block', padding: '0 var(--sp-3) var(--sp-3)' } });
    for (const t of BOARD_THEMES) {
      const selected = getSetting('boardTheme') === t.id;
      const btn = el('button', {
        class: 'swatch',
        type: 'button',
        'aria-pressed': String(selected),
        'aria-label': `Board theme ${t.label}`,
        onClick: () => {
          updateSettings({ boardTheme: t.id });
          for (const [i, node] of Array.from(swatchRow.children).entries()) {
            const on = BOARD_THEMES[i].id === getSetting('boardTheme');
            node.setAttribute('aria-pressed', String(on));
            node.style.outline = on ? '2px solid var(--accent-green)' : 'none';
          }
          swatchLabel.textContent = `${t.label} — light ${t.light} · dark ${t.dark}`;
          boardList.parentElement.querySelector('.setting-group__title span:last-child').textContent = t.label;
        },
      },
        el('span', { class: 'swatch__board' },
          el('i', { style: { background: t.light } }),
          el('i', { style: { background: t.dark } }),
          el('i', { style: { background: t.dark } }),
          el('i', { style: { background: t.light } }),
        ),
      );
      btn.style.outline = selected ? '2px solid var(--accent-green)' : 'none';
      btn.style.outlineOffset = '1px';
      swatchRow.appendChild(btn);
    }
    const curTheme = BOARD_THEMES.find((t) => t.id === getSetting('boardTheme'));
    swatchLabel.textContent = curTheme
      ? `${curTheme.label} — light ${curTheme.light} · dark ${curTheme.dark}`
      : 'Custom value in storage';
    boardList.appendChild(swatchLabel);

    // --------------------------------------------------------- Pieces
    const pieceList = group('Pieces');
    const pieceRow = el('div', { class: 'chips' });
    pieceList.appendChild(pieceRow);
    for (const p of PIECE_THEMES) {
      const selected = getSetting('pieceTheme') === p.id;
      const btn = el('button', {
        class: 'piece-choice',
        type: 'button',
        'aria-pressed': String(selected),
        'aria-label': `Piece style ${p.label}`,
        style: { display: 'flex' },
        onClick: () => {
          updateSettings({ pieceTheme: p.id });
          for (const node of Array.from(pieceRow.children)) {
            node.setAttribute('aria-pressed', String(node.dataset.theme === getSetting('pieceTheme')));
          }
        },
      });
      btn.dataset.theme = p.id;
      btn.appendChild(pieceSvg('q', 'w', p.id));
      btn.appendChild(el('span', { class: 'tiny muted', text: p.label }));
      pieceRow.appendChild(btn);
    }

    // ------------------------------------------------------- Gameplay
    const gameplay = group('Gameplay');
    for (const s of SWITCHES) switchRow(gameplay, s);

    // ------------------------------------------------- Sound & haptics
    const feedback = group('Sound & haptics');
    switchRow(feedback, { key: 'sound', label: 'Sound effects', hint: 'Moves, captures, checks and results.' });
    switchRow(feedback, { key: 'haptics', label: 'Vibration', hint: 'Short pulse on captures and results (device support required).' });
    feedback.appendChild(el('button', {
      class: 'list__row',
      type: 'button',
      onClick: () => {
        if (!getSetting('sound')) {
          toast('Sound is off — turn it on first', { type: 'info' });
          return;
        }
        playSound('success');
        toast(getSetting('sound') ? 'Playing the result chime' : '', { type: 'info', durationMs: 1400 });
      },
    },
      el('span', { class: 'list__row__label', text: 'Play test sound' }),
      icon('sound', 18),
    ));

    // -------------------------------------------------- Accessibility
    const a11y = group('Accessibility');
    switchRow(a11y, { key: 'reduceMotion', label: 'Reduce motion', hint: 'Disables piece slide animations and screen transitions.' });

    // --------------------------------------------------------- Network
    const net = group('Network', apiConfigured() ? 'configured' : 'not configured');
    const note = el('div', { class: 'notice mt-2', style: { display: 'none' } });
    const fieldWrap = el('div', { style: { padding: 'var(--sp-3)' } });
    const input = el('input', {
      class: 'input',
      type: 'url',
      inputmode: 'url',
      autocomplete: 'off',
      spellcheck: 'false',
      placeholder: 'https://example.test/api',
      value: getSetting('backendUrl') || '',
    });
    const err = el('div', { class: 'field__error' });
    fieldWrap.appendChild(el('label', { class: 'field__label', text: 'Backend base URL' }));
    fieldWrap.appendChild(input);
    fieldWrap.appendChild(err);
    fieldWrap.appendChild(el('div', { class: 'tiny muted', text: 'Empty = offline only. HTTPS recommended; local play never needs it.' }));

    const buttons = el('div', { class: 'flex gap-2 mt-2' });
    function showNote(kind, iconName, text) {
      note.style.display = '';
      note.className = `notice mt-2${kind === 'warn' ? ' notice--warn' : kind === 'bad' ? ' notice--danger' : ''}`;
      clear(note);
      note.appendChild(icon(iconName, 16));
      note.appendChild(el('span', { text }));
    }

    buttons.appendChild(el('button', {
      class: 'btn btn--primary',
      type: 'button',
      onClick: () => {
        const url = input.value.trim();
        err.textContent = '';
        if (url && !/^https?:\/\/.+/i.test(url)) {
          err.textContent = 'Enter a full http:// or https:// URL, or leave empty for offline.';
          playSound('error');
          return;
        }
        updateSettings({ backendUrl: url });
        toast(url ? 'Backend URL saved' : 'Backend cleared — offline mode', { type: 'success', durationMs: 1600 });
        showNote('ok', 'check', url ? `Saved: ${url}` : 'Backend URL cleared.');
      },
    }, el('span', { text: 'Save' })));

    buttons.appendChild(el('button', {
      class: 'btn btn--secondary',
      type: 'button',
      onClick: async () => {
        if (!apiConfigured()) {
          showNote('warn', 'info', 'No backend URL configured — nothing was contacted.');
          return;
        }
        showNote('ok', 'clock', 'Contacting /health …');
        try {
          const res = await ping();
          showNote('ok', 'check', `Reachable — ${res.ms} ms${res.version ? ` · server ${res.version}` : ''}.`);
        } catch (e) {
          if (isOfflineError(e)) {
            showNote('bad', 'wifiOff', 'Unreachable: no network route to that URL (offline, wrong host, or TLS refused).');
          } else if (e && typeof e.status === 'number') {
            showNote('bad', 'info', `Server answered HTTP ${e.status}${e.message ? ` — ${e.message}` : ''}.`);
          } else {
            showNote('bad', 'info', `Request failed: ${e && e.message ? e.message : String(e)}`);
          }
        }
      },
    }, icon('wifi', 18), el('span', { text: 'Test' })));

    fieldWrap.appendChild(buttons);
    fieldWrap.appendChild(note);
    net.appendChild(fieldWrap);

    // ---------------------------------------------------------- About
    const about = group('About');
    valueRow(about, 'Version', appVersion());
    valueRow(about, 'Platform', platformInfo());
    valueRow(about, 'Language', 'English', 'The only bundled locale — no fake language picker.');
    valueRow(about, 'Content', 'Bundled offline', 'Board, puzzles and lessons ship inside the APK.');
    about.appendChild(el('button', {
      class: 'list__row',
      type: 'button',
      onClick: () => modal({
        title: 'Licenses',
        body: el('div', {},
          el('p', { class: 'muted small', text: 'chess.js 1.4.0 — Copyright (c) Jeff Hlywa and contributors. Licensed under the BSD 2-Clause License; bundled at app/js/vendor/chess.js with its license header intact.' }),
          el('p', { class: 'muted small', text: 'All other assets (board art, icons, sounds, copy) are original work for this app. No CDN assets, no third-party fonts or trackers.' }),
        ),
        actions: [el('button', { class: 'btn btn--primary', type: 'button', text: 'Close' })],
      }),
    }, el('span', { class: 'list__row__label', text: 'Licenses' }), icon('chevron', 18)));

    // ---------------------------------------------------- Reset (danger)
    const resetGroup = el('div', { class: 'setting-group mt-3' });
    resetGroup.appendChild(el('div', { class: 'setting-group__title' }, el('span', { text: 'Reset' })));
    const resetList = el('div', { class: 'list' });
    resetList.appendChild(el('button', {
      class: 'list__row',
      type: 'button',
      onClick: async () => {
        const yes = await confirm({
          title: 'Reset all settings?',
          message: `Every preference returns to its default (${DEFAULT_SETTINGS.boardTheme} board, sounds on, …). Games, puzzles and lesson progress are not touched.`,
          confirmLabel: 'Reset settings',
          danger: true,
        });
        if (!yes) return;
        resetSettings();
        toast('Settings reset to defaults', { type: 'success', durationMs: 1600 });
        paint();
      },
    },
      el('span', { class: 'list__row__label' },
        el('div', { text: 'Reset settings to defaults' }),
        el('div', { class: 'tiny muted', text: 'Does not delete games or progress.' }),
      ),
      icon('refresh', 18),
    ));
    resetGroup.appendChild(resetList);
    scroll.appendChild(resetGroup);

    scroll.appendChild(el('p', { class: 'tiny muted center mt-3', text: 'Games, puzzle progress and account data live under More → Privacy.' }));
  }

  paint();

  return { el: root, keepScreenOn: false };
}
