/**
 * Game session controller.
 *
 * Owns everything that decides *what is true* about a running game:
 *   - the authoritative chess.js position,
 *   - the tournament clock (monotonic, increment-aware),
 *   - whose move it is and which moves are legal,
 *   - bot scheduling (worker when available, synchronous fallback),
 *   - game-over detection (mate/stalemate/draw rules/flag/resign),
 *   - persistence of the live + finished game.
 *
 * The board renderer never mutates state; it calls `attemptMove()` and redraws
 * from the snapshot returned by `state()`.
 *
 * Modes:
 *   local  — two humans, one device
 *   bot    — human vs the offline engine (easy/medium/hard)
 *   online — moves arrive from / go to the server (transport injected)
 */

import { Chess } from '../vendor/chess.js';
import { Clock, isLowTime } from './clock.js';
import { chooseMove } from './bot.js';
import { buildPgn, RESULTS } from './pgn.js';
import { dbPut, makeGameId } from '../db.js';
import { getSetting, playSound, haptic } from '../store.js';

export const MODES = ['local', 'bot', 'online'];

/** Standard time controls offered by the app (seconds + increment seconds). */
export const PRESETS = [
  { id: 'bullet-1', label: 'Bullet', name: '1 + 0', initialSec: 60, incrementSec: 0, kind: 'bullet' },
  { id: 'bullet-2', label: 'Bullet', name: '2 + 1', initialSec: 120, incrementSec: 1, kind: 'bullet' },
  { id: 'blitz-3', label: 'Blitz', name: '3 + 0', initialSec: 180, incrementSec: 0, kind: 'blitz' },
  { id: 'blitz-3-2', label: 'Blitz', name: '3 + 2', initialSec: 180, incrementSec: 2, kind: 'blitz' },
  { id: 'blitz-5', label: 'Blitz', name: '5 + 0', initialSec: 300, incrementSec: 0, kind: 'blitz' },
  { id: 'rapid-10', label: 'Rapid', name: '10 + 0', initialSec: 600, incrementSec: 0, kind: 'rapid' },
  { id: 'rapid-15-10', label: 'Rapid', name: '15 + 10', initialSec: 900, incrementSec: 10, kind: 'rapid' },
  { id: 'classical-30', label: 'Classical', name: '30 + 0', initialSec: 1800, incrementSec: 0, kind: 'classical' },
];

export function presetById(id) {
  return PRESETS.find((p) => p.id === id) || PRESETS[5];
}

/** Custom (unlimited allowed) time control from the mode screen. */
export function customControl({ minutes, incrementSec = 0, unlimited = false }) {
  if (unlimited) {
    return { id: 'custom-unlimited', label: 'Custom', name: 'Unlimited', initialSec: 0, incrementSec: 0, kind: 'unlimited', unlimited: true };
  }
  const initialSec = Math.max(10, Math.min(7200, Math.round(minutes * 60)));
  const inc = Math.max(0, Math.min(600, Math.round(incrementSec)));
  return {
    id: 'custom',
    label: 'Custom',
    name: `${minutes} + ${inc}`,
    initialSec,
    incrementSec: inc,
    kind: initialSec < 180 ? 'bullet' : initialSec < 600 ? 'blitz' : initialSec < 1800 ? 'rapid' : 'classical',
    unlimited: false,
  };
}

/** Human-readable outcome labels (records store the raw `reason` key). */
export const OUTCOME_REASONS = {
  checkmate: 'Checkmate',
  stalemate: 'Stalemate',
  insufficient: 'Insufficient material',
  threefold: 'Threefold repetition',
  fiftyMove: 'Fifty-move rule',
  resign: 'Resignation',
  timeout: 'Time forfeit',
  agreement: 'Draw agreed',
  abort: 'Game aborted',
};

/**
 * @param {{
 *   mode?: 'local'|'bot'|'online',
 *   control?: object,               // from PRESETS / customControl
 *   side?: 'w'|'b'|'random',        // human colour in bot/online games
 *   level?: 'easy'|'medium'|'hard',
 *   whiteName?: string, blackName?: string,
 *   fen?: string,
 *   gameId?: string,
 *   onUpdate?: (snapshot:object)=>void,
 *   transport?: { sendMove:(m)=>void, resign:()=>void, ... } | null,
 * }} opts
 */
