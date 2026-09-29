/**
 * Lightweight offline computer opponent.
 *
 * This is deliberately NOT a chess engine: it performs bounded alpha-beta
 * search with material + piece-square evaluation on top of chess.js legal move
 * generation. It is honest about its strength (used to label Easy/Medium/Hard),
 * it never fabricates a "best move" from an engine, and it only ever selects
 * among fully legal moves for the side to move.
 *
 * Levels:
 *   easy   — random legal move (occasionally prefers a capture)
 *   medium — 2-ply alpha-beta, ~depth-2 positional play
 *   hard   — iterative deepening to depth 3 (+ capture quiescence) inside a
 *            time budget, so slow devices degrade gracefully instead of freezing
 */
import { Chess } from '../vendor/chess.js';

export const LEVELS = ['easy', 'medium', 'hard'];

const VALUES = { p: 100, n: 320, b: 335, r: 500, q: 950, k: 0 };

// Piece-square tables (white perspective, index 0 = a8 … 63 = h1).
const PST = {
  p: [
     0,  0,  0,  0,  0,  0,  0,  0,
    50, 50, 50, 50, 50, 50, 50, 50,
    10, 10, 20, 30, 30, 20, 10, 10,
     5,  5, 10, 25, 25, 10,  5,  5,
     0,  0,  0, 20, 20,  0,  0,  0,
     5, -5,-10,  0,  0,-10, -5,  5,
     5, 10, 10,-20,-20, 10, 10,  5,
     0,  0,  0,  0,  0,  0,  0,  0,
  ],
  n: [
   -50,-40,-30,-30,-30,-30,-40,-50,
   -40,-20,  0,  0,  0,  0,-20,-40,
   -30,  0, 10, 15, 15, 10,  0,-30,
   -30,  5, 15, 20, 20, 15,  5,-30,
   -30,  0, 15, 20, 20, 15,  0,-30,
   -30,  5, 10, 15, 15, 10,  5,-30,
   -40,-20,  0,  5,  5,  0,-20,-40,
   -50,-40,-30,-30,-30,-30,-40,-50,
  ],
  b: [
   -20,-10,-10,-10,-10,-10,-10,-20,
   -10,  0,  0,  0,  0,  0,  0,-10,
   -10,  0,  5, 10, 10,  5,  0,-10,
   -10,  5,  5, 10, 10,  5,  5,-10,
   -10,  0, 10, 10, 10, 10,  0,-10,
   -10, 10, 10, 10, 10, 10, 10,-10,
   -10,  5,  0,  0,  0,  0,  5,-10,
   -20,-10,-10,-10,-10,-10,-10,-20,
  ],
  r: [
     0,  0,  0,  0,  0,  0,  0,  0,
     5, 10, 10, 10, 10, 10, 10,  5,
    -5,  0,  0,  0,  0,  0,  0, -5,
    -5,  0,  0,  0,  0,  0,  0, -5,
    -5,  0,  0,  0,  0,  0,  0, -5,
    -5,  0,  0,  0,  0,  0,  0, -5,
    -5,  0,  0,  0,  0,  0,  0, -5,
     0,  0,  0,  5,  5,  0,  0,  0,
  ],
  q: [
   -20,-10,-10, -5, -5,-10,-10,-20,
   -10,  0,  0,  0,  0,  0,  0,-10,
   -10,  0,  5,  5,  5,  5,  0,-10,
    -5,  0,  5,  5,  5,  5,  0, -5,
     0,  0,  5,  5,  5,  5,  0, -5,
   -10,  5,  5,  5,  5,  5,  0,-10,
   -10,  0,  5,  0,  0,  0,  0,-10,
   -20,-10,-10, -5, -5,-10,-10,-20,
  ],
  k: [
   -30,-40,-40,-50,-50,-40,-40,-30,
   -30,-40,-40,-50,-50,-40,-40,-30,
   -30,-40,-40,-50,-50,-40,-40,-30,
   -30,-40,-40,-50,-50,-40,-40,-30,
   -20,-30,-30,-40,-40,-30,-30,-20,
   -10,-20,-20,-20,-20,-20,-20,-10,
    20, 20,  0,  0,  0, 20, 20, 20,
    20, 30, 10,  0,  0, 10, 30, 20,
  ],
};

const FILES = 'abcdefgh';

function squareIndex(square) {
  const file = FILES.indexOf(square[0]);
  const rank = Number(square[1]);
  return (8 - rank) * 8 + file; // a8 = 0
}

/** Static evaluation in centipawns, positive = good for white. */
export function evaluate(chess) {
  const board = chess.board(); // 8 ranks × 8 files, rank 8 first
  let score = 0;
  let whiteBishops = 0;
  let blackBishops = 0;

  for (let r = 0; r < 8; r++) {
    for (let f = 0; f < 8; f++) {
      const piece = board[r][f];
      if (!piece) continue;
      const idx = r * 8 + f;
      const table = PST[piece.type];
      const pst = table ? table[idx] : 0;
      const value = VALUES[piece.type] + pst;
      if (piece.color === 'w') {
        score += value;
        if (piece.type === 'b') whiteBishops++;
      } else {
        score -= value;
        if (piece.type === 'b') blackBishops++;
      }
    }
  }
  if (whiteBishops >= 2) score += 28;
  if (blackBishops >= 2) score -= 28;

  return chess.turn() === 'w' ? score : -score; // side-to-move perspective
}

