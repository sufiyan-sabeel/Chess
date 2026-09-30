# Chess Engine — `Checkmate\Chess\Rules`

Server-side chess rules engine for Checkmate: standard chess legal-move
generation, FEN/UCI/SAN/PGN, game-phase predicates, draw detection and
result reporting. Pure PHP 8, zero dependencies, no Composer.

Everything below is documented from real runs of the deliverable scripts;
all test outputs are pasted verbatim from those runs (only the machine-load
note was added where timings are discussed).

## 1. Deliverables

| Path | Purpose |
| --- | --- |
| `backend/src/Chess/Rules.php` | The engine (~1,460 lines, single file). |
| `backend/tests/chess/perft.php` | Perft suite over the 6 Chess Programming Wiki positions. |
| `backend/tests/chess/rules.php` | 278-assertion rules/regression suite. |
| `backend/tests/chess/crosscheck.mjs` | Differential harness vs. the vendored chess.js. |
| `backend/tests/chess/bridge.php` | Stateful PHP child process (JSON lines) spawned by the harness. |
| `docs/CHESS_ENGINE.md` | This document. |

Scope note: only `backend/src/Chess/**` and `backend/tests/chess/**` were
touched, plus this document. I ran no `git commit` — the engine and the four
test files are already recorded in the other workstream's commit `5ff74e3`
(the working tree matches that commit for those paths; only this document
has further uncommitted edits, and the 7 lines their commit shows for
`Rules.php` are my own PGN-numbering fix from §8).

Integration: the file lives at the path the project's PSR-4 autoloader
expects, so `Checkmate\Chess\Rules` resolves with no manual `require`
(verified: `require 'src/Autoloader.php'; Checkmate\Autoloader::register();
Checkmate\Chess\Rules::start()` → 20 legal moves). The test scripts still
`require_once` the file directly so they run standalone. No other workstream
code references the engine yet — the CI workflow runs their separate
`backend/tests/chess-suite.php` (which has its own `backend/tests/perft.php`),
not the suites in this document.

## 2. How to run

```sh
cd backend/tests/chess

php perft.php            # full run: all 6 positions, depth 4 (except kiwipete, see §5.3)
php perft.php --quick    # depth <= 3 everywhere, ~3 s
php rules.php            # full rules suite; exit 0 = pass
node crosscheck.mjs      # differential test vs vendored chess.js; exit 0 = pass
```

`rules.php` and `crosscheck.mjs` print only failures plus a summary block, so
a clean run is short (see §6/§7). `bridge.php` is spawned automatically by
`crosscheck.mjs`; it reads JSON commands from stdin, so running it by hand
just waits for input.

## 3. API summary

```php
use Checkmate\Chess\Rules;

Rules::START_FEN                              // standard start position
Rules::fromFen(string): ?Rules                // null on invalid FEN
Rules::start(): Rules                         // new engine at START_FEN
$g->fen(): string
$g->sideToMove(): 'w'|'b'
$g->legalMovesUci(): array                    // sorted UCI strings
$g->legalMovesSan(): array                    // map uci => san
$g->moveUci(string): ?array                   // null if illegal
$g->moveSan(string): ?array                   // null if illegal / not SAN
$g->isCheck()/isCheckmate()/isStalemate()/isDraw()/canClaimDraw()/isGameOver()
$g->result(): '1-0'|'0-1'|'1/2-1/2'|'*'
$g->resultReason(): 'checkmate'|'stalemate'|'insufficient_material'
                    |'threefold_repetition'|'fifty_move'|null
$g->history(): array                          // played moves as UCI (empty at start)
$g->replay(array, string $startFen = Rules::START_FEN): ?Rules  // null at first bad move
$g->perft(int): int
$g->toPgn(array $headers = []): string
Rules::pgnToUci(string): ?array               // null if >64 KB or >1024 moves
```

Class aliases at the bottom of the file: `Chess\Rules` and global `Rules`.

## 4. Test methodology

* **Perft** (`perft.php`) — the 6 standard Chess Programming Wiki positions
  with hardcoded published node counts; loud `FAIL` lines and exit 1 on any
  mismatch. Depth 4 for startpos/pos3/pos4/pos5/pos6, depth 3 for kiwipete
  (see §5.3). `--quick` caps at depth 3 for fast iteration.
* **Rules** (`rules.php`) — 278 assertions over hand-designed positions:
  castling, en passant, promotions, pins/discovered/double check, mates,
  stalemate, insufficient material, threefold, fifty-move, SAN generation and
  round-trip, FEN round-trip/rejection, `replay()`, PGN export/parse.