export class GameSession {
  constructor(opts = {}) {
    const {
      mode = 'bot',
      control = presetById('blitz-5'),
      side = 'w',
      level = 'medium',
      whiteName = 'You',
      blackName = 'Checkmate Bot',
      fen = '',
      gameId = makeGameId(),
      onUpdate = null,
      transport = null,
    } = opts;

    if (!MODES.includes(mode)) throw new Error(`unknown mode: ${mode}`);

    this.mode = mode;
    this.control = control;
    this.level = level;
    this.gameId = gameId;
    this.onUpdate = onUpdate;
    this.transport = transport;
    this.createdAt = Date.now();

    this.chess = new Chess();
    if (fen) this.chess.load(fen);
    this.startFen = this.chess.fen();

    this.humanSide = mode === 'local' ? null : (side === 'random' ? (Math.random() < 0.5 ? 'w' : 'b') : side);
    if (mode === 'local') this.humanSide = 'w'; // local play: the device controls both

    this.names = { w: whiteName, b: blackName };
    this.finished = false;
    this.outcome = null;          // { winner:'w'|'b'|null, reason, token }
    this.botThinking = false;
    this.botTimer = null;
    this.undoAvailable = false;
    this.lastMove = null;         // { from, to }
    this.pendingPromotion = null; // { from, to } awaiting piece choice
    this.drawOffer = null;        // side that offered a draw
    this.saves = 0;

    const unlimited = !control.initialSec;
    this.unlimited = unlimited;
    this.clock = unlimited
      ? null
      : new Clock({
          initialMs: control.initialSec * 1000,
          incrementMs: control.incrementSec * 1000,
          onTick: (s) => this.emit({ clock: s }),
          onTimeout: (color) => this.finishByFlag(color),
        });

    // The first mover starts the clock as soon as the screen mounts.
    this.clockStarted = false;
  }

  // ------------------------------------------------------------- lifecycle

  begin() {
    if (this.clockStarted) return this;
    this.clockStarted = true;
    if (this.clock) this.clock.start(this.chess.turn());
    this.persist();
    this.emit({ started: true });
    this.maybeBotMove();
    return this;
  }

  dispose() {
    if (this.botTimer !== null) {
      clearTimeout(this.botTimer);
      this.botTimer = null;
    }
    this.botThinking = false;
    if (this.clock) this.clock.dispose();
    if (this.boardWorker) {
      try { this.boardWorker.terminate(); } catch { /* noop */ }
      this.boardWorker = null;
    }
  }

  // ---------------------------------------------------------------- reading

  /** Full render snapshot — the only thing the UI should read. */
  state() {
    const turn = this.chess.turn();
    return {
      id: this.gameId,
      mode: this.mode,
      control: this.control,
      level: this.level,
      fen: this.chess.fen(),
      turn,
      history: this.chess.history(),
      moves: this.chess.history({ verbose: true }).map((m) => ({
        san: m.san, from: m.from, to: m.to, color: m.color,
        piece: m.piece, captured: m.captured || null, promotion: m.promotion || null,
        flags: m.flags,
      })),
      inCheck: this.chess.inCheck(),
      gameOver: this.finished,
      outcome: this.outcome,
      humanSide: this.humanSide,
      canMove: this.canHumanMove(turn),
      botThinking: this.botThinking,
      lastMove: this.lastMove,
      pendingPromotion: this.pendingPromotion,
      drawOffer: this.drawOffer,
      names: this.names,
      clock: this.clock ? this.clock.snapshot() : null,
      unlimited: this.unlimited,
      undoAvailable: this.undoAvailable,
      lowTime: this.clock
        ? { w: isLowTime(this.clock.remainingMs('w')), b: isLowTime(this.clock.remainingMs('b')) }
        : { w: false, b: false },
      savedAt: this.createdAt,
      saves: this.saves,
    };
  }

  canHumanMove(turn) {
    if (this.finished) return false;
    if (this.mode === 'local') return true;
    if (this.botThinking) return false;
    return turn === this.humanSide;
  }

  legalTargets(square) {
    return this.chess.moves({ square, verbose: true });
  }

  legalMovesFrom(square) {
    return this.legalTargets(square).map((m) => m.to);
  }

