/**
 * PGN helpers — export, import and light parsing.
 *
 * chess.js owns the actual PGN grammar (it is the vendored authority); this
 * module only:
 *   - assembles headers for a finished game,
 *   - round-trips a game through PGN text (used for share/export and for the
 *     Review screen),
 *   - extracts metadata without touching the move stream.
 *
 * Nothing here fabricates results: `Result` is always derived from the
 * outcome object produced by the game controller.
 */

import { Chess } from '../vendor/chess.js';

/** PGN Result tokens. */
export const RESULTS = {
  white: '1-0',
  black: '0-1',
  draw: '1/2-1/2',
  ongoing: '*',
};

/** Map an internal outcome -> PGN result token. */
export function pgnResultToken(outcome) {
  if (!outcome) return RESULTS.ongoing;
  switch (outcome.winner) {
    case 'w': return RESULTS.white;
    case 'b': return RESULTS.black;
    default: return outcome.winner === null ? RESULTS.draw : RESULTS.ongoing;
  }
}

/**
 * Build a PGN string for a finished (or in-progress) game.
 *
 * @param {{
 *   moves: string[],                 // SAN strings in order
 *   white?: string, black?: string,
 *   event?: string, site?: string, date?: Date|string,
 *   result?: string,                 // '1-0' | '0-1' | '1/2-1/2' | '*'
 *   round?: string, timeControl?: string,
 *   fen?: string,                    // start position (default standard)
 *   termination?: string,            // e.g. 'normal', 'time forfeit'
 * }} opts
 * @returns {string} PGN text
 */
export function buildPgn(opts = {}) {
  const {
    moves = [],
    white = 'White',
    black = 'Black',
    event = 'Checkmate',
    site = 'Checkmate App',
    date = new Date(),
    result = RESULTS.ongoing,
    round = '1',
    timeControl = '',
    fen = '',
    termination = '',
  } = opts;

  const chess = new Chess();
  try {
    if (fen) chess.load(fen);
  } catch {
    throw new Error('invalid start FEN');
  }

  // Replay to guarantee the movetext we emit matches a legal game.
  for (const san of moves) {
    const played = chess.move(san);
    if (!played) throw new Error(`illegal SAN in move list: ${san}`);
  }

  const dateStr = normaliseDate(date);
  const headers = [
    ['Event', event],
    ['Site', site],
    ['Date', dateStr],
    ['Round', String(round)],
    ['White', white],
    ['Black', black],
    ['Result', result],
  ];
  if (timeControl) headers.push(['TimeControl', timeControl]);
  if (termination) headers.push(['Termination', termination]);

  for (const [k, v] of headers) {
    if (typeof chess.setHeader === 'function') chess.setHeader(k, v);
    else chess.header(k, v);
  }
  return chess.pgn({ newline: '\n', maxWidth: 80 });
}

function normaliseDate(d) {
  const date = d instanceof Date ? d : new Date(d);
  if (Number.isNaN(date.getTime())) return '??????.???.??';
  const p = (n) => String(n).padStart(2, '0');
  return `${date.getUTCFullYear()}.${p(date.getUTCMonth() + 1)}.${p(date.getUTCDate())}`;
}

/**
 * Parse PGN text into `{ moves, headers, result, startFen }`.
 * Throws on text that chess.js cannot load, so callers never silently
 * receive a truncated game.
 */
export function parsePgn(pgnText) {
  const text = String(pgnText || '').trim();
  if (!text) throw new Error('empty PGN');

  const chess = new Chess();
  chess.loadPgn(text, { strict: false });

  // getHeaders() omits null placeholders; header() (deprecated) returns them.
  const raw = typeof chess.getHeaders === 'function' ? chess.getHeaders() : chess.header();
  const headers = {};
  for (const [k, v] of Object.entries(raw || {})) {
    if (v !== null && v !== undefined) headers[k] = v;
  }

  // history() is the SAN list of the fully replayed game.
  const moves = chess.history();

  return {
    moves,
    headers,
    result: headers.Result || RESULTS.ongoing,
    startFen: headers.FEN || '',
    white: headers.White || 'White',
    black: headers.Black || 'Black',
    event: headers.Event || '',
    date: headers.Date || '',
    termination: headers.Termination || '',
    pgn: text,
  };
}

/** True when the string at least looks like PGN (cheap pre-filter). */
export function looksLikePgn(text) {
  return /\[Event\s+"/i.test(String(text || ''));
}

/**
 * Step-through helper used by the Review screen: returns a FEN snapshot for
 * every ply, so the reviewer can scrub without re-parsing repeatedly.
 * @returns {{fens:string[], sans:string[], startsWithFen:string}}
 */
export function buildScrubber(pgnText) {
  const parsed = parsePgn(pgnText);
  const chess = new Chess();
  if (parsed.startFen) {
    try { chess.load(parsed.startFen); } catch { /* standard start */ }
  }
  const startsWithFen = chess.fen();
  const fens = [startsWithFen];
  const sans = [];
  for (const san of parsed.moves) {
    const m = chess.move(san);
    if (!m) break; // defensive: never step past an illegal move
    sans.push(m.san);
    fens.push(chess.fen());
  }
  return { fens, sans, startsWithFen };
}
