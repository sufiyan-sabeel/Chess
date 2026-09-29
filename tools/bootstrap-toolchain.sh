#!/usr/bin/env bash
# =============================================================================
# Checkmate — Android toolchain bootstrap (Termux / minimal Linux, no Gradle)
# -----------------------------------------------------------------------------
# Downloads ONLY what is required to turn Kotlin sources into a signed APK:
#   1. kotlin-compiler  (javac-equivalent for Kotlin)
#   2. kotlin-stdlib     (referenced by compiled Kotlin code)
#   3. r8               (provides D8 dexer + optional R8 shrinker)
#   4. android.jar       (Android API 30 platform, from dl.google.com)
#
# Everything lands in tools/toolchain/ which is .gitignore'd.
# The script is idempotent: re-running skips already-present artifacts.
# Total download ~129 MB, on-disk ~122 MB (the platform zip is deleted after
# android.jar is extracted).
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TC="$ROOT/tools/toolchain"
mkdir -p "$TC"

KOTLIN_VER="2.1.20"
R8_VER="8.5.35"
PLATFORM_URL="https://dl.google.com/android/repository/platform-30_r03.zip"

log() { printf '\033[1;34m[bootstrap]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[bootstrap] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# fetch <url> <dest> [min_bytes]
fetch() {
  local url="$1" dest="$2" min="${3:-1024}"
  if [ -s "$dest" ] && [ "$(wc -c <"$dest")" -ge "$min" ]; then
    log "cached: $(basename "$dest") ($(wc -c <"$dest") bytes)"
    return 0
  fi
  log "downloading $(basename "$dest") ..."
  curl -fSL --retry 3 --retry-delay 2 --connect-timeout 20 -o "$dest.part" "$url" \
    || die "download failed: $url"
  local sz
  sz="$(wc -c <"$dest.part")"
  [ "$sz" -ge "$min" ] || die "download too small ($sz bytes): $url"
  mv "$dest.part" "$dest"
  log "ok: $(basename "$dest") ($sz bytes)"
}

# --- 1. Kotlin compiler -------------------------------------------------------
fetch "https://repo1.maven.org/maven2/org/jetbrains/kotlin/kotlin-compiler/${KOTLIN_VER}/kotlin-compiler-${KOTLIN_VER}.jar" \
      "$TC/kotlin-compiler.jar" 50000000

# --- 2. Kotlin stdlib ---------------------------------------------------------
fetch "https://repo1.maven.org/maven2/org/jetbrains/kotlin/kotlin-stdlib/${KOTLIN_VER}/kotlin-stdlib-${KOTLIN_VER}.jar" \
      "$TC/kotlin-stdlib.jar" 1000000

# --- 2b. Kotlin compiler companion jars (the Maven artifact is not a fat jar) --
KLIB="$TC/kotlin-lib"
mkdir -p "$KLIB"
fetch "https://repo1.maven.org/maven2/org/jetbrains/kotlinx/kotlinx-coroutines-core-jvm/1.8.0/kotlinx-coroutines-core-jvm-1.8.0.jar" \
      "$KLIB/kotlinx-coroutines-core-jvm.jar" 1000000
fetch "https://repo1.maven.org/maven2/org/jetbrains/kotlin/kotlin-script-runtime/${KOTLIN_VER}/kotlin-script-runtime-${KOTLIN_VER}.jar" \
      "$KLIB/kotlin-script-runtime.jar" 20000
fetch "https://repo1.maven.org/maven2/org/jetbrains/kotlin/kotlin-reflect/1.6.10/kotlin-reflect-1.6.10.jar" \
      "$KLIB/kotlin-reflect.jar" 1000000
fetch "https://repo1.maven.org/maven2/org/jetbrains/intellij/deps/trove4j/1.0.20200330/trove4j-1.0.20200330.jar" \
      "$KLIB/trove4j.jar" 100000
fetch "https://repo1.maven.org/maven2/org/jetbrains/annotations/13.0/annotations-13.0.jar" \
      "$KLIB/annotations.jar" 10000
fetch "https://repo1.maven.org/maven2/org/jetbrains/kotlin/kotlin-stdlib-jdk8/${KOTLIN_VER}/kotlin-stdlib-jdk8-${KOTLIN_VER}.jar" \
      "$KLIB/kotlin-stdlib-jdk8.jar" 500

# --- 3. R8 / D8 ---------------------------------------------------------------
fetch "https://dl.google.com/android/maven2/com/android/tools/r8/${R8_VER}/r8-${R8_VER}.jar" \
      "$TC/r8.jar" 10000000

# --- 4. android.jar (extract ONLY android.jar from the platform zip) ----------
if [ ! -s "$TC/android.jar" ]; then
  fetch "$PLATFORM_URL" "$TC/platform.zip" 40000000
  log "extracting android.jar from platform zip ..."
  # BusyBox unzip has no -Z; use Python to pull ONLY android.jar (the platform
  # zip expands to ~180 MB, which this disk cannot afford).
  python3 - "$TC/platform.zip" "$TC/android.jar" <<'PY'
import sys, zipfile
src, dest = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(src) as z:
    names = [n for n in z.namelist() if n.endswith("/android.jar") or n == "android.jar"]
    if not names:
        raise SystemExit("android.jar not found inside platform zip")
    with z.open(names[0]) as f, open(dest, "wb") as out:
        while True:
            chunk = f.read(1 << 20)
            if not chunk:
                break
            out.write(chunk)
print("extracted", names[0], "->", dest)
PY
  rm -f "$TC/platform.zip"
  log "ok: android.jar ($(wc -c <"$TC/android.jar") bytes); platform zip removed"
else
  log "cached: android.jar"
fi

# --- Verify the toolchain actually runs ---------------------------------------
log "verifying toolchain ..."
KCP="$TC/kotlin-compiler.jar:$(ls -1 "$TC/kotlin-lib"/*.jar 2>/dev/null | paste -sd: -)"
echo "$KCP" > "$TC/kotlin-compiler.cp"
set +e
java -cp "$KCP" org.jetbrains.kotlin.cli.jvm.K2JVMCompiler -version >"$TC/.kv.out" 2>&1
KV_RC=$?
set -e
grep -v -i -E 'jansi|UnsatisfiedLinkError|^\s+at ' "$TC/.kv.out" | head -5
[ "$KV_RC" -eq 0 ] || die "kotlin compiler did not run (rc=$KV_RC)"
grep -q "kotlinc-jvm" "$TC/.kv.out" || die "kotlin compiler version banner missing"
rm -f "$TC/.kv.out"
java -cp "$TC/r8.jar" com.android.tools.r8.D8 --version 2>&1 | head -2 \
  || die "D8 did not run"
grep -aq "Android" "$TC/android.jar" || log "warning: android.jar sanity check inconclusive"

log "toolchain ready in $TC"
du -sh "$TC"
df -h "$ROOT" | tail -1