  /** Piece + colour on a square, or null. */
  pieceAt(square) {
    return this.chess.get(square) || null;
  }

  // ---------------------------------------------------------------- moves

  /**
   * Attempt a move for the human side.
   * @returns {{ok:true}|{ok:false, reason:string, needsPromotion?:{from,to}}}
   */
  attemptMove({ from, to, promotion = null }) {
    if (this.finished) return { ok: false, reason: 'game-over' };
    if (!this.canHumanMove(this.chess.turn())) return { ok: false, reason: 'not-your-turn' };

    const candidates = this.chess.moves({ square: from, verbose: true }).filter((m) => m.to === to);
    if (!candidates.length) return { ok: false, reason: 'illegal' };

    const needsPromo = candidates.some((m) => m.promotion);
    if (needsPromo && !promotion) {
      if (getSetting('autoQueen')) {
        promotion = 'q';
      } else {
        this.pendingPromotion = { from, to };
        this.emit({ pendingPromotion: this.pendingPromotion });
        return { ok: false, reason: 'needs-promotion', needsPromotion: { from, to } };
      }
    }

    const move = this.applyMove({ from, to, promotion }, 'human');
    if (!move) return { ok: false, reason: 'illegal' };
    this.pendingPromotion = null;
    return { ok: true, move };
  }

  /** Resolve a pending promotion with the chosen piece. */
  completePromotion(piece) {
    const pending = this.pendingPromotion;
    if (!pending) return { ok: false, reason: 'no-promotion' };
    this.pendingPromotion = null;
    return this.attemptMove({ from: pending.from, to: pending.to, promotion: piece });
  }

  cancelPromotion() {
    this.pendingPromotion = null;
    this.emit({ pendingPromotion: null });
  }

  /**
   * The single mutation point. Applies a move (human, bot or remote),
   * advances the clock, records undo availability, notifies the transport,
   * persists and emits.
   */
  applyMove({ from, to, promotion = null, remote = false } = {}, source = 'human') {
    let move;
    try {
      move = this.chess.move({ from, to, promotion: promotion || undefined });
    } catch {
      return null;
    }
    if (!move) return null;

    this.lastMove = { from: move.from, to: move.to };
    this.undoAvailable = this.mode !== 'online';
    this.drawOffer = null;

    // clock: credit increment to the mover, then hand over
    if (this.clock && this.clockStarted) {
      this.clock.switchTo(this.chess.turn());
    }

    if (source === 'human') {
      playSound(move.captured ? 'capture' : 'move');
      haptic(10);
    } else if (source === 'bot') {
      playSound(move.captured ? 'capture' : 'move');
    }

    if (!remote && this.transport && this.transport.sendMove) {
      try { this.transport.sendMove({ from: move.from, to: move.to, promotion: move.promotion || null }); } catch { /* offline */ }
    }

    // check / mate tones (chess.js already applied the move)
    if (this.chess.inCheck()) playSound(this.chess.isCheckmate() ? 'end' : 'check');

    this.checkOutcome();
    this.persist();
    this.emit({ moved: move });
    if (!this.finished) this.maybeBotMove();
    return move;
  }

  /** Apply a move that arrived from the opponent (online mode). */
  applyRemoteMove(mv) {
    if (this.finished) return null;
    return this.applyMove(mv, 'remote');
  }

  // ---------------------------------------------------------------- outcome

  checkOutcome() {
    if (this.finished) return this.outcome;
    let winner = null;
    let reason = null;

    if (this.chess.isCheckmate()) {
      winner = this.chess.turn() === 'w' ? 'b' : 'w';
      reason = 'checkmate';
    } else if (this.chess.isStalemate()) {
      reason = 'stalemate';
    } else if (this.chess.isInsufficientMaterial()) {
      reason = 'insufficient';
    } else if (this.chess.isThreefoldRepetition()) {
      reason = 'threefold';
    } else if (this.chess.isDraw()) {
      // 50-move (any other draw chess.js recognises)
      reason = 'fiftyMove';
    }

    if (reason) this.finish(winner, reason);
    return this.outcome;
  }

  finishByFlag(color) {
    if (this.finished) return;
    const winner = color === 'w' ? 'b' : 'w';
    playSound('end');
    haptic([30, 40, 30]);
    this.finish(winner, 'timeout', { flagColor: color });
  }