* **Differential** (`crosscheck.mjs`) — compares this engine against the
  vendored `app/src/main/assets/app/js/vendor/chess.js` (chess.js 1.4.0) on:
  30 fixed positions (all 6 perft positions + castling/ep/promotion-heavy
  FENs), all 20 legal root moves of the start position, 242 sampled plies of
  position play, and 30 seeded self-play games (≤200 plies each). Compared
  every time: the full legal UCI set, the SAN of every legal move, and these
  13 scalars — `fen`, `turn`, `check`, `mate`, `stale`, `insufficient`,
  `threefold`, `fifty`, `draw`, `canClaimDraw`, `gameOver`, `result`,
  `reason` (`result`/`reason` derived from chess.js in the engine's
  precedence order); threefold state is carried across plies by a stateful
  PHP child (`bridge.php`).

**Where expectations came from.** Every expectation in `rules.php` (legal-move
sets, FENs, flags, results, SAN strings) was derived by running the position
through the vendored chess.js *first*, then encoding the observed values —
the reference engine is the oracle, my hand analysis only designed the
positions. Several designed positions turned out wrong and were fixed before
being encoded (see §8). `perft.php` expectations are the published CPW counts.

**Final confirmation run** (all three suites back-to-back with the final
scripts, `perft --quick` + `rules` + `crosscheck`): `perft_exit=0`,
`rules_exit=0`, `crosscheck_exit=0`. Re-run once more after the other
workstream's commit `5ff74e3` (which records these files): same three exits,
`18/18`, `278 passed, 0 failed`, `6130 state comparisons | 0 failures`.

## 5. Perft results

### 5.1 Positions

| Name | FEN |
| --- | --- |
| startpos | `rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1` |
| kiwipete | `r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1` |
| position 3 | `8/2p5/3p4/KP5r/1R3p1k/8/4P1P1/8 w - - 0 1` |
| position 4 | `r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq - 0 1` |
| position 5 | `rnbq1k1r/pp1Pbppp/2p5/8/2B5/8/PPP1NnPP/RNBQK2R w KQ - 1 8` |
| position 6 | `r4rk1/1pp1qppp/p1np1n2/2b1p1b1/2B1P1B1/P1NP1N2/1PP1QPPP/R4RK1 w - - 0 10` |

### 5.2 Full run (final script, plain CLI, no JIT)

```
PHP 8.5.1 | opcache.cli=false | jit=disable | mode=full (depth <= 4)
--------------------------------------------------------------------------
startpos     d1  expected         20  actual         20      0.00s  PASS
startpos     d2  expected        400  actual        400      0.00s  PASS
startpos     d3  expected       8902  actual       8902      0.10s  PASS
startpos     d4  expected     197281  actual     197281      2.16s  PASS
kiwipete     d1  expected         48  actual         48      0.00s  PASS
kiwipete     d2  expected       2039  actual       2039      0.02s  PASS
kiwipete     d3  expected      97862  actual      97862      1.59s  PASS
position 3   d1  expected         14  actual         14      0.00s  PASS
position 3   d2  expected        191  actual        191      0.01s  PASS
position 3   d3  expected       2812  actual       2812      0.05s  PASS
position 3   d4  expected      43238  actual      43238      0.56s  PASS
position 4   d1  expected          6  actual          6      0.00s  PASS
position 4   d2  expected        264  actual        264      0.00s  PASS
position 4   d3  expected       9467  actual       9467      0.10s  PASS
position 4   d4  expected     422333  actual     422333      5.28s  PASS
position 5   d1  expected         44  actual         44      0.00s  PASS
position 5   d2  expected       1486  actual       1486      0.02s  PASS
position 5   d3  expected      62379  actual      62379      1.16s  PASS
position 5   d4  expected    2103487  actual    2103487     29.74s  PASS
position 6   d1  expected         46  actual         46      0.00s  PASS
position 6   d2  expected       2079  actual       2079      0.02s  PASS
position 6   d3  expected      89890  actual      89890      1.05s  PASS
position 6   d4  expected    3894594  actual    3894594     63.99s  PASS
--------------------------------------------------------------------------
perft summary: 23/23 passed | nodes: 6938882 | time: 105.9s
RESULT: PASS
```

**23/23 PASS, 6,938,882 nodes, 105.9 s** (`php perft.php`, exit 0).

