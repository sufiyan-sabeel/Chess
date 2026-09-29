/**
 * Tournament clock.
 *
 * Design notes:
 *  - elapsed time is computed from a monotonic source (performance.now by
 *    default), never from decrementing a counter inside a timer callback, so a
 *    delayed/suspended callback cannot corrupt the remaining time;
 *  - only the side to move ticks;
 *  - increment is credited to the mover when the clock switches;
 *  - display time is never negative and timeout fires exactly once;
 *  - pause()/resume() model app backgrounding: resume() credits only the time
 *    that actually elapsed while running.
 *
 * In online matches the server clock is authoritative — the client applies
 * `syncTo()` from server values and then projects locally until the next ack.
 */

export class Clock {
  /**
   * @param {{
   *   initialMs: number,
   *   incrementMs?: number,
   *   now?: () => number,
   *   onTick?: (state: object) => void,
   *   onTimeout?: (color: 'w'|'b') => void,
   *   tickMs?: number,
   * }} opts
   */
  constructor({ initialMs, incrementMs = 0, now, onTick, onTimeout, tickMs = 100 }) {
    if (!Number.isFinite(initialMs) || initialMs <= 0) throw new Error('initialMs must be > 0');
    this.initialMs = initialMs;
    this.incrementMs = Math.max(0, incrementMs);
    this.now = now || (() => (typeof performance !== 'undefined' ? performance.now() : Date.now()));
    this.onTick = onTick || (() => {});
    this.onTimeout = onTimeout || (() => {});
    this.tickMs = tickMs;

    this.remaining = { w: initialMs, b: initialMs };
    this.active = null;        // 'w' | 'b' | null (null = not started/paused)
    this.runningSince = null;  // monotonic timestamp when active side started ticking
    this.timedOut = null;      // 'w' | 'b' | null
    this.timer = null;
    this.paused = true;
  }

  // ---------------------------------------------------------------- control

  /** Start (or restart) the clock with `color` to move. */
  start(color) {
    this.assertColor(color);
    this.active = color;
    this.runningSince = this.now();
    this.paused = false;
    this.scheduleTick();
    this.emit();
    return this;
  }

  /**
   * Apply increment to the mover and hand the clock to `color`.
   * If the mover's flag fell before the move completed, they lose on time and
   * the clock is not resumed (increment must not resurrect a dead clock).
   */
  switchTo(color) {
    this.assertColor(color);
    const mover = this.active;
    if (mover && mover !== color) {
      this.captureElapsed();
      if (this.remaining[mover] <= 0) {
        this.remaining[mover] = 0;
        this.timedOut = mover;
        this.paused = true;
        this.clearTimer();
        this.onTimeout(mover);
        this.emit();
        return this;
      }
      this.remaining[mover] += this.incrementMs;
    } else if (this.active === color) {
      // switch called without a colour change — credit the mover explicitly
      this.creditIncrement(color);
    }
    this.remaining[color] = Math.max(0, this.remaining[color]);
    this.active = color;
    this.runningSince = this.now();
    this.checkFlag();
    this.emit();
    return this;
  }

  creditIncrement(color) {
    if (!color || this.incrementMs <= 0) return;
    // consume whatever elapsed since last runningSince, then credit
    this.captureElapsed();
    this.remaining[color] += this.incrementMs;
  }

  pause() {
    if (this.paused) return this;
    this.captureElapsed();
    this.paused = true;
    this.clearTimer();
    this.emit();
    return this;
  }

  resume() {
    if (!this.paused || !this.active || this.timedOut) return this;
    this.runningSince = this.now();
    this.paused = false;
    this.scheduleTick();
    this.emit();
    return this;
  }

  stop() {
    this.captureElapsed();
    this.paused = true;
    this.active = null;
    this.clearTimer();
    this.emit();
    return this;
  }

  // ---------------------------------------------------------------- reading

  /** Remaining ms for a colour (never negative). */
  remainingMs(color) {
    this.assertColor(color);
    let v = this.remaining[color];
    if (color === this.active && !this.paused && !this.timedOut && this.runningSince !== null) {
      v -= this.now() - this.runningSince;
    }
    return Math.max(0, v);
  }

  isRunning() {
    return Boolean(this.active && !this.paused && !this.timedOut);
  }

  activeColor() {
    return this.active;
  }

  /**
   * Server-authoritative resync (online play).
   * @param {{w:number,b:number,active:'w'|'b'|null,running?:boolean}} data
   */
  syncTo({ w, b, active = null, running = true }) {
    if (Number.isFinite(w)) this.remaining.w = Math.max(0, w);
    if (Number.isFinite(b)) this.remaining.b = Math.max(0, b);
    this.active = active;
    this.runningSince = this.now();
    this.paused = !running || !active;
    if (!this.paused) this.scheduleTick();
    else this.clearTimer();
    this.checkFlag();
    this.emit();
    return this;
  }

  snapshot() {
    return {
      w: this.remainingMs('w'),
      b: this.remainingMs('b'),
      active: this.active,
      paused: this.paused,
      timedOut: this.timedOut,
      initialMs: this.initialMs,
      incrementMs: this.incrementMs,
    };
  }

  // --------------------------------------------------------------- internal

  captureElapsed() {
    if (this.active && !this.paused && this.runningSince !== null) {
      const elapsed = this.now() - this.runningSince;
      this.remaining[this.active] = Math.max(0, this.remaining[this.active] - elapsed);
      this.runningSince = this.now();
    }
  }

  checkFlag() {
    if (this.timedOut || !this.active) return;
    if (this.remainingMs(this.active) <= 0) {
      this.remaining[this.active] = 0;
      this.timedOut = this.active;
      this.paused = true;
      this.clearTimer();
      this.onTimeout(this.active);
      this.emit();
      return true;
    }
    return false;
  }

  scheduleTick() {
    this.clearTimer();
    if (this.paused || !this.active) return;
    this.timer = setInterval(() => {
      const fired = this.checkFlag();
      this.emit();
      if (fired) this.clearTimer();
    }, this.tickMs);
    // Node/tests: do not keep the process alive
    if (this.timer && typeof this.timer.unref === 'function') this.timer.unref();
  }

  clearTimer() {
    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }
  }

  emit() {
    try {
      this.onTick(this.snapshot());
    } catch (e) {
      console.error('clock onTick failed', e);
    }
  }

  assertColor(color) {
    if (color !== 'w' && color !== 'b') throw new Error(`invalid clock color: ${color}`);
  }

  dispose() {
    this.clearTimer();
  }
}

/** True when the clock is in its final seconds (low-time warning). */
export function isLowTime(ms, thresholdMs = 20000) {
  return ms > 0 && ms <= thresholdMs;
}
