/**
 * Bundled learning lessons — the content behind the Learn tab.
 *
 * Rules:
 *  - every `demo.fen` is a real, loadable chess position (see
 *    tests/frontend/lessons.test.mjs, which loads each one through the
 *    vendored chess.js and asserts the claims the captions make);
 *  - text states chess rules and standard teaching practice only — no
 *    invented engine analysis, no fabricated game history;
 *  - sections are plain facts: a heading, prose, optional bullet list.
 *
 * Shapes:
 *   section = { h3: string, p?: string, ul?: string[] }
 *   demo    = { fen: string, caption: string }
 */

export const LESSONS = [
  {
    id: 'l01-board-pieces',
    title: 'The board and the pieces',
    sub: 'How each piece moves',
    minutes: 4,
    sections: [
      {
        h3: 'The board',
        p: 'Chess is played on an 8×8 grid of alternating light and dark squares. Squares are named by file (a–h, left to right for White) and rank (1–8, starting at White’s side), so e4 is the square at file e, rank 4. White moves first.',
      },
      {
        h3: 'The pieces',
        ul: [
          'Pawn — moves one square forward (two from its starting rank), captures one square diagonally forward.',
          'Rook — any number of squares horizontally or vertically.',
          'Knight — an L-shape (two squares one way, one square at a right angle); it jumps over other pieces.',
          'Bishop — any number of squares diagonally; it stays on squares of one colour.',
          'Queen — any number of squares horizontally, vertically or diagonally: a rook and bishop combined.',
          'King — one square in any direction. It may never move onto a square attacked by an enemy piece.',
        ],
      },
      {
        h3: 'Check',
        p: 'A king is in check when an enemy piece attacks its square. The king must be moved out of check immediately — by moving it, by blocking the attack, or by capturing the attacking piece.',
      },
    ],
    demo: {
      fen: 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
      caption: 'The starting position: White to move.',
    },
  },
  {
    id: 'l02-check-mate-stalemate',
    title: 'Checkmate and stalemate',
    sub: 'How games end without a clock',
    minutes: 4,
    sections: [
      {
        h3: 'Checkmate',
        p: 'The game ends immediately when a king is in check and no legal move removes the threat. That is checkmate: the side delivering it wins. Every checkmate is found by asking what the king cannot escape.',
      },
      {
        h3: 'Stalemate',
        p: 'If a player has no legal move and their king is not in check, the game is a draw — stalemate. It most often appears when one side has a large material advantage but crowds the enemy king with no square left to give.',
      },
      {
        h3: 'Draws decided by the rules',
        ul: [
          'Insufficient material — neither side can checkmate (for example, king versus king, or king and bishop versus king).',
          'Threefold repetition — the same position appears three times with the same side to move and the same rights.',
          'The fifty-move rule — 50 moves pass with no pawn move and no capture (75 moves in the FIDE rulebook is automatic).',
        ],
      },
    ],
    demo: {
      // 1. f3 e5 2. g4 Qh4# — Fool's mate, the shortest game in chess.
      fen: 'rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3',
      caption: 'Checkmate: the h4–e1 diagonal is open, and g4 blocks the escape.',
    },
  },
  {
    id: 'l03-castling',
    title: 'Castling',
    sub: 'One move, two pieces, three conditions',
    minutes: 3,
    sections: [
      {
        h3: 'How it is played',
        p: 'Castling is the only move that moves two pieces at once: the king moves two squares towards a rook, and that rook jumps to the square the king crossed. Kingside is written O-O, queenside O-O-O (0-0 and 0-0-0 also appear).',
      },
      {
        h3: 'When it is legal',
        ul: [
            'Neither the king nor that rook has moved earlier in the game (rook moves cancel only their own side).',
            'There is no piece between the king and the rook.',
            'The king is not in check, does not pass through check, and does not land on an attacked square.',
        ],
      },
      {
        h3: 'Why it matters',
        p: 'Castling connects the rooks and takes the king away from the centre, where it is easiest to attack. Most opening principles boil down to developing pieces and castling before the centre opens.',
      },
    ],
    demo: {
      // "Kiwipete" (perft position 2): both castling rights available.
      fen: 'r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1',
      caption: 'Both sides can castle: try O-O or O-O-O for White.',
    },
  },
  {
    id: 'l04-en-passant',
    title: 'En passant',
    sub: 'The one capture that skips a square',
    minutes: 3,
    sections: [
      {
        h3: 'The rule',
        p: 'When a pawn advances two squares from its starting rank and lands beside an enemy pawn, that enemy pawn may capture it as if it had moved only one square. The capture must be made on the very next move, or the right expires.',
      },
      {
        h3: 'Why it exists',
        p: 'Without it, a pawn could leap past an enemy pawn that would otherwise have been able to capture it — the two-square first move was added for faster games, and en passant keeps the capture relationship intact.',
      },
      {
        h3: 'Reading the position',
        ul: [
          'The captured pawn leaves the board entirely; the capturing pawn ends on the skipped square.',
          'In the FEN notation the eligible square is recorded after the side-to-move field (for example d6).',
          'Only pawns can capture en passant, and only immediately.',
        ],
      },
    ],
    demo: {
      // 1. e4 Nf6 2. e5 d5 — White may answer with exd6 e.p.
      fen: 'rnbqkb1r/ppp1pppp/5n2/3pP3/8/8/PPPP1PPP/RNBQKBNR w KQkq d6 0 3',
      caption: 'White to move: the pawn on e5 can capture en passant on d6.',
    },
  },
  {
    id: 'l05-promotion',
    title: 'Promotion',
    sub: 'Turning a pawn into a queen (or anything else)',
    minutes: 3,
    sections: [
      {
        h3: 'The rule',
        p: 'When a pawn reaches the far rank it must be exchanged, at once, for a queen, rook, bishop or knight of the same colour. The choice is yours — under-promotion is legal and sometimes winning.',
      },
      {
        h3: 'When to under-promote',
        ul: [
          'Knight: when the new piece gives an immediate check or fork that a queen cannot.',
          'Rook or bishop: to deliver check while avoiding stalemate, or in an endgame where a queen would allow a draw by repetition.',
          'Queen: the normal choice — it is the strongest piece.',
        ],
      },
      {
        h3: 'Practical note',
        p: 'The move is not complete until the piece is chosen. Checkmate delivered by a promoting pawn counts only once the promoted piece stands on the board.',
      },
    ],
    demo: {
      fen: '8/P6k/8/8/8/8/7K/8 w - - 0 1',
      caption: 'The a7 pawn can promote on a8 — four choices, all legal.',
    },
  },
  {
    id: 'l06-piece-values',
    title: 'Piece values and material',
    sub: 'Counting what you are up or down',
    minutes: 4,
    sections: [
      {
        h3: 'Traditional values',
        p: 'For counting exchanges, chess uses long-standing approximations: pawn 1, knight 3, bishop 3, rook 5, queen 9. These are teaching tools for comparing trades — not engine evaluations, and not a promise about the outcome of a specific position.',
      },
      {
        h3: 'Using the count',
        ul: [
          'A fair trade gives up roughly what it takes: a knight for a rook is not equal — you are down about two points.',
          'Two minor pieces (about 6) are usually worth more than a rook (about 5) in the middlegame, because the minors coordinate and attack targets.',
          'Bishop pairs are often valued slightly above two bishops of opposite colours because they cover every square together.',
        ],
      },
      {
        h3: 'When the count lies',
        p: 'Material is only one factor. Attack, king safety, passed pawns and the clock can all outweigh a few points — that is why games are decided on the board, not on the abacus. In the app, captured-material totals shown during a game use these same approximations.',
      },
    ],
    demo: {
      // 1. e4 e5 2. Nf3 Nc6 3. Bb5 — equal material, play continues.
      fen: 'r1bqkbnr/pppp1ppp/2n5/1B2p3/4P3/5N2/PPPP1PPP/RNBQK2R b KQkq - 3 3',
      caption: 'Material is level — the count cannot tell you who is better here.',
    },
  },
];