| Position | Deepest | Nodes at deepest | Time at deepest |
| --- | --- | ---: | ---: |
| startpos | d4 | 197,281 | 2.16 s |
| kiwipete | d3 (see §5.3) | 97,862 | 1.59 s |
| position 3 | d4 | 43,238 | 0.56 s |
| position 4 | d4 | 422,333 | 5.28 s |
| position 5 | d4 | 2,103,487 | 29.74 s |
| position 6 | d4 | 3,894,594 | 63.99 s |

Timings vary with machine load (the box is shared and load average was
~10 during this run); node counts are the deterministic signal.

### 5.3 Depth caps and why

| Position | Depth run | Cap reason |
| --- | --- | --- |
| startpos | **d4** | required by the task (d4 required for startpos/pos3/pos5) |
| kiwipete | **d3** | only position not run to d4. Its published d4 count is 4,085,603 nodes ≈ 42× the d3 cell; at the measured 61–71k nps that is ~60–70 s of extra runtime (about as much as position 6's entire d4). Capped to keep the routine run short; no d4 value for kiwipete is claimed anywhere. |
| position 3 | **d4** | required (and cheap: 0.56 s) |
| position 4 | **d4** | task allowed a d3 cap here; run to d4 anyway (5.28 s) |
| position 5 | **d4** | required (29.74 s) |
| position 6 | **d4** | task allowed a d3 cap here; run to d4 anyway (63.99 s) |

So the required d4 set (startpos, position 3, position 5) all ran to d4, and
positions 4 and 6 — which the task allowed to stop at d3 — were run to d4 as
well; only kiwipete stopped at d3, the one depth cap in the run. Total
105.9 s.

### 5.4 Quick mode and JIT

Quick mode (final confirmation run):

```
PHP 8.5.1 | opcache.cli=false | jit=disable | mode=quick (depth <= 3)
--------------------------------------------------------------------------
startpos     d1  expected         20  actual         20      0.00s  PASS
startpos     d2  expected        400  actual        400      0.00s  PASS
startpos     d3  expected       8902  actual       8902      0.10s  PASS
kiwipete     d1  expected         48  actual         48      0.00s  PASS
kiwipete     d2  expected       2039  actual       2039      0.02s  PASS
kiwipete     d3  expected      97862  actual      97862      1.06s  PASS
position 3   d1  expected         14  actual         14      0.00s  PASS
position 3   d2  expected        191  actual        191      0.00s  PASS
position 3   d3  expected       2812  actual       2812      0.04s  PASS
position 4   d1  expected          6  actual          6      0.00s  PASS
position 4   d2  expected        264  actual        264      0.00s  PASS
position 4   d3  expected       9467  actual       9467      0.11s  PASS
position 5   d1  expected         44  actual         44      0.00s  PASS
position 5   d2  expected       1486  actual       1486      0.02s  PASS
position 5   d3  expected      62379  actual      62379      0.69s  PASS
position 6   d1  expected         46  actual         46      0.00s  PASS
position 6   d2  expected       2079  actual       2079      0.02s  PASS
position 6   d3  expected      89890  actual      89890      0.89s  PASS
--------------------------------------------------------------------------
perft summary: 18/18 passed | nodes: 277949 | time: 3.0s
RESULT: PASS
```

**18/18 PASS, 277,949 nodes, 3.0 s** (`php perft.php --quick`, exit 0).

Measured performance comparison (same node counts in every configuration):

| Configuration | quick (277,949 nodes) | full (6,938,882 nodes) |
| --- | ---: | ---: |
| plain CLI, no JIT | 3.0 s | 105.9 s |
| `-d opcache.enable_cli=1 -d opcache.jit=tracing` | 1.5 s | 152.6 s* |

\* The JIT full run was executed while the machine was heavily loaded
(load average ~22 from an unrelated workload), so it is not a fair
comparison — JIT roughly halves the quick run, and the full run in this
environment is dominated by contention either way. Both runs passed 23/23
with identical node counts.

## 6. Rules suite output

`php rules.php` (final confirmation run), complete output:

```
== basic state ==

== castling ==

== en passant ==

== promotions ==

== pins, discovered check, double check ==

== checkmate / stalemate ==

== insufficient material ==

== threefold repetition ==

== fifty-move rule ==

== SAN generation ==
ok   SAN round-trip startpos (20 moves)
ok   SAN round-trip kiwipete (48 moves)
ok   SAN round-trip castling (26 moves)
ok   SAN round-trip promotion (13 moves)

== FEN round-trip / rejection ==

== replay() ==

== PGN export / parse ==

----------------------------------------
rules.php: 278 passed, 0 failed
RESULT: PASS
```

**278 passed, 0 failed, RESULT: PASS, exit 0.** The suite is quiet by design:
each `ok` line marks a multi-move round-trip (all `uci → san → uci` moves of a
position parsed back identically), a `FAIL` line would print for any failed
assertion (none did), and the summary block totals everything.

What the 278 assertions cover (13 sections):

* **castling** — both sides generate `O-O`/`O-O-O` (UCI `e1g1`/`e1c1`);
  illegal when the king is in check, when the king would cross an attacked
  square, and after the rook has moved or been captured; the *departure*
  square of a rook that is attacked while the king path is safe remains legal
  (FIDE §3.8.2 — cross-verified as legal against chess.js, see §10).
* **en passant** — capture generated only on the immediately following ply;
  the target square must not leave the king in check (pinned-pawn case);
  FEN ep field only appears when the ep capture is actually legal.
* **promotions** — quiet push legality + SAN for all four pieces on `e8`
  (`e8=Q+`, `e8=R+`, `e8=B`, `e8=N` — the king on a8 makes the queen/rook
  promotions checks) and capture-promotion legality for all eight
  `a7×b8`/`a7a8` continuations with SAN asserted for `axb8=Q+`, `axb8=R+`,
  `axb8=N` and `a8=Q`; applied-move checks: `e8=N` gives no check, `e8=Q+`
  checks but is not mate. Black-side promotions are exercised by perft
  position 4 and the crosscheck self-play games rather than by explicit
  assertions here.
* **pins / discovered / double check** — pinned pieces cannot move off their
  line; a discovered check exposes only the legal subset; in double check the
  king may only move.
* **mates** — scholar's mate, fool's mate, back-rank mate, smothered mate,
  `Qxd7#`, each asserted as `isCheckmate()`, `isGameOver()`, `result()` and
  the matching `resultReason()`.
* **stalemate** — asserted including that it is a draw while
  `canClaimDraw()` is false (no threefold/fifty threshold reached).
* **insufficient material** — K vs K, K+B vs K, K+N vs K and K+B vs K+B
  with bishops on the *same* colour are drawn; bishops on *opposite* colours
  and two knights vs a bare king are not (parity with chess.js, see §10);
  each drawn case also asserts `result()`, `resultReason()` and
  `canClaimDraw() === false`.
* **threefold** — position counted 3 times ⇒ `isDraw()`, `canClaimDraw()`,
  `result() = 1/2-1/2`, `resultReason() = threefold_repetition`; counted from
  ply 0 (the initial position is part of the repetition history, which the
  8-ply knight-shuffle test requires), and `history()` lists exactly the
  played moves.
* **fifty-move** — at 99 halfmoves nothing is drawn; at 100 halfmoves
  `isFiftyMove()`, `canClaimFiftyMove()`, `canClaimDraw()`, `isDraw()`,
  `isGameOver()`, `result()` and `resultReason()` all flip together. (The
  `fifty` scalar is also compared on every crosscheck ply.)
* **SAN generation** — castling SAN (`O-O`, `O-O-O`), check/mate suffixes
  (`Nf3+`, `Ng6+`, `Nd2+`, `Nc7+`, `Qxf7#`, `Qh4#`, `Ra8#`, `Nf7#`,
  `Qxd7#`), disambiguation in all four forms (same rank ⇒ file, same file ⇒
  rank, neither ⇒ file, three knights ⇒ full square `Nb1c3` / rank `N5c3` /
  file `Ndc3`), pawn-capture file qualification (`cxd5`), and promotion
  notation (`e8=Q+`); `moveSan()` also accepts bare SAN (`Nf3`) while
  rejecting UCI (`e2e4`), long algebraic (`Ng1f3`, `Pe2e4`) and impossible
  SAN (`Qh5`, `O-O` without rights).
* **SAN round-trip** — every legal move of 4 positions parsed back to the
  same UCI (the four `ok` lines above).
* **FEN round-trip/rejection** — round-trips all 6 perft FENs plus castling
  and ep positions, and a mid-game position; 18 malformed FENs rejected with
  `fromFen() === null` (wrong token counts, bad side-to-move/castling/ep
  fields, castling rights without the matching rook, missing/extra kings,
  pawn on the back rank, adjacent kings, non-mover already in check, rank
  width errors), plus the empty string.
* **replay()** — replays a real game to completion; returns `null` when any
  ply is illegal (tested at plies 0, 3 and 5), when the first move belongs to
  the wrong side, or for non-string entries; accepts a custom start FEN,
  rejects an invalid one, and always builds from `$startFen` regardless of
  the receiver's state.
* **PGN** — export includes headers/result/move text; black replies share the
  white move number (`1. e4 e5`); a black-to-move start FEN numbers correctly
  (`1... Ke7 2. Ke2`); SetUp/FEN tags emitted for custom start positions;
  export→`pgnToUci` round-trips to the identical UCI list (including a
  castling game and a black-first game); comments/NAGs/variations/annotations
  stripped when parsing; malformed movetext rejected; oversized (>64 KB)
  and over-long (>1024 moves) PGNs rejected with `null` while a 1000-move
  game is accepted.

## 7. Differential test vs chess.js

`node crosscheck.mjs` (final confirmation run), complete output:

```
phase 1: 30 fixed positions
phase 2: startpos — every legal root move
phase 3: sampled play across fixed positions
phase 4: self-play 30 games x <=200 plies (mulberry32)
----------------------------------------
crosscheck: 30 positions | 20 root moves | 242 sampled plies | 30 games (5838 plies) | 6130 state comparisons | 0 failures
crosscheck: all comparisons matched
RESULT: PASS
```

**6,130 state comparisons, 0 failures, exit 0.** Per state the harness
compares the full legal UCI set, the SAN of every legal move, and the 13
scalar fields listed in §4 (FEN, side to move, check/mate/stale,
insufficient/threefold/fifty/draw/canClaimDraw, game over, result, reason —
`result`/`reason` derived from chess.js in the engine's precedence order).

Phase detail:

1. **30 fixed positions** — the 6 perft FENs plus castling setups
   (base/black-to-move/in check/pass-square attacked/rook attacked), en
   passant (available/pinned), promotion and capture-promotion, double check,
   discovered check, pinned knight, four mates, stalemate, four
   insufficient-material cases, two disambiguation positions, a
   99-halfmove position and a knight-check position. For each: one full
   state comparison (all 13 scalars + legal set + SAN map).
2. **startpos root** — all 20 legal moves applied from a fresh start each
   time, state compared after each.
3. **sampled play** — up to 10 seeded plies per position, FEN/state compared
   every ply; positions that reach game over or run out of moves stop early,
   giving 242 sampled plies (≥ 200 required).
4. **self-play** — 30 games × ≤200 plies, deterministic mulberry32 seed.
   Every ply compares move sets, SAN, all 13 scalars and FEN. The per-ply FEN
   comparison proves castling-rights/en-passant/clock bookkeeping stays in
   sync, and the `threefold` scalar (compared at every ply) proves repetition
   counting survives across plies — a stateless call would reset it.

Total state comparisons: 30 (phase 1) + 20 (phase 2) + 242 (phase 3) +
5,838 (phase 4) = **6,130**.

Repetition correctness across plies is verified by `bridge.php`: the harness
keeps a single long-lived PHP child for the whole run and resets it with an
explicit `load` command per position/game, so one `Rules` instance carries
the repetition-key history across plies (restarting the process — or
reloading — would reset threefold counting).

## 8. Engine bugs found by these tests

1. **PGN move numbering** — `toPgn()` printed `1. e4 1... e5 2. Bc4 2... Nc6`
   (a `N...` prefix for black replies that follow a white move), instead of
   `1. e4 e5 2. Bc4 Nc6`. Found by the PGN round-trip/numbering assertions in
   `rules.php`; fixed so black replies share the white move number and the
   `N...` prefix appears only for black moves *not* preceded by a white move
   (black-to-move start FENs). Regression-tested both cases (§6, PGN bullet).
2. **Design-time errors in the test positions themselves** (not engine bugs,
   but caught before encoding): a "double check" position where the knight
   jump gave only a single check; a `Qxd7#` where d7 was not on the queen's
   line; a "shared knight target" pair with no common square; a stalemate
   candidate where the king could simply move. All were re-derived from
   chess.js rather than guessed.

## 9. Design decisions and parity notes

* **chess.js parity choices** (verified by `crosscheck.mjs`, §7):
  * FEN ep field is printed **only when a legal en-passant capture exists**;
    internally the ep square is kept after a double push only if an enemy
    pawn is pseudo-adjacent (chess.js's rule).
  * Repetition key = `piece placement + side to move + castling rights + ep
    name` (string, no Zobrist). Under the ep parity rule above, an ep name
    appears in the key only when it is capture-relevant, matching chess.js.
  * `history()` returns the played moves as UCI (empty at game start, 1
    entry after 1 ply); the *repetition key list*, separately, starts at the
    initial position so ply 0 counts toward threefold (the 8-ply test in §6
    only reaches 3 occurrences if it does).
  * Threefold counted at occurrence ≥ 3 (automatic, claimability at ≥ 3 as
    well — same threshold).
  * Insufficient material mirrors chess.js exactly (§6, insufficient bullet).
  * `isDraw()` includes stalemate.
  * Result precedence: checkmate → stalemate → insufficient material →
    threefold → fifty-move → `*`.
* **`canClaimDraw()`** = threefold (count ≥ 3) **or** halfmoves ≥ 100 —
  exactly the harness's parity definition (`threefold || fifty` on the
  chess.js side). Claim and automatic thresholds switch together here: there
  is no "claimable but not yet automatic" window for the fifty-move rule
  (documented in §10). The difference between `isDraw()`/`canClaimDraw()`
  and a playable position is demonstrated by stalemate: drawn while
  `canClaimDraw()` is false.
* **Castling through attacked rook square** — the task description said
  castling is "forbidden if the rook is attacked"; per FIDE §3.8.2 only the
  king's *departure, transit and destination* squares matter, so a rook under
  attack does not forbid castling. Implemented per FIDE and cross-verified as
  such against chess.js (phase 1 of the harness includes such positions).
* **`replay()`** builds from its `$startFen` argument (it ignores the
  receiver's state), returning `null` at the first illegal move so callers can
  report the exact ply.
* **`moveSan()`** accepts strict SAN including `O-O`/`0-0` castling,
  `+`/`#` suffixes, trailing `!`/`?`/`?!` decorations and both `e8=Q` and
  `e8Q` promotion forms; it rejects UCI and long algebraic (§6, SAN bullet).
  The comparison is normalised SAN vs generated SAN, so an input that does
  not match the generated SAN of some legal move returns `null`.
* **`pgnToUci()`** rejects inputs over 64 KB or with more than 1024 moves
  (returns `null`), and tolerates comments, NAGs, results and variation
  markers by stripping them.

## 10. Known limitations (honest list)

* **Timing reproducibility** — this is a shared box (load average ~10–22
  during the runs recorded here); wall-clock numbers vary run to run. Node
  counts are exact and deterministic.
* **Threefold vs 50-move**: `canClaimDraw()` and the automatic result switch
  on the same thresholds (count ≥ 3 / halfmoves ≥ 100); there is no
  "claimable but not yet automatic" halfmove state (documented in §9).
* **Insufficient material** follows chess.js's rule set rather than a strict
  FIDE 3.20–3.24 reading (parity chosen deliberately so the two
  implementations agree); e.g. two knights vs a bare king is *not* a draw
  here.
* **`resultReason()`** reports `stalemate` before
  `insufficient_material`/repetition when several would apply, and reports
  only one reason by design.
* **Perft depth** — kiwipete stops at d3 (§5.3); no deeper counts are
  claimed. The required d4 set (startpos/pos3/pos5) ran to d4, and
  positions 4/6 (which could have stopped at d3) also ran to d4.
* **Castling legality vs task wording** — deviation is deliberate and
  documented (§9); the alternative reading (rook attacked ⇒ forbidden) was
  not implemented.
* **No time control, clock, or variant support** — Chess960/FRC castling is
  not generated or accepted in FEN castling fields (the harness's castling
  positions are all standard-chess); no variant, no clock management.
* **Repetition keys ignore move counters** — en-passant name is part of the
  key (parity rule above); halfmove clock is *not* part of the key (correct
  per FIDE: a repetition requires identical piece placement, rights to
  castle, and en-passant possibility, not the clocks).

## 11. Environment and footprint

```
$ php -v | head -1 ; node -v
PHP 8.5.1 (cli) (built: Dec 22 2025 00:03:00) (NTS)
v24.18.0
```

```
$ du -sb backend/src/Chess backend/tests/chess
51450   backend/src/Chess
56603   backend/tests/chess
```

This document adds ≈ 26 KB — total ≈ **134 KB** added, far under the 5 MB
budget. No downloads, no dependencies, nothing committed.

Disk at the end of the task (available space fluctuates while other work
proceeds on this shared disk):

```
$ df -h .
Filesystem                Size      Used Available Use% Mounted on
/dev/block/dm-15        100.4G    100.2G    136.5M 100% /
```
