# Checkmate

Checkmate is a chess app for Android built as two cooperating parts: a self-contained HTML/CSS/JS client bundled inside a thin Kotlin WebView shell, and a dependency-free PHP 8 REST backend.

Created by **Umaiz Sufiyan**.

## Repository layout

| Path | What it is |
|---|---|
| `app/src/main/assets/app/` | The product itself: a vanilla JS client with no framework and no build step. Screens for home, play, game, puzzles, learn, leaderboard, watch, review, stats, profile, settings, onboarding and auth. `chess.js` is vendored in `js/vendor/`. |
| `app/src/main/java/com/umaiz/checkmate/MainActivity.kt` | Kotlin WebView shell. Serves the bundled assets from the synthetic origin `https://appassets.androidplatform.net/` through `shouldInterceptRequest`, so `localStorage` and IndexedDB behave normally (a `file://` origin does not). `configChanges` keeps an active game and its running clock alive across rotation. |
| `backend/` | PHP 8.1+ REST API implementing `docs/API.md`. No Composer packages, no framework, no ORM: PSR-4 autoloading over `src/`, plain SQL migrations, MariaDB. |
| `tools/` | No-Gradle APK pipeline: `bootstrap-toolchain.sh`, `build-apk.sh`, `verify-apk.sh` (kotlinc + d8/R8 + aapt2 + apksigner). |
| `tests/frontend/` | Node test suite for the JS client. |
| `docs/` | API, backend, engine, checklist and build-status documentation. |

## Tests

Node 18 or newer, no install step:

```bash
node --test tests/frontend/*.test.mjs
node tests/frontend/verify-puzzles.mjs
```

Last verified on 2026-11-08: 68 of 68 frontend tests pass, and all 12 bundled puzzles are validated against `chess.js`.

## Building the APK

There is deliberately no Gradle project in this repository; see `docs/BUILD_STATUS.md` for the reasoning and the exact environment it was developed in. Build with the scripts in `tools/`:

```bash
bash tools/bootstrap-toolchain.sh   # one-time: downloads the local toolchain into tools/toolchain (git-ignored)
bash tools/build-apk.sh
bash tools/verify-apk.sh <path-to-apk>
```

The pipeline expects a Linux or Termux host with a JDK, plus `aapt2` and `apksigner` available from the Android SDK build-tools.

## Running the backend

PHP 8.1+ and MariaDB 10.6+:

```bash
cd backend
bash bin/dev.sh     # private DB, migrations, API on :8080
bash bin/test.sh    # full cycle: DB -> migrate -> API -> test suite
```

The emulator reaches the host API at `http://10.0.2.2:8080/api/v1`. Production traffic is HTTPS-only, enforced by `app/src/main/res/xml/network_security_config.xml`.

## Documentation

- `docs/API.md` - REST contract, response envelope and error codes
- `docs/BACKEND.md` - backend architecture
- `docs/CHESS_ENGINE.md` - engine, bot and clock design
- `docs/BUILD_STATUS.md` - honest record of what has and has not been built and verified
- `docs/IMPLEMENTATION_CHECKLIST.md` - feature status

## Status and limitations

- The APK pipeline targets a Linux/Termux host and has not been verified from a clean checkout in a standard CI environment.
- `app/src/main/assets/app/js/vendor/chess.js` is vendored, so rules changes require updating that file by hand.
- No license file is included in this repository.
