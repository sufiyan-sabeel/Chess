/**
 * Play — mode selection.
 *
 * Local game (two players), Computer (offline engine, difficulty + time
 * control), Online (real accounts, requires sign-in), plus a custom time
 * control builder.
 *
 * Online play is only offered when a backend is configured and the player is
 * signed in; otherwise the button explains exactly what is missing instead of
 * pretending to search for opponents.
 */

import { el, clear } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, toast, modal, section, emptyState } from '../ui/components.js';
import { PRESETS, customControl, presetById } from '../chess/game.js';
import { LEVELS } from '../chess/bot.js';
import { getSettings, updateSettings, getSession, isLoggedIn } from '../store.js';
import { apiConfigured } from '../api.js';
import { go } from '../router.js';

// Pending configuration handed to /game (kept in-module: no URL secrets).
let pending = null;

export function setPendingGame(config) {
  pending = config;
}

export function getPendingGame() {
  return pending;
}

export function playScreen() {
  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });
  const settings = getSettings();

  let selectedPreset = settings.lastPreset || 'blitz-5';
  let level = settings.lastLevel || 'medium';
  let side = settings.lastSide || 'w';

  root.appendChild(topbar({ title: 'New game' }));
  root.appendChild(scroll);

  // ---------------------------------------------------------------- cards
  const modeGrid = el('div', { class: 'mode-grid' },
    modeCard({
      icon: 'users',
      title: 'Local',
      sub: 'Two players, one device. Clocks and rules fully enforced.',
      onClick: () => start('local'),
    }),
    modeCard({
      icon: 'bot',
      title: 'Computer',
      sub: 'Offline engine. Easy, medium or hard.',
      onClick: () => openComputer(),
    }),
    modeCard({
      icon: 'wifi',
      title: 'Online',
      sub: apiConfigured()
        ? (isLoggedIn() ? 'Queue for a rated game against a real account.' : 'Sign in to play rated games.')
        : 'No server configured in Settings → Network.',
      onClick: () => openOnline(),
    }),
    modeCard({
      icon: 'handshake',
      title: 'Invite',
      sub: 'Create or join a private game with a 6-character code.',
      onClick: () => openInvite(),
    }),
  );

  scroll.appendChild(modeGrid);

  // ------------------------------------------------------- time control
  scroll.appendChild(section('Time control', el('button', {
    class: 'link', type: 'button', text: 'Custom',
    onClick: () => openCustom(),
  })));

  const presetWrap = el('div', { class: 'presets' });
  scroll.appendChild(presetWrap);

  function renderPresets() {
    clear(presetWrap);
    for (const p of PRESETS) {
      presetWrap.appendChild(el('button', {
        class: 'preset',
        type: 'button',
        'aria-pressed': String(p.id === selectedPreset),
        onClick: () => { selectedPreset = p.id; updateSettings({ lastPreset: p.id }); renderPresets(); explain(); },
      },
        el('div', { class: 'preset__time', text: `${p.initialSec >= 60 ? p.initialSec / 60 : p.initialSec}${p.initialSec >= 60 ? '' : 's'}` }),
        el('div', { class: 'preset__name', text: p.name }),
        el('div', { class: 'preset__label', text: p.label }),
      ));
    }
  }

  const explainBox = el('div', { class: 'tc-explain mt-2' });
  scroll.appendChild(explainBox);

  function explain() {
    clear(explainBox);
    const p = PRESETS.find((x) => x.id === selectedPreset);
    if (!p) return;
    explainBox.appendChild(el('div', {}, icon('clock', 18)));
    explainBox.appendChild(el('div', {
      html: `<b>${p.label} ${p.name}</b> — each player starts with ${humanise(p.initialSec)}`
        + (p.incrementSec ? ` and gains ${p.incrementSec}s per move.` : ' with no increment.'),
    }));
  }

  function humanise(sec) {
    if (sec < 60) return `${sec} seconds`;
    if (sec % 60 === 0) return `${sec / 60} minute${sec / 60 === 1 ? '' : 's'}`;
    return `${Math.floor(sec / 60)}m ${sec % 60}s`;
  }

  renderPresets();
  explain();

  // ------------------------------------------------------------- helpers

  function currentControl() {
    if (customActive) return customActive;
    return PRESETS.find((p) => p.id === selectedPreset) || presetById('blitz-5');
  }

  let customActive = null;

  function modeCard({ icon: iconName, title, sub, onClick }) {
    return el('button', { class: 'mode-card', type: 'button', onClick },
      el('div', { class: 'mode-card__icon' }, icon(iconName, 26)),
      el('div', { class: 'mode-card__title', text: title }),
      el('div', { class: 'mode-card__sub', text: sub }),
    );
  }

  function start(mode, extra = {}) {
    const control = extra.control || currentControl();
    setPendingGame({
      mode,
      control,
      level: extra.level || level,
      side: extra.side || side,
      ...extra,
    });
    updateSettings({ lastLevel: extra.level || level, lastSide: extra.side || side });
    go('/game');
  }

  // ---------------------------------------------------------- computer
  function openComputer() {
    let chosen = level;
    let chosenSide = side;
    const levelList = el('div', { class: 'chips' });
    const sideList = el('div', { class: 'chips mt-2' });

    const paintLevels = () => {
      clear(levelList);
      for (const l of LEVELS) {
        levelList.appendChild(el('button', {
          class: 'chip',
          type: 'button',
          'aria-pressed': String(l === chosen),
          text: l[0].toUpperCase() + l.slice(1),
          onClick: () => { chosen = l; paintLevels(); },
        }));
      }
    };
    const paintSides = () => {
      clear(sideList);
      for (const [id, label] of [['w', 'Play white'], ['b', 'Play black'], ['random', 'Random']]) {
        sideList.appendChild(el('button', {
          class: 'chip',
          type: 'button',
          'aria-pressed': String(id === chosenSide),
          text: label,
          onClick: () => { chosenSide = id; paintSides(); },
        }));
      }
    };
    paintLevels();
    paintSides();

    const description = el('p', { class: 'muted small mt-3' });
    const paintDesc = () => {
      description.textContent = ({
        easy: 'Easy: the engine picks mostly random legal moves — good for learning the rules.',
        medium: 'Medium: 2-ply search with positional evaluation.',
        hard: 'Hard: iterative deepening to depth 3 with capture search, time-boxed so it never freezes the UI.',
      })[chosen];
    };
    paintDesc();
    levelList.addEventListener('click', () => setTimeout(paintDesc, 0));

    const handle = modal({
      title: 'Play the computer',
      body: el('div', {},
        el('div', { class: 'setting-group__title', text: 'Difficulty' }),
        levelList,
        el('div', { class: 'setting-group__title mt-3', text: 'Your colour' }),
        sideList,
        description,
        el('p', { class: 'tiny muted mt-2', text: `Time control: ${currentControl().name}` }),
      ),
      actions: [
        el('button', { class: 'btn btn--ghost', type: 'button', text: 'Cancel', onClick: () => handle.close() }),
        el('button', {
          class: 'btn btn--primary', type: 'button', text: 'Start',
          onClick: () => { handle.close(); start('bot', { level: chosen, side: chosenSide }); },
        }),
      ],
    });
  }

  // ------------------------------------------------------------ online
  function openOnline() {
    if (!apiConfigured()) {
      const handle = modal({
        title: 'Online play needs a server',
        body: el('div', {},
          el('p', { class: 'muted small', text: 'No backend URL is configured on this device, so there is no matchmaking server to talk to.' }),
          el('p', { class: 'muted small mt-2', text: 'Set one under Settings → Network. Production requires HTTPS.' }),
        ),
        actions: [el('button', {
          class: 'btn btn--primary', type: 'button', text: 'Open settings',
          onClick: () => { handle.close(); go('/settings'); },
        })],
      });
      return;
    }
    if (!isLoggedIn()) {
      const handle = modal({
        title: 'Sign in to play online',
        body: el('p', { class: 'muted small', text: 'Rated games are matched against real accounts, so a sign-in is required.' }),
        actions: [
          el('button', { class: 'btn btn--ghost', type: 'button', text: 'Not now', onClick: () => handle.close() }),
          el('button', { class: 'btn btn--primary', type: 'button', text: 'Sign in', onClick: () => { handle.close(); go('/auth'); } }),
        ],
      });
      return;
    }
    // Handled by the online flow (matchmaking screen) — routed through /game.
    start('online');
  }

  function openInvite() {
    if (!apiConfigured() || !isLoggedIn()) {
      toast('Private games need a configured server and a signed-in account', { type: 'error' });
      return;
    }
    let code = '';
    const input = el('input', {
      class: 'input', type: 'text', maxlength: '6', autocapitalize: 'characters',
      placeholder: 'K7QF2N', 'aria-label': 'Invite code',
      onInput: (e) => { code = e.target.value.toUpperCase().trim(); e.target.value = code; },
    });
    const jh = modal({
      title: 'Join with a code',
      body: el('div', { class: 'auth-form' },
        el('p', { class: 'muted small', text: 'Enter the 6-character code your friend shared.' }),
        input,
      ),
      actions: [
        el('button', { class: 'btn btn--ghost', type: 'button', text: 'Cancel', onClick: () => jh.close() }),
        el('button', {
          class: 'btn btn--primary', type: 'button', text: 'Join',
          onClick: () => {
            jh.close();
            if (code.length !== 6) { toast('Codes are 6 characters', { type: 'error' }); return; }
            start('online', { inviteCode: code });
          },
        }),
      ],
    });
  }

  function openCustom() {
    let minutes = 10;
    let inc = 5;
    let unlimited = false;

    const val = el('span', { class: 'stepper__val', text: `${minutes} min` });
    const incVal = el('span', { class: 'stepper__val', text: `+${inc}s` });

    const body = el('div', {},
      el('div', { class: 'setting-group__title', text: 'Base time per player' }),
      el('div', { class: 'stepper' },
        el('button', { class: 'btn btn--icon', type: 'button', 'aria-label': 'Less time', onClick: () => bump(-1) }, icon('minus', 20)),
        val,
        el('button', { class: 'btn btn--icon', type: 'button', 'aria-label': 'More time', onClick: () => bump(1) }, icon('plus', 20)),
      ),
      el('div', { class: 'setting-group__title mt-3', text: 'Increment per move' }),
      el('div', { class: 'stepper' },
        el('button', { class: 'btn btn--icon', type: 'button', 'aria-label': 'Less increment', onClick: () => bumpInc(-1) }, icon('minus', 20)),
        incVal,
        el('button', { class: 'btn btn--icon', type: 'button', 'aria-label': 'More increment', onClick: () => bumpInc(1) }, icon('plus', 20)),
      ),
      el('p', { class: 'tiny muted mt-3', text: 'Base time: 1–120 minutes in whole minutes. Increment: 0–600 seconds.' }),
    );

    function bump(d) {
      minutes = Math.min(120, Math.max(1, minutes + d));
      val.textContent = `${minutes} min`;
    }
    function bumpInc(d) {
      const steps = [0, 1, 2, 3, 5, 10, 15, 20, 30, 60];
      let i = steps.indexOf(inc);
      i = Math.min(steps.length - 1, Math.max(0, i + d));
      inc = steps[i];
      incVal.textContent = `+${inc}s`;
    }

    const ch = modal({
      title: 'Custom time control',
      body,
      actions: [
        el('button', { class: 'btn btn--ghost', type: 'button', text: 'Cancel', onClick: () => ch.close() }),
        el('button', {
          class: 'btn btn--primary', type: 'button', text: 'Use this',
          onClick: () => {
            ch.close();
            customActive = customControl({ minutes, incrementSec: inc, unlimited });
            selectedPreset = null;
            renderPresets();
            clear(explainBox);
            explainBox.appendChild(el('div', {}, icon('clock', 18)));
            explainBox.appendChild(el('div', { html: `<b>Custom ${customActive.name}</b> — applied to the next game.` }));
            toast(`Custom ${customActive.name} selected`, { type: 'success' });
          },
        }),
      ],
    });
    return ch;
  }

  // resume an unfinished local game if one exists
  scroll.appendChild(el('div', { class: 'mt-4' },
    emptyState({
      iconName: 'info',
      title: 'Nothing running',
      hint: 'Start a mode above. Active games are saved after every move and can be resumed from Review.',
    }),
  ));

  return { el: root, keepScreenOn: false };
}
