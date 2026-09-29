/**
 * Bot worker — keeps the search off the UI thread.
 *
 * The controller posts { fen, level, timeBudgetMs } and receives
 * { move: { from, to, promotion, level } | null }.
 *
 * Everything here is our own code + the vendored chess.js: no remote code,
 * no network access inside the worker.
 */
import { chooseMove } from './bot.js';

self.onmessage = (event) => {
  const { fen, level = 'medium', timeBudgetMs = 1200 } = event.data || {};
  let move = null;
  try {
    move = chooseMove({ fen, level, timeBudgetMs });
  } catch (err) {
    move = null;
  }
  self.postMessage({ move });
};

// Signal readiness to the controller (harmless if nobody listens).
self.postMessage({ ready: true });
