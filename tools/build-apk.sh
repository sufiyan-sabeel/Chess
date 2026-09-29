#!/usr/bin/env bash
# =============================================================================
# Checkmate — APK build pipeline (no Gradle required)
# -----------------------------------------------------------------------------
# aapt2  : compile + link resources / manifest into a base APK
# kotlinc: compile the Android shell
# D8/R8  : dex (debug: D8, release: R8 with conservative keep rules)
# zipalign: 4-byte align entries (pure-Python, replaces the missing binary)
# apksigner: v1+v2 sign + verify
#
# Usage:
#   tools/build-apk.sh [debug|release]
#
# Signing (release):
#   CHECKMATE_KEYSTORE / CHECKMATE_KEYSTORE_PASSWORD /
#   CHECKMATE_KEY_ALIAS / CHECKMATE_KEY_PASSWORD  -> used when all set.
#   Otherwise a LOCAL DEV keystore is generated at tools/keystore/local-dev.jks
#   (gitignored) and the artifact is labelled "dev-signed" — never as a
#   production release.
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TC="$ROOT/tools/toolchain"
SRC="$ROOT/app/src/main"
BUILD="$ROOT/build"
DIST="$ROOT/dist"
MODE="${1:-release}"

[ -d "$TC" ] || { echo "toolchain missing — run tools/bootstrap-toolchain.sh first" >&2; exit 1; }
KCP="$(cat "$TC/kotlin-compiler.cp")"

VERSION_NAME="${VERSION_NAME:-1.0.0}"
VERSION_CODE="${VERSION_CODE:-1}"

# versionCode = major*10000 + minor*100 + patch, keep in sync with VERSION_NAME
if [ "$VERSION_NAME" = "1.0.0" ] && [ "$VERSION_CODE" = "1" ]; then
  VERSION_CODE=10000
fi

