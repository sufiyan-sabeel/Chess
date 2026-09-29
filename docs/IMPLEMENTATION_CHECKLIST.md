# Checkmate — Implementation Checklist

Legend: `[ ]` not started · `[~]` in progress · `[x]` implemented **and tested** · `[!]` blocked / needs external configuration
`[v]` implemented but **untestable on device** in this environment (structurally verified only)

---

## PHASE 1 — Foundation [x]

- [x] Repository audit (empty repo, Termux ARM64, 377 MB free disk)
- [x] Toolchain bootstrap without Gradle (kotlinc 2.1.20, r8 8.5.35, android.jar API 30)
- [x] Android shell: Kotlin WebView Activity (asset serving over synthetic https origin)
- [x] WebView security hardening (file access off, cleartext off, navigation locked, narrow JS bridge)
- [x] Design tokens scaffolded (manifest/theme/colors aligned to the charcoal+green palette)
- [x] Launcher icon (original vector, no third-party assets)
- [x] No-Gradle build pipeline: aapt2 → kotlinc → D8/R8 → zipalign → apksigner
- [x] Release build signed, v1/v2/v3 verified, 16/16 artifact checks pass
- [x] Local dev keystore auto-generation with explicit "not for distribution" labelling
- [x] docs/BUILD_STATUS.md evidence log
- [v] App launches on device — **not executable here** (no device/emulator)

## PHASE 2 — Complete offline chess

- [ ] chess.js vendored and loaded from bundled assets (no CDN)
- [ ] Responsive 8×8 board (SVG pieces, coordinates)
- [ ] Legal move generation, selection, legal-move dots, last-move + check highlights
- [ ] Pawn promotion UI (Q/R/B/N)
- [ ] Tournament clocks (monotonic, increment, background/resume, timeout)
- [ ] Game endings: checkmate, stalemate, insufficient material, threefold, 50-move
- [ ] Takeback/undo in local modes
- [ ] PGN export / import / FEN round-trip
- [ ] Local game persistence (IndexedDB)
- [ ] Computer opponent (Easy/Medium/Hard, legal moves only, never plays human's side)
- [ ] Automated chess tests (castling, en passant, promotion, pins, mate, stalemate, draws)
- [ ] Automated clock tests

## PHASE 3 — Finished mobile UI

- [ ] Splash / onboarding / guest play
- [ ] Home (greeting, play modes, recent games, rating summary, connection status)
- [ ] Mode selection with all presets (bullet/blitz/rapid/classical/custom) + explanation
- [ ] Live game screen (player cards, clocks, move list, captures, controls)
- [ ] Statistics (rating graph, per-mode records, filters, empty/loading states)
- [ ] Leaderboard screen (server-backed, pagination, search, offline state)
- [ ] Puzzles (verified dataset, hints, difficulty, progress)
- [ ] Learn chess (lessons, interactive, progression, resume)
- [ ] Game review (replay, SAN/UCI, copy/export/import PGN, analysis screen)
- [ ] Profile (name, verification status, ratings, history, settings, logout, delete)
- [ ] Settings (themes, sound, haptics, coordinates, accessibility, reduce motion, about, version, attribution)
- [ ] Watch (real games or explained empty state — never fake data)
- [ ] More menu wiring — all navigation performs real actions
- [ ] Narrow-screen / landscape / inset testing

## PHASE 4 — PHP backend

- [ ] Schema + migrations (users, tokens, ratings, matches, moves, history, puzzles)
- [ ] register / login / logout / refresh / me
- [ ] email verification + resend (needs SMTP: [!])
- [ ] forgot / reset password
- [ ] account deletion
- [ ] Rate limiting, generic responses, security headers, CORS allowlist
- [ ] Backend test suite executed against a real local MariaDB

## PHASE 5 — Online multiplayer

- [ ] Matchmaking (quick match by mode, private invite codes)
- [ ] Signaling (SDP offer/answer, ICE) over PHP
- [ ] WebRTC DataChannel move exchange between two clients
- [ ] Protocol validation (schema, sequence numbers, size limits, colour enforcement)
- [ ] Disconnect / reconnect / resync
- [ ] Draw offer / resign / result messages
- [ ] Server-side move-history validation before any rating change
- [ ] Two-client test executed

## PHASE 6 — Ratings & leaderboard

- [ ] Elo with configurable K, transactional updates, duplicate protection
- [ ] Leaderboard endpoints (stable ranking, pagination, search, mode filter, current-user rank)
- [ ] Frontend leaderboard bound to real records
- [ ] Transaction / concurrency tests

## PHASE 7 — Optimization

- [ ] Measure APK size with full assets
- [ ] Asset audit (SVG vs raster, no large fonts, no unused files)
- [ ] Startup / offline / network-failure testing

## PHASE 8 — Release

- [ ] Full test run (JS + backend + Android build)
- [ ] Release APK + checksum + BUILD_INFO
- [ ] GitHub Actions workflow (build, test, artifact, release-on-tag)
- [ ] Documentation set complete (PRD, ARCHITECTURE, API, SECURITY, TESTING, RELEASE, BUILD_STATUS, README)
- [ ] Final engineering report
