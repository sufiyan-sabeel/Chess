#!/usr/bin/env bash
# =============================================================================
# Checkmate — artifact verification.
# Every claim about an APK in docs/BUILD_STATUS.md comes from this script.
#   usage: tools/verify-apk.sh <path-to.apk>
# Exits non-zero when any required check fails.
# =============================================================================
set -uo pipefail

APK="${1:-}"
[ -n "$APK" ] && [ -f "$APK" ] || { echo "usage: $0 <apk>" >&2; exit 2; }
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok()   { echo "  [PASS] $*"; PASS=$((PASS+1)); }
bad()  { echo "  [FAIL] $*"; FAIL=$((FAIL+1)); }

echo "== verifying $APK =="

# 1) exists and non-trivial
SIZE=$(wc -c <"$APK")
if [ "$SIZE" -gt 10000 ]; then ok "non-empty ($SIZE bytes)"; else bad "suspicious size ($SIZE bytes)"; fi

# 2) signature
SIG=$(apksigner verify --verbose "$APK" 2>&1)
for scheme in "v1 scheme" "v2 scheme" "v3 scheme"; do
  if echo "$SIG" | grep -q "Verified using $scheme.*true"; then ok "signature $scheme verified"
  else bad "signature $scheme NOT verified"; fi
done

# 3) badging / identity
BAD=$(aapt2 dump badging "$APK" 2>&1)
echo "$BAD" | grep -q "package: name='com.umaiz.checkmate'" && ok "package id com.umaiz.checkmate" || bad "wrong package id"
echo "$BAD" | grep -o "versionCode='[0-9]*'" | head -1 | grep -q "[0-9]" && ok "$(echo "$BAD" | grep -o "versionCode='[0-9]*' versionName='[^']*'" | head -1)" || bad "versionCode missing"
echo "$BAD" | grep -q "launchable-activity: name='com.umaiz.checkmate.MainActivity'" && ok "launcher activity present" || bad "launcher activity missing"
VNAME=$(echo "$BAD" | grep -o "versionName='[^']*'" | head -1)
case "$VNAME" in
  *"\$"*|*'"'*) bad "versionName looks templated: $VNAME" ;;
  *) ok "versionName readable: $VNAME" ;;
esac

# 4) permissions: only what we declared
PERMS=$(echo "$BAD" | grep "^uses-permission" | grep -o "name='[^']*'" | sed "s/name='//;s/'//" | sort)
EXPECTED=$(printf "android.permission.INTERNET\nandroid.permission.VIBRATE")
if [ "$PERMS" = "$EXPECTED" ]; then ok "permissions minimal: INTERNET, VIBRATE"
else bad "unexpected permissions: $(echo $PERMS)"; fi

# 5) alignment (pre-signature entries only)
if python3 "$ROOT/tools/zipalign.py" --check "$APK" 4 2>/dev/null; then ok "4-byte aligned entries"
else bad "alignment check failed"; fi

# 6) dex sanity
TMP=$(mktemp -d)
unzip -o -q "$APK" "classes.dex" -d "$TMP" 2>/dev/null
if [ -f "$TMP/classes.dex" ]; then
  ok "classes.dex present ($(wc -c <"$TMP/classes.dex") bytes)"
  if python3 "$ROOT/tools/dex_classes.py" "$TMP/classes.dex" > "$TMP/classes.txt" 2>/dev/null; then
    grep -q "^com.umaiz.checkmate.MainActivity$" "$TMP/classes.txt" && ok "MainActivity in dex" || bad "MainActivity missing from dex"
    grep -q "MainActivity\$NativeBridge" "$TMP/classes.txt" && ok "NativeBridge in dex" || bad "NativeBridge missing from dex"
    ok "dex class count: $(wc -l <"$TMP/classes.txt")"
  else
    bad "dex could not be parsed"
  fi
else
  bad "classes.dex missing"
fi

# 7) bundled assets
unzip -l "$APK" | grep -q "assets/app/index.html" && ok "index.html bundled" || bad "index.html missing"
ENTRY_COUNT=$(unzip -l "$APK" | tail -1 | awk '{print $2}')
ok "apk entry count: $ENTRY_COUNT"

# 8) checksum
echo "  sha256: $(sha256sum "$APK" | cut -d' ' -f1)"
echo "  size:   $SIZE bytes ($(awk "BEGIN{printf \"%.2f\", $SIZE/1048576}") MiB)"

rm -rf "$TMP"
echo "== result: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
