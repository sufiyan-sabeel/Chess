# Checkmate — Build Status

> Honest engineering record. **Only checked items have evidence.**
> Regenerate evidence with `tools/build-apk.sh <mode> && tools/verify-apk.sh <apk>`.

Last updated: 2026-09-29 (Phase 1 complete)

## Environment used for local builds

| Item | Value |
|---|---|
| Host | Termux on Android (aarch64), PRoot kernel 6.17 |
| Free disk at start | **377 MB** (hard constraint — no Gradle/Android SDK install possible) |
| JDK | OpenJDK 21.0.12 (aarch64) |
| Node | v24.18.0 |
| PHP | 8.5.1 (CLI) |
| MariaDB | 10.3.20 server binaries present, not yet initialised |
| aapt2 | 2.20-android-16.0.0_r4 (Termux package) |
| apksigner | 0.9 (Termux package) |
| Gradle / Android SDK | **absent** → replaced by a purpose-built no-Gradle pipeline |

## Toolchain (tools/bootstrap-toolchain.sh)

Downloaded once into `tools/toolchain/` (git-ignored), 98.9 MB total:

- kotlin-compiler 2.1.20 (+ companion jars: coroutines, script-runtime, reflect, trove4j, annotations)
- kotlin-stdlib 2.1.20
- r8 8.5.35 (D8 dexer + R8 shrinker)
- android.jar from `platform-30_r03.zip` (only `android.jar` extracted, zip deleted)

## Build pipeline (tools/build-apk.sh)

`aapt2 compile/link → kotlinc → D8(debug) or R8(release) → merge dex → zipalign(4, pure Python) → apksigner sign → verify`

- **debug**: D8, no shrinking.
- **release**: R8, shrinks unused library code only — `-dontobfuscate -dontoptimize`
  (obfuscation withheld deliberately: it cannot be validated without a device).

### Signing

Local builds auto-generate `tools/keystore/local-dev.jks` (git-ignored, password
`checkmate-local-dev` unless `CHECKMATE_LOCAL_DEV_PASSWORD` is set).
Such APKs are labelled **dev-signed** and must not be published as a production
release. Production signing = set `CHECKMATE_KEYSTORE*` env vars (CI: GitHub secrets).

## Evidence log

### Phase 1 — foundation [x]

Commands executed:

```
tools/bootstrap-toolchain.sh          # rc=0, toolchain ready (98.9M)
tools/build-apk.sh debug              # rc=0
tools/build-apk.sh release            # rc=0
tools/verify-apk.sh dist/checkmate-1.0.0-release.apk   # 16 passed, 0 failed
```

Verification results (release APK):

```
[PASS] non-empty (29332 bytes)
[PASS] signature v1 / v2 / v3 verified
[PASS] package id com.umaiz.checkmate
[PASS] versionCode='10000' versionName='1.0.0'
[PASS] launcher activity com.umaiz.checkmate.MainActivity
[PASS] permissions minimal: INTERNET, VIBRATE
[PASS] 4-byte aligned entries
[PASS] classes.dex present (34444 bytes) — 53 classes (R8 shrunk from 2 051 904 bytes / D8 debug)
[PASS] MainActivity + NativeBridge in dex
[PASS] index.html bundled
sha256 a093db022b063d6579c2cfc8a0dfe8914a912f36161a475242f806fbe2874664
```

Debug APK: 635 540 bytes, sha256 `43fa6247...90bee`.

### Not tested (no device/emulator in this environment)

- Installation on a real Android device / emulator (`adb` unavailable, no emulator image fits the disk budget)
- Actual Activity launch, WebView rendering, back-button behaviour, rotation
- On-device performance and memory behaviour

These are marked **[!] untested on device** everywhere in the checklist.
The APK is structurally valid: signed, parseable, correct manifest, dex parses.

## Known blockers

| Blocker | Impact | Required to clear |
|---|---|---|
| No Android device/emulator | Cannot run the app, no instrumentation tests | A device with `adb`, or CI job on a hosted runner |
| No Gradle/SDK locally (disk) | CI workflow cannot be executed locally | GitHub Actions run (SDK provided on runners) |
| No mail provider configured | Email verification/password reset emails cannot send | SMTP/API credentials in `backend/.env` |
| No public HTTPS host | Production API/WebRTC signaling unreachable | Deploy backend + TLS + TURN |

## Next task

Phase 2 — complete offline chess (chess.js integration, board, clocks, bot, PGN, rules tests).