log() { printf '\033[1;36m[build]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[build] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

rm -rf "$BUILD"
mkdir -p "$BUILD/dex" "$DIST"

# ---------------------------------------------------------------- 1) resources
log "aapt2 compile ..."
aapt2 compile --dir "$SRC/res" -o "$BUILD/res.zip"

log "aapt2 link ..."
aapt2 link \
  -o "$BUILD/base.apk" \
  -I "$TC/android.jar" \
  --manifest "$SRC/AndroidManifest.xml" \
  --min-sdk-version 21 \
  --target-sdk-version 34 \
  --version-code "$VERSION_CODE" \
  --version-name "$VERSION_NAME" \
  --auto-add-overlay \
  -R "$BUILD/res.zip" \
  -A "$SRC/assets" \
  2>&1 | tee "$BUILD/aapt2-link.log" || die "aapt2 link failed (see $BUILD/aapt2-link.log)"

# ------------------------------------------------------------------ 2) compile
log "kotlinc ..."
java -Xmx1024m -cp "$KCP" org.jetbrains.kotlin.cli.jvm.K2JVMCompiler \
  -no-stdlib -no-reflect \
  -classpath "$TC/android.jar:$TC/kotlin-stdlib.jar:$TC/kotlin-lib/annotations.jar" \
  -jvm-target 1.8 \
  -d "$BUILD/classes" \
  "$SRC/java" 2>&1 | tee "$BUILD/kotlinc.log" | grep -v -i -E 'jansi|UnsatisfiedLinkError|^\s+at ' || true

grep -q "^error:" "$BUILD/kotlinc.log" && die "Kotlin compilation failed"
[ -d "$BUILD/classes/com/umaiz/checkmate" ] || die "no classes produced"

log "jar classes ..."
(cd "$BUILD/classes" && zip -q -r "$BUILD/classes.jar" .)

# --------------------------------------------------------------------- 3) dex
if [ "$MODE" = "release" ]; then
  log "R8 (release, shrink stdlib, no obfuscation) ..."
  java -cp "$TC/r8.jar" com.android.tools.r8.R8 \
    --release \
    --lib "$TC/android.jar" \
    --min-api 21 \
    --pg-conf "$ROOT/app/proguard-rules.pro" \
    --output "$BUILD/dex" \
    "$BUILD/classes.jar" "$TC/kotlin-stdlib.jar" 2>&1 | tee "$BUILD/r8.log" | grep -v -i -E 'jansi|UnsatisfiedLinkError' || true
  [ -f "$BUILD/dex/classes.dex" ] || die "R8 produced no classes.dex (see $BUILD/r8.log)"
else
  log "D8 (debug, no shrinking) ..."
  java -cp "$TC/r8.jar" com.android.tools.r8.D8 \
    --release \
    --lib "$TC/android.jar" \
    --min-api 21 \
    --output "$BUILD/dex" \
    "$BUILD/classes.jar" "$TC/kotlin-stdlib.jar" 2>&1 | tee "$BUILD/d8.log" | grep -v -i -E 'jansi|UnsatisfiedLinkError' || true
  [ -f "$BUILD/dex/classes.dex" ] || die "D8 produced no classes.dex (see $BUILD/d8.log)"
fi

log "dex: $(wc -c <"$BUILD/dex/classes.dex") bytes"
python3 "$ROOT/tools/dex_classes.py" "$BUILD/dex/classes.dex" \
  | grep -q 'com.umaiz.checkmate.MainActivity' || die "MainActivity missing from dex"

# ------------------------------------------------- 4) merge dex into base apk
log "packaging ..."
python3 - "$BUILD/base.apk" "$BUILD/dex/classes.dex" "$BUILD/app-raw.apk" <<'PY'
import sys, zipfile, shutil
base, dex, out = sys.argv[1:4]
shutil.copyfile(base, out)
with open(dex, "rb") as f, zipfile.ZipFile(out, "a", zipfile.ZIP_DEFLATED) as z:
    if "classes.dex" not in z.namelist():
        z.writestr("classes.dex", f.read())
with zipfile.ZipFile(out) as z:
    if "classes.dex" not in z.namelist():
        raise SystemExit("classes.dex missing after merge")
PY

# ---------------------------------------------------------------- 5) alignment
log "zipalign (4) ..."
python3 "$ROOT/tools/zipalign.py" "$BUILD/app-raw.apk" "$BUILD/app-aligned.apk" 4
python3 "$ROOT/tools/zipalign.py" --check "$BUILD/app-aligned.apk" 4 || die "alignment check failed"

# ------------------------------------------------------------------ 6) signing
KS_FILE="${CHECKMATE_KEYSTORE:-}"
KS_PASS="${CHECKMATE_KEYSTORE_PASSWORD:-}"
KS_ALIAS="${CHECKMATE_KEY_ALIAS:-checkmate}"
KS_KEY_PASS="${CHECKMATE_KEY_PASSWORD:-}"
SIGNING_LABEL="external-keystore"

if [ -z "$KS_FILE" ] || [ ! -f "$KS_FILE" ]; then
  KS_FILE="$ROOT/tools/keystore/local-dev.jks"
  KS_PASS="${CHECKMATE_LOCAL_DEV_PASSWORD:-checkmate-local-dev}"
  KS_KEY_PASS="$KS_PASS"
  SIGNING_LABEL="dev-keystore(auto-generated, NOT for distribution)"
  if [ ! -f "$KS_FILE" ]; then
    log "generating local dev keystore ..."
    mkdir -p "$(dirname "$KS_FILE")"
    keytool -genkeypair -noprompt \
      -keystore "$KS_FILE" -storetype PKCS12 \
      -storepass "$KS_PASS" -keypass "$KS_KEY_PASS" \
      -alias "$KS_ALIAS" -keyalg RSA -keysize 2048 -validity 10000 \
      -dname "CN=Checkmate Local Dev, OU=Checkmate, O=Umaiz Sufiyan, C=XX" \
      >/dev/null 2>&1 || die "keystore generation failed"
  fi
  echo "WARNING: signing with an auto-generated LOCAL DEV keystore." >&2
  echo "         This is not a production identity. Set CHECKMATE_KEYSTORE*" >&2
  echo "         (or GitHub secrets in CI) for a real release." >&2
fi

OUT_NAME="checkmate-${VERSION_NAME}-${MODE}.apk"
log "signing ($SIGNING_LABEL) ..."
apksigner sign \
  --ks "$KS_FILE" \
  --ks-key-alias "$KS_ALIAS" \
  --ks-pass "pass:$KS_PASS" \
  --key-pass "pass:$KS_KEY_PASS" \
  --out "$DIST/$OUT_NAME" \
  "$BUILD/app-aligned.apk"

log "verifying signature ..."
apksigner verify --verbose --print-certs "$DIST/$OUT_NAME" > "$BUILD/signature-report.txt" 2>&1 \
  || die "signature verification failed"
grep -E 'Verified using|Signer #1 certificate DN' "$BUILD/signature-report.txt" | head -4

# ------------------------------------------------------------- 7) inspection
log "badging ..."
aapt2 dump badging "$DIST/$OUT_NAME" > "$BUILD/badging.txt" 2>&1 || die "aapt2 dump badging failed"
grep -E "package:|launchable-activity:|application:|sdkVersion" "$BUILD/badging.txt" | head -6

SIZE_BYTES="$(wc -c <"$DIST/$OUT_NAME")"
SHA="$(sha256sum "$DIST/$OUT_NAME" | cut -d' ' -f1)"
echo "$SHA  $OUT_NAME" > "$DIST/$OUT_NAME.sha256"

log "APK: $DIST/$OUT_NAME"
log "size: $SIZE_BYTES bytes ($(awk "BEGIN{printf \"%.2f\", $SIZE_BYTES/1048576}") MiB)"
log "sha256: $SHA"

{
  echo "app: Checkmate"
  echo "version_name: $VERSION_NAME"
  echo "version_code: $VERSION_CODE"
  echo "mode: $MODE"
  echo "signing: $SIGNING_LABEL"
  echo "size_bytes: $SIZE_BYTES"
  echo "sha256: $SHA"
  echo "built_utc: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "host: $(uname -sr) / $(uname -m)"
  echo "package: com.umaiz.checkmate"
} > "$DIST/BUILD_INFO.txt"

log "done."