  resign(color) {
    if (this.finished) return null;
    if (this.mode === 'online' && this.transport && this.transport.resign) {
      try { this.transport.resign(); } catch { /* offline */ }
    }
    const winner = color === 'w' ? 'b' : 'w';
    return this.finish(winner, 'resign', { resigned: color });
  }

  /** Offer/accept a draw (local & bot: both players are on this device). */
  offerDraw() {
    if (this.finished) return null;
    if (this.mode === 'online' && this.transport && this.transport.offerDraw) {
      try { this.transport.offerDraw(); } catch { /* offline */ }
      this.drawOffer = this.chess.turn();
      this.emit({ drawOffer: this.drawOffer });
      return this.drawOffer;
    }
    return this.finish(null, 'agreement');
  }

  /** Accept an opponent's draw offer (online). */
  acceptDraw() {
    if (this.finished) return null;
    return this.finish(null, 'agreement');
  }

  abort() {
    if (this.finished) return null;
    return this.finish(null, 'abort');
  }

  finish(winner, reason, extra = {}) {
    if (this.finished) return this.outcome;
    this.finished = true;
    if (this.clock) this.clock.stop();

    const token = winner === 'w' ? RESULTS.white : winner === 'b' ? RESULTS.black : RESULTS.draw;
    this.outcome = {
      winner,
      reason,
      reasonLabel: OUTCOME_REASONS[reason] || reason,
      token,
      ...extra,
    };

    // Tone vocabulary is defined in store.js (move/capture/check/low/end/...).
    const won = winner !== null && (this.mode === 'local' ? true : winner === this.humanSide);
    playSound(winner === null ? 'end' : won ? 'success' : 'end');
    haptic(winner === null ? 20 : [25, 60, 90]);

    this.persist(true);
    this.emit({ finished: this.outcome });
    return this.outcome;
  }

  // ------------------------------------------------------------------ undo

  /**
   * Take back the last full turn (two plies in bot games, one ply in local).
   * Only available before the game ends and only for local/bot games.
   */
  undo() {
    if (this.finished || !this.undoAvailable || this.mode === 'online') return false;
    if (this.chess.history().length === 0) return false;

    const plies = this.mode === 'bot' ? Math.min(2, this.chess.history().length) : 1;
    for (let i = 0; i < plies; i++) this.chess.undo();

    this.botThinking = false;
    if (this.botTimer !== null) { clearTimeout(this.botTimer); this.botTimer = null; }

    this.lastMove = null;
    const hist = this.chess.history({ verbose: true });
    if (hist.length) {
      const last = hist[hist.length - 1];
      this.lastMove = { from: last.from, to: last.to };
    }
    this.undoAvailable = this.chess.history().length > 0;

    // restart clock for the side to move (remaining times are preserved by
    // the clock object itself; only the active side changes)
    if (this.clock && this.clockStarted) this.clock.switchTo(this.chess.turn());

    playSound('tap');
    this.persist();
    this.emit({ undone: true });
    this.maybeBotMove();
    return true;
  }

  // ------------------------------------------------------------------- bot

  maybeBotMove() {
    if (this.finished || this.mode !== 'bot') return;
    if (this.chess.turn() === this.humanSide) return;
    if (this.botThinking) return;

    this.botThinking = true;
    this.emit({ botThinking: true });

    const fen = this.chess.fen();
    const level = this.level;
    const budget = level === 'hard' ? 1400 : level === 'medium' ? 700 : 150;

    // Small delay so the UI paints the human's move before the bot replies.
    this.botTimer = setTimeout(() => {
      this.botTimer = null;
      this.runBot(fen, level, budget);
    }, 120);
    if (this.botTimer && typeof this.botTimer.unref === 'function') this.botTimer.unref();
  }