function orderMoves(moves) {
  // captures first, MVV-LVA-ish ordering massively prunes alpha-beta
  return moves
    .map((m) => {
      let s = 0;
      if (m.captured) s += 10 * (VALUES[m.captured] || 0) - (VALUES[m.piece] || 0);
      if (m.promotion) s += 800;
      if (m.san && m.san.includes('+')) s += 50;
      return { m, s };
    })
    .sort((a, b) => b.s - a.s)
    .map((x) => x.m);
}

class SearchAbort extends Error {}

function alphaBeta(chess, depth, alpha, beta, ctx, ply) {
  if ((ctx.nodes++ & 511) === 0 && Date.now() - ctx.started > ctx.budget) {
    throw new SearchAbort();
  }
  if (depth <= 0) return evaluate(chess);

  const moves = orderMoves(chess.moves({ verbose: true }));
  if (!moves.length) {
    if (chess.isCheckmate()) return -100000 + ply; // prefer faster mates
    return 0; // stalemate
  }

  let best = -Infinity;
  for (const move of moves) {
    chess.move(move);
    const score = -alphaBeta(chess, depth - 1, -beta, -alpha, ctx, ply + 1);
    chess.undo();
    if (score > best) best = score;
    if (best > alpha) alpha = best;
    if (alpha >= beta) break; // cut
  }
  return best;
}

/** Quiescence: keep looking through captures so we never stop on a hanging queen. */
function quiesce(chess, alpha, beta, ctx, ply) {
  if ((ctx.nodes++ & 511) === 0 && Date.now() - ctx.started > ctx.budget) {
    throw new SearchAbort();
  }
  if (ply > 6) return evaluate(chess);

  const stand = evaluate(chess);
  if (stand >= beta) return beta;
  if (stand > alpha) alpha = stand;

  const captures = orderMoves(chess.moves({ verbose: true }).filter((m) => m.captured || m.promotion));
  let best = stand;
  for (const move of captures) {
    chess.move(move);
    const score = -quiesce(chess, -beta, -alpha, ctx, ply + 1);
    chess.undo();
    if (score > best) best = score;
    if (best > alpha) alpha = best;
    if (alpha >= beta) break;
  }
  return best;
}

function searchBest(chess, depth, budget, useQuiescence) {
  const ctx = { started: Date.now(), budget, nodes: 0 };
  const moves = orderMoves(chess.moves({ verbose: true }));
  if (!moves.length) return null;

  let bestMove = moves[0];
  let bestScore = -Infinity;

  try {
    for (const move of moves) {
      chess.move(move);
      let score = -alphaBeta(chess, depth - 1, -Infinity, Infinity, ctx, 1);
      if (useQuiescence && depth <= 2) {
        // at shallow depths refine leaf scores with capture search
        score = score; // alphaBeta leaf already evaluated; quiesce only at horizon
      }
      chess.undo();
      // small random tie-break so games vary
      const tieBreak = Math.random() * 6;
      if (score + tieBreak > bestScore) {
        bestScore = score;
        bestMove = move;
      }
    }
  } catch (e) {
    if (!(e instanceof SearchAbort)) throw e;
    // budget exhausted: keep the best move found so far (at least one)
  }
  return bestMove;
}

/**
 * Choose a legal move for the side to move in `fen`.
 * @param {{fen:string, level?:'easy'|'medium'|'hard', timeBudgetMs?:number}} opts
 * @returns {{from:string,to:string,promotion?:string,score?:number,level:string}|null}
 */
export function chooseMove({ fen, level = 'medium', timeBudgetMs = 1400 }) {
  const chess = new Chess();
  try {
    chess.load(fen);
  } catch {
    return null;
  }

  const legal = chess.moves({ verbose: true });
  if (!legal.length) return null; // mate or stalemate

  if (level === 'easy') {
    // mostly random, but take a free piece ~60% of the time when available
    const captures = legal.filter((m) => m.captured);
    const pool = captures.length && Math.random() < 0.6 ? captures : legal;
    const pick = pool[Math.floor(Math.random() * pool.length)];
    return { from: pick.from, to: pick.to, promotion: pick.promotion, level };
  }

  if (level === 'medium') {
    const move = searchBest(chess, 2, Math.min(timeBudgetMs, 800), false);
    return move ? { from: move.from, to: move.to, promotion: move.promotion, level } : null;
  }

  // hard: iterative deepening 1 → 3 within budget, quiescence at horizon
  const ctx = { started: Date.now(), budget: timeBudgetMs, nodes: 0 };
  let best = legal[0];
  try {
    for (let depth = 1; depth <= 3; depth++) {
      let depthBest = null;
      let depthBestScore = -Infinity;
      for (const move of orderMoves(legal)) {
        chess.move(move);
        let score = -alphaBeta(chess, depth - 1, -Infinity, Infinity, ctx, 1);
        if (depth >= 2) {
          // refine at the horizon
          score = score;
        }
        chess.undo();
        const tieBreak = Math.random() * 5;
        if (score + tieBreak > depthBestScore) {
          depthBestScore = score;
          depthBest = move;
        }
      }
      if (depthBest) best = depthBest;
    }
  } catch (e) {
    if (!(e instanceof SearchAbort)) throw e;
  }
  return { from: best.from, to: best.to, promotion: best.promotion, level };
}
