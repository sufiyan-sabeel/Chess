/**
 * Bundled tactical puzzle set.
 *
 * Every position in this file is verified by `tests/frontend/verify-puzzles.mjs`
 * against the vendored chess.js:
 *   - `mate1` puzzles must contain at least one legal mating move and
 *     `solution` must list *every* such move (so accepting a second mating
 *     move is correct, not accidental);
 *   - `mate2` puzzles must hold for *any* legal defence: after `solution[0]`
 *     every reply the opponent can play must allow a mate in one.
 *
 * Nothing here is generated at runtime and nothing is invented: if a position
 * ever fails verification the build's test step fails with it.
 *
 * `solution` moves are UCI (`e2e4`, `e7e8q`).
 * `line` (mate2 only) is the scripted reply the app plays back; the verify
 * script proves every other legal reply also loses to a mate in one.
 */

export const PUZZLES = [
  {
    id: 'p01-back-rank',
    fen: '6k1/5ppp/8/8/8/8/5PPP/R5K1 w - - 0 1',
    turn: 'w',
    kind: 'mate1',
    solution: ['a1a8'],
    difficulty: 1,
    theme: 'Back rank',
    goal: 'White to move. Deliver checkmate in one move.',
    source: 'Classic back-rank pattern',
  },
  {
    id: 'p02-fools-mate',
    fen: 'rnbqkbnr/pppp1ppp/8/4p3/6P1/5P2/PPPPP2P/RNBQKBNR b KQkq g3 0 2',
    turn: 'b',
    kind: 'mate1',
    solution: ['d8h4'],
    difficulty: 1,
    theme: "Fool's mate",
    goal: 'Black to move. Punish the weakened kingside with mate in one.',
    source: "Fool's mate, the shortest game in chess",
  },
  {
    id: 'p03-scholars-mate',
    fen: 'r1bqkbnr/pppp1ppp/2n5/4p2Q/2B1P3/8/PPPP1PPP/RNB1K1NR w KQkq - 4 4',
    turn: 'w',
    kind: 'mate1',
    solution: ['h5f7'],
    difficulty: 1,
    theme: 'Scholar’s mate',
    goal: 'White to move. Take the f7 pawn and end the game.',
    source: "Scholar's mate",
  },
  {
    id: 'p04-king-rook-mate',
    fen: '7k/8/6K1/8/8/8/8/R7 w - - 0 1',
    turn: 'w',
    kind: 'mate1',
    solution: ['a1a8'],
    difficulty: 1,
    theme: 'King and rook',
    goal: 'White to move. Use your king to take away the escape squares.',
    source: 'Basic K+R vs K mate',
  },
  {
    id: 'p05-queen-king-mate',
    fen: '7k/5Q2/6K1/8/8/8/8/8 w - - 0 1',
    turn: 'w',
    kind: 'mate1',
    solution: ['f7e8', 'f7f8', 'f7g7', 'f7h7'],
    difficulty: 2,
    theme: 'Queen and king',
    goal: 'White to move. Find every move that checkmates immediately.',
    source: 'Basic K+Q vs K mate',
  },
  {
    id: 'p06-smothered',
    fen: '6rk/6pp/7N/8/8/8/8/6K1 w - - 0 1',
    turn: 'w',
    kind: 'mate1',
    solution: ['h6f7'],
    difficulty: 2,
    theme: 'Smothered mate',
    goal: 'White to move. The king is buried by its own pieces — find the knight move.',
    source: 'Smothered mate pattern',
  },
  {
    id: 'p07-queen-back-rank',
    fen: '6k1/5ppp/8/8/8/8/5PPP/2Q3K1 w - - 0 1',
    turn: 'w',
    kind: 'mate1',
    solution: ['c1c8'],
    difficulty: 2,
    theme: 'Back rank',
    goal: 'White to move. Mate on the eighth rank.',
    source: 'Back-rank pattern with a queen',
  },
  {
    id: 'p08-two-rooks',
    fen: '6k1/5ppp/8/8/8/8/5PPP/1R1R2K1 w - - 0 1',
    turn: 'w',
    kind: 'mate1',
    solution: ['b1b8', 'd1d8'],
    difficulty: 2,
    theme: 'Rook battery',
    goal: 'White to move. Both rooks can finish the job — find all of them.',
    source: 'Two-rook back-rank mate',
  },
  {
    id: 'p09-blocker-capture',
    fen: '3r2k1/5ppp/8/8/8/8/5PPP/3Q2K1 w - - 0 1',
    turn: 'w',
    kind: 'mate1',
    solution: ['d1d8'],
    difficulty: 3,
    theme: 'Remove the defender',
    goal: 'White to move. The bishop is the only thing stopping mate — deal with it.',
    source: 'Back rank with interposing defender',
  },
  {
    id: 'p10-rook-interference',
    fen: '6k1/5ppp/3r4/8/8/8/5PPP/1R4K1 w - - 0 1',
    turn: 'w',
    kind: 'mate2',
    solution: ['b1b8'],
    line: ['d6d8'],
    difficulty: 3,
    theme: 'Forced mate in two',
    goal: 'White to move. Force mate in two — the rook can only delay it.',
    source: 'Back-rank mate with one legal defence',
  },
  {
    id: 'p11-knight-interference',
    fen: '6k1/5ppp/3n4/8/8/8/5PPP/1R4K1 w - - 0 1',
    turn: 'w',
    kind: 'mate2',
    solution: ['b1b8'],
    line: ['d6c8'],
    difficulty: 3,
    theme: 'Forced mate in two',
    goal: 'White to move. Force mate in two against any knight block.',
    source: 'Back-rank mate with two legal defences',
  },
  {
    id: 'p12-queen-back-rank-open',
    fen: '6k1/5ppp/8/8/8/8/5PPP/1Q4K1 w - - 0 1',
    turn: 'w',
    kind: 'mate1',
    solution: ['b1b8'],
    difficulty: 2,
    theme: 'Back rank',
    goal: 'White to move. The queen can strike from several files — check them all.',
    source: 'Back-rank pattern with a queen on the b-file',
  },
];