  runBot(fen, level, budget) {
    const deliver = (mv) => {
      if (this.finished) { this.botThinking = false; return; }
      // Guard: the position may have changed (undo/resign) while we searched.
      if (this.chess.fen() !== fen) {
        this.botThinking = false;
        this.emit({ botThinking: false });
        return;
      }
      this.botThinking = false;
      if (mv) this.applyMove({ from: mv.from, to: mv.to, promotion: mv.promotion }, 'bot');
      else this.emit({ botThinking: false });
      this.emit({ botThinking: false });
    };

    // Preferred path: a worker keeps the UI thread free on slow devices.
    if (this.mode === 'bot' && typeof Worker !== 'undefined' && !this.workerFailed) {
      try {
        this.getWorker();
        this.pendingBot = deliver;
        this.worker.postMessage({ fen, level, timeBudgetMs: budget });
        return;
      } catch {
        this.workerFailed = true;
      }
    }
    // Synchronous fallback (browser dev / worker blocked).
    let mv = null;
    try { mv = chooseMove({ fen, level, timeBudgetMs: budget }); } catch { mv = null; }
    deliver(mv);
  }

  getWorker() {
    if (this.worker) return this.worker;
    const url = new URL('./botWorker.js', import.meta.url);
    this.worker = new Worker(url, { type: 'module' });
    this.worker.onmessage = (e) => {
      // Ignore the worker's boot handshake; only answers to a search count.
      if (!e.data || !Object.prototype.hasOwnProperty.call(e.data, 'move')) return;
      const deliver = this.pendingBot;
      this.pendingBot = null;
      if (deliver) deliver(e.data.move || null);
    };
    this.worker.onerror = () => {
      this.workerFailed = true;
      this.pendingBot = null;
      try { this.worker.terminate(); } catch { /* noop */ }
      this.worker = null;
      // fall back immediately so the game never stalls
      if (!this.finished && this.botThinking) {
        this.botThinking = false;
        this.maybeBotMove();
      }
    };
    return this.worker;
  }

  // ------------------------------------------------------------- persistence

  persist(final = false) {
    // Fire-and-forget: persistence must never block a move.
    const s = this.state();
    const record = {
      id: this.gameId,
      status: final ? 'finished' : 'active',
      mode: this.mode,
      level: this.level,
      control: this.control,
      startFen: this.startFen,
      fen: s.fen,
      sans: s.history,
      result: final && this.outcome ? this.outcome.token : '*',
      winner: final && this.outcome ? this.outcome.winner : undefined,
      reason: final && this.outcome ? this.outcome.reason : undefined,
      white: this.names.w,
      black: this.names.b,
      humanSide: this.humanSide,
      clocks: this.clock ? this.clock.snapshot() : null,
      startedAt: this.createdAt,
      finishedAt: final ? Date.now() : null,
      pgn: final ? this.toPgn() : '',
    };
    this.saves++;
    dbPut('games', record).catch(() => {});
    return record;
  }

  toPgn() {
    const outcome = this.outcome;
    const tc = this.unlimited ? '' : `${this.control.initialSec}+${this.control.incrementSec}`;
    return buildPgn({
      moves: this.chess.history(),
      // Replaying must start from THIS game's position — a game created from
      // a custom FEN would otherwise throw inside buildPgn (chess.js rejects
      // the first SAN) and take finish()/persist() down with it.
      fen: this.startFen,
      white: this.names.w,
      black: this.names.b,
      result: outcome ? outcome.token : RESULTS.ongoing,
      timeControl: tc,
      termination: outcome ? outcome.reasonLabel : '',
      date: new Date(this.createdAt),
      event: `Checkmate (${this.mode}${this.mode === 'bot' ? `/${this.level}` : ''})`,
    });
  }

  emit(patch = {}) {
    if (!this.onUpdate) return;
    try {
      this.onUpdate({ ...this.state(), ...patch });
    } catch (e) {
      console.error('game onUpdate failed', e);
    }
  }
}

/** Resume an unfinished record persisted by `GameSession.persist()`. */
export function resumeSession(record, onUpdate) {
  const session = new GameSession({
    mode: record.mode,
    control: record.control,
    side: record.humanSide || 'w',
    level: record.level || 'medium',
    whiteName: record.white,
    blackName: record.black,
    fen: record.fen || record.startFen || '',
    gameId: record.id,
    onUpdate,
  });
  session.createdAt = record.startedAt || Date.now();
  session.startFen = record.startFen || session.startFen;
  if (record.clocks && session.clock) {
    session.clock.syncTo({
      w: record.clocks.w,
      b: record.clocks.b,
      active: record.clocks.active || session.chess.turn(),
      running: false,
    });
  }
  return session;
}
