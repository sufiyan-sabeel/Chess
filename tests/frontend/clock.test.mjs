/**
 * Clock tests — fully deterministic via an injected fake time source.
 *
 * Covers: full-time start, elapsed accounting, increment crediting,
 * pause/resume freezing, flag fall (with no resurrection by increment),
 * resync, snapshots and the low-time helper.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Clock, isLowTime } from '../../app/src/main/assets/app/js/chess/clock.js';

function makeClock(opts = {}) {
  let t = 0;
  const clock = new Clock({ now: () => t, ...opts });
  return { clock, advance: (ms) => { t += ms; }, now: () => t };
}

test('rejects invalid initial time', () => {
  assert.throws(() => new Clock({ initialMs: 0 }), /initialMs/);
  assert.throws(() => new Clock({ initialMs: -5 }), /initialMs/);
  assert.throws(() => new Clock({ initialMs: NaN }), /initialMs/);
});

test('starts paused with full time for both sides', () => {
  const { clock } = makeClock({ initialMs: 60_000 });
  assert.equal(clock.paused, true);
  assert.equal(clock.isRunning(), false);
  assert.equal(clock.remainingMs('w'), 60_000);
  assert.equal(clock.remainingMs('b'), 60_000);
  assert.equal(clock.activeColor(), null);
  clock.dispose();
});

test('elapsed time is deducted only from the active side', () => {
  const { clock, advance } = makeClock({ initialMs: 60_000 });
  clock.start('w');
  advance(5_000);
  assert.equal(clock.remainingMs('w'), 55_000);
  assert.equal(clock.remainingMs('b'), 60_000);
  clock.dispose();
});

test('switchTo credits the mover increment and hands over', () => {
  const { clock, advance } = makeClock({ initialMs: 60_000, incrementMs: 3_000 });
  clock.start('w');
  advance(10_000);
  clock.switchTo('b');
  // white: 60 - 10 + 3 = 53s; black clock now running
  assert.equal(clock.remainingMs('w'), 53_000);
  advance(2_000);
  assert.equal(clock.remainingMs('b'), 58_000);
  assert.equal(clock.activeColor(), 'b');
  clock.dispose();
});

test('pause freezes the clock, resume continues from the paused point', () => {
  const { clock, advance } = makeClock({ initialMs: 60_000 });
  clock.start('w');
  advance(4_000);
  clock.pause();
  advance(30_000); // wall time passes while paused
  assert.equal(clock.remainingMs('w'), 56_000);
  clock.resume();
  advance(1_000);
  assert.equal(clock.remainingMs('w'), 55_000);
  clock.dispose();
});

test('flag falls exactly at zero and fires onTimeout once', () => {
  const timeouts = [];
  const { clock, advance } = makeClock({
    initialMs: 1_000,
    incrementMs: 5_000, // must NOT resurrect a dead clock
    onTimeout: (color) => timeouts.push(color),
  });
  clock.start('w');
  advance(1_001);
  clock.switchTo('b');
  assert.deepEqual(timeouts, ['w']);
  assert.equal(clock.remainingMs('w'), 0);
  assert.equal(clock.timedOut, 'w');
  assert.equal(clock.isRunning(), false);
  assert.equal(clock.paused, true);

  // a later switch cannot credit the increment to the flagged side
  clock.switchTo('w');
  assert.equal(clock.remainingMs('w'), 0);
  assert.deepEqual(timeouts, ['w']); // onTimeout fired only once
  clock.dispose();
});

test('switchTo with the same colour credits the mover explicitly', () => {
  const { clock, advance } = makeClock({ initialMs: 60_000, incrementMs: 2_000 });
  clock.start('w');
  advance(5_000);
  clock.switchTo('w'); // same colour: explicit increment credit
  assert.equal(clock.remainingMs('w'), 57_000); // 60 - 5 + 2
  clock.dispose();
});

test('snapshot carries both sides, active colour and configuration', () => {
  const { clock, advance } = makeClock({ initialMs: 90_000, incrementMs: 1_000 });
  clock.start('b');
  advance(1_000);
  const snap = clock.snapshot();
  assert.equal(snap.initialMs, 90_000);
  assert.equal(snap.incrementMs, 1_000);
  assert.equal(snap.active, 'b');
  assert.equal(snap.paused, false);
  assert.equal(snap.timedOut, null);
  assert.equal(snap.w, 90_000);
  assert.equal(snap.b, 89_000);
  clock.dispose();
});

test('syncTo applies server-authoritative times and can pause', () => {
  const { clock, advance } = makeClock({ initialMs: 60_000 });
  clock.syncTo({ w: 12_345, b: 45_000, active: 'b', running: false });
  assert.equal(clock.paused, true);
  assert.equal(clock.remainingMs('b'), 45_000);
  advance(5_000); // paused: no deduction
  assert.equal(clock.remainingMs('b'), 45_000);
  clock.syncTo({ w: 12_345, b: 45_000, active: 'b', running: true });
  advance(1_000);
  assert.equal(clock.remainingMs('b'), 44_000);
  assert.equal(clock.remainingMs('w'), 12_345);
  clock.dispose();
});

test('stop halts the clock with no active side', () => {
  const { clock, advance } = makeClock({ initialMs: 60_000 });
  clock.start('w');
  advance(2_000);
  clock.stop();
  advance(9_999);
  assert.equal(clock.remainingMs('w'), 58_000);
  assert.equal(clock.activeColor(), null);
  assert.equal(clock.isRunning(), false);
  clock.dispose();
});

test('onTick emits snapshots while running', () => {
  const seen = [];
  const { clock, advance } = makeClock({ initialMs: 30_000, onTick: (s) => seen.push(s) });
  clock.start('w');
  advance(1_000);
  clock.pause();
  assert.ok(seen.length >= 1);
  assert.ok(seen.every((s) => typeof s.w === 'number' && typeof s.b === 'number'));
  clock.dispose();
});

test('isLowTime: zero is not low, boundary is inclusive', () => {
  assert.equal(isLowTime(0), false);
  assert.equal(isLowTime(1), true);
  assert.equal(isLowTime(20_000), true);
  assert.equal(isLowTime(20_001), false);
  assert.equal(isLowTime(5_000, 10_000), true);
  assert.equal(isLowTime(10_001, 10_000), false);
});
