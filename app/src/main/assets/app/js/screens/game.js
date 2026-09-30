/**
 * Live game screen.
 *
 * Renders the board, clocks, move list and controls for whichever session is
 * in flight (`/play` deposits a pending config here; Review resumes saved
 * games). The screen owns *presentation only* — every rule decision comes
 * from `GameSession`.
 */

import { el, clear, formatClockPrecise } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { Board } from '../ui/board.js';
import { confirm, modal, toast, section, avatarNode } from '../ui/components.js';
import { pieceSvg } from '../chess/pieces.js';
import { GameSession, resumeSession, presetById } from '../chess/game.js';
import { getSettings, updateSettings, getSession, playSound, haptic } from '../store.js';
import { getGame } from '../db.js';
import { go } from '../router.js';
import { getPendingGame, setPendingGame } from './play.js';

let liveSession = null;

/** Called by the shell when the app is backgrounded. */
export function pauseLiveGame() {
  if (liveSession && liveSession.clock && !liveSession.finished) liveSession.clock.pause();
}

export function resumeLiveGame() {
  if (liveSession && liveSession.clock && liveSession.clockStarted && !liveSession.finished) {
    liveSession.clock.resume();
  }
}

export function gameScreen(ctx = {}) {
  const root = el('div', { class: 'screen screen--flush' });
  const settings = getSettings();

  const pendingConfig = getPendingGame();
  const resumeId = ctx.params && ctx.params.id;

  if (!pendingConfig && !resumeId) {
    // Deep link with nothing to play — send the player back, honestly.
    setTimeout(() => go('/play', { replace: true }), 0);
    return { el: root };
  }

  // ------------------------------------------------------------ session
  // Resolve a random side here so player names are correct from the start.
  const chosenSide = pendingConfig
    ? (pendingConfig.side === 'random' ? (Math.random() < 0.5 ? 'w' : 'b') : (pendingConfig.side || 'w'))
    : 'w';

  const session = pendingConfig
    ? new GameSession({
        mode: pendingConfig.mode,
        control: pendingConfig.control || presetById('blitz-5'),
        side: chosenSide,
        level: pendingConfig.level || 'medium',
        whiteName: playerName('w', { ...pendingConfig, side: chosenSide }),
        blackName: playerName('b', { ...pendingConfig, side: chosenSide }),
        onUpdate: onState,
      })
    : null;

  if (pendingConfig) setPendingGame(null);
  liveSession = session || liveSession;

  // ------------------------------------------------------------- layout
  const game = el('div', { class: 'game' });
  const mainCol = el('div', { class: 'game__main' });
  const sideCol = el('div', { class: 'game__side' });
  game.appendChild(mainCol);
  game.appendChild(sideCol);
  root.appendChild(game);

  const topPlayer = el('div', { class: 'game__players' });
  const boardWrap = el('div', { class: 'board-wrap' });
  const bottomPlayer = el('div', { class: 'game__players' });
  const statusLine = el('div', { class: 'game-status' });
  const controls = el('div', { class: 'game-controls' });
  const moveList = el('div', { class: 'movelist' });
  const moveListInner = el('div', { class: 'movelist__inner' });
  moveList.appendChild(moveListInner);

  mainCol.append(topPlayer, boardWrap, bottomPlayer, statusLine, controls);
  sideCol.append(section('Moves'), moveList);

  const board = new Board({
    root: boardWrap,
    orientation: settings.boardFlipped ? 'b' : 'w',
    onMove: ({ from, to }) => {
      const res = session.attemptMove({ from, to });
      if (!res.ok && res.reason === 'illegal') {
        playSound('error');
        toast('That move is not legal', { type: 'error', durationMs: 1200 });
      }
    },
    onPromotion: (piece) => { session.completePromotion(piece); },
    onCancelPromotion: () => { session.cancelPromotion(); },
  });

  // ------------------------------------------------------------- player UI
  // Chess.com-style cards: avatar, name, real captured-piece glyphs and the
  // *net* material lead (shown only on the side that is ahead).
  const VALUES = { p: 1, n: 3, b: 3, r: 5, q: 9, k: 0 };

  function materialOf(moves, color) {
    return moves
      .filter((m) => m.color === color && m.captured)
      .reduce((sum, m) => sum + (VALUES[m.captured] || 0), 0);
  }

  function playerName(color, cfg) {
    const session0 = getSession();
    const human = session0 && session0.display_name ? session0.display_name : 'You';
    if (cfg.mode === 'local') return color === 'w' ? 'White' : 'Black';
    if (cfg.mode === 'bot') return color === cfg.side ? human : 'Checkmate Bot';
    if (cfg.mode === 'online') return color === 'w' ? human : 'Opponent';
    return color === 'w' ? 'White' : 'Black';
  }

  function playerCard(color, state) {
    const foe = color === 'w' ? 'b' : 'w';
    const isTop = (board.orientation === 'w' && color === 'b') || (board.orientation === 'b' && color === 'w');
    const clockMs = state.clock ? (color === 'w' ? state.clock.w : state.clock.b) : 0;
    const active = state.clock && state.clock.active === color;
    const low = color === 'w' ? state.lowTime.w : state.lowTime.b;
    const cls = [
      'clock',
      active ? 'clock--active' : '',
      low ? 'clock--low' : '',
      state.clock && state.clock.paused ? 'clock--paused' : '',
      isTop ? '' : '',
    ].filter(Boolean).join(' ');

    const captures = state.moves.filter((m) => m.color === color && m.captured);
    // Net lead: my captured material minus what the opponent captured off me.
    const ahead = materialOf(state.moves, color) - materialOf(state.moves, foe);
    const pieceTheme = (getSettings() && getSettings().pieceTheme) || 'classic';
    const displayName = state.names[color] || (color === 'w' ? 'White' : 'Black');

    return el('div', { class: `player ${active ? 'player--active' : ''}`.trim() },
      el('div', { class: `player__color player__color--${color}`, 'aria-hidden': 'true' }),
      avatarNode(displayName, `${state.id || 'game'}-${color}`, 'sm'),
      el('div', { class: 'player__meta' },
        el('div', { class: 'player__name' },
          el('span', { text: displayName }),
          color === state.turn ? el('span', { class: 'tiny', style: { color: 'var(--accent-green)' }, text: '●' }) : null,
        ),
        el('div', { class: 'player__captures' },
          captures.length
            ? captures.map((m) => pieceSvg(m.captured, foe, pieceTheme))
            : el('span', { class: 'tiny', text: state.mode === 'bot' ? (state.level || 'medium') : state.control.label }),
          ahead > 0 ? el('span', { class: 'player__diff', text: `+${ahead}` }) : null,
        ),
      ),
      state.unlimited
        ? el('div', { class: 'clock clock--paused', text: '∞' })
        : el('div', { class: cls, text: formatClockPrecise(clockMs), 'data-clock': color }),
    );
  }

  // ------------------------------------------------------------- move list
  function renderMoves(state) {
    clear(moveListInner);
    const sans = state.history;
    if (!sans.length) {
      moveListInner.appendChild(el('div', { class: 'tiny muted', text: 'No moves yet.' }));
      return;
    }
    for (let i = 0; i < sans.length; i += 2) {
      const no = Math.floor(i / 2) + 1;
      const wIdx = i;
      const bIdx = i + 1;
      moveListInner.appendChild(el('div', { class: 'moveline' },
        el('span', { class: 'moveline__no', text: `${no}.` }),
        el('div', { class: 'moveline__sans' },
          moveChip(sans[wIdx], wIdx, state),
          bIdx < sans.length ? moveChip(sans[bIdx], bIdx, state) : null,
        ),
      ));
    }
    moveList.scrollTop = moveList.scrollHeight;
  }

  function moveChip(san, index, state) {
    const current = index === state.history.length - 1;
    return el('button', {
      class: `moveline__move ${current ? 'moveline__move--current' : ''}`.trim(),
      type: 'button',
      text: san,
      onClick: () => {
        // Local & bot games allow jumping back for review of already-played moves
        if (state.mode === 'online') return;
        toast('Open Review from the game list to step through a finished game', { durationMs: 1800 });
      },
    });
  }

  // ------------------------------------------------------------- controls
  function renderControls(state) {
    clear(controls);

    const flipBtn = iconButton('flip', 'Flip board', () => {
      const o = board.flip();
      updateSettings({ boardFlipped: o === 'b' });
      onState(session.state());
    }, false);

    const soundBtn = iconButton(settings.sound ? 'sound' : 'soundOff', 'Toggle sound', () => {
      updateSettings({ sound: !getSettings().sound });
      settings.sound = getSettings().sound;
      renderControls(state);
    }, !settings.sound);

    controls.append(flipBtn, soundBtn);

    if (state.gameOver) {
      controls.appendChild(el('button', {
        class: 'btn btn--primary',
        type: 'button',
        onClick: () => showResult(state),
      }, icon('chart', 18), el('span', { text: 'Review' })));
      return;
    }

    if (state.mode !== 'online') {
      controls.appendChild(iconButton('undo', 'Take back', () => {
        if (!session.undo()) toast('Nothing to take back', { durationMs: 1200 });
      }, false));
    }

    controls.appendChild(iconButton('flag', 'Resign', async () => {
      const ok = await confirm({
        title: 'Resign?',
        message: state.mode === 'local'
          ? 'The player whose turn it is will lose this game.'
          : 'You will lose this game.',
        confirmLabel: 'Resign',
        danger: true,
      });
      if (ok) session.resign(state.turn);
    }, true));

    if (state.mode !== 'bot') {
      controls.appendChild(iconButton('handshake', 'Offer draw', async () => {
        const ok = await confirm({
          title: 'Offer a draw?',
          message: state.mode === 'local' ? 'End the game as a draw.' : 'Send a draw offer to your opponent.',
          confirmLabel: 'Draw',
        });
        if (ok) session.offerDraw();
      }, true));
    }
  }

  function iconButton(iconName, label, onClick, danger) {
    return el('button', {
      class: 'btn btn--icon',
      type: 'button',
      'aria-label': label,
      title: label,
      style: danger ? { color: 'var(--danger)' } : {},
      onClick,
    }, icon(iconName, 20));
  }

  // --------------------------------------------------------------- status
  function renderStatus(state) {
    clear(statusLine);
    statusLine.className = 'game-status';

    if (state.gameOver && state.outcome) {
      statusLine.textContent = `${state.outcome.reasonLabel} · ${state.outcome.token}`;
      return;
    }
    if (state.botThinking) {
      statusLine.classList.add('game-status--sync');
      statusLine.textContent = 'Opponent is thinking…';
      return;
    }
    if (state.inCheck) {
      statusLine.classList.add('game-status--check');
      statusLine.textContent = state.turn === 'w' ? 'White is in check' : 'Black is in check';
      return;
    }
    if (state.mode === 'online') {
      statusLine.classList.add(navigator.onLine === false ? 'game-status--offline' : 'game-status--sync');
      statusLine.textContent = navigator.onLine === false
        ? 'Offline — moves will sync when you reconnect'
        : 'Waiting for server ack…';
      return;
    }
    statusLine.textContent = `${state.turn === 'w' ? 'White' : 'Black'} to move`;
  }

  // --------------------------------------------------------------- result
  function showResult(state) {
    const o = state.outcome;
    if (!o) return;
    const cls = o.winner === null ? 'draw' : (state.mode === 'local' || o.winner === state.humanSide) ? 'win' : 'lose';
    const score = o.winner === 'w' ? '1 – 0' : o.winner === 'b' ? '0 – 1' : '½ – ½';

    const h = modal({
      title: cls === 'win' ? 'You win' : cls === 'lose' ? 'Game over' : 'Draw',
      body: el('div', { class: `result result--${cls}` },
        el('div', { class: 'result__icon' }, icon(cls === 'win' ? 'trophy' : cls === 'draw' ? 'handshake' : 'flag', 34)),
        el('div', { class: 'result__score', text: score }),
        el('div', { class: 'result__reason', text: o.reasonLabel }),
        el('div', { class: 'tiny muted', text: `${state.history.length} moves · ${state.control.name}` }),
      ),
      actions: [
        el('button', {
          class: 'btn btn--ghost', type: 'button', text: 'Close',
          onClick: () => h.close(),
        }),
        el('button', {
          class: 'btn btn--primary', type: 'button', text: 'Review game',
          onClick: () => { h.close(); go(`/review/${encodeURIComponent(state.id)}`); },
        }),
      ],
      dismissible: true,
      onClose: () => { /* keep board visible */ },
    });
  }

  // ------------------------------------------------------------ rendering
  let lastState = null;

  function onState(patch) {
    const state = patch && patch.fen ? patch : (session ? session.state() : null);
    if (!state) return;
    lastState = state;
    board.draw(state);
    renderStatus(state);
    renderControls(state);
    renderMoves(state);

    // player cards (order follows board orientation)
    const topColor = board.orientation === 'w' ? 'b' : 'w';
    const bottomColor = topColor === 'w' ? 'b' : 'w';
    clear(topPlayer);
    clear(bottomPlayer);
    topPlayer.appendChild(playerCard(topColor, state));
    bottomPlayer.appendChild(playerCard(bottomColor, state));

    if (state.gameOver && state.outcome && !resultShown) {
      resultShown = true;
      playSound('end');
      setTimeout(() => showResult(state), 240);
    }
  }

  let resultShown = false;

  // ------------------------------------------------------- screen lifecycle
  let started = false;

  function handleEnter() {
    if (started) return;
    started = true;

    if (resumeId && !session) {
      // Resume path: load the saved record synchronously-ish then build a session.
      getGame(resumeId).then((record) => {
        if (!record) {
          toast('That game could not be found', { type: 'error' });
          go('/', { replace: true });
          return;
        }
        liveSession = resumeSession(record, onState);
        liveSession.begin();
      }).catch(() => toast('Could not load the game', { type: 'error' }));
      return;
    }

    if (session) {
      session.begin();
      // Keep screen awake during play (native bridge when available).
      try { window.CheckmateNative?.setKeepScreenOn(true); } catch { /* optional */ }
    }
  }

  const onVisibility = (ev) => {
    if (!liveSession) return;
    if (ev.detail.hidden) pauseLiveGame();
    else resumeLiveGame();
  };
  window.addEventListener('cm:visibility', onVisibility);

  const onConnection = () => { if (lastState) renderStatus(lastState); };
  window.addEventListener('cm:connection', onConnection);

  // initial paint so the board is never blank
  if (session) onState(session.state());

  return {
    el: root,
    keepScreenOn: true,
    onEnter: handleEnter,
    onLeave: () => {
      window.removeEventListener('cm:visibility', onVisibility);
      window.removeEventListener('cm:connection', onConnection);
      try { window.CheckmateNative?.setKeepScreenOn(false); } catch { /* optional */ }
      // Keep a live local game running in memory only while the screen is up:
      // leaving pauses the clock so nobody loses on time in the background.
      if (liveSession && liveSession.clock && !liveSession.finished) {
        liveSession.clock.pause();
      }
      if (session && !session.finished) session.persist();
      board.destroy();
      if (session) session.dispose();
      if (liveSession === session) liveSession = null;
    },
  };
}
