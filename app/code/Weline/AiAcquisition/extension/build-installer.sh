#!/usr/bin/env bash
# Build macOS one-click installer package (WelineExtensionInstaller.app + zip)
# Usage: bash extension/build-installer.sh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
DL="$MODULE_DIR/view/statics/downloads"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# 1) extension zip (build if missing)
ZIP="$DL/ai-acquisition-extension.zip"
if [ ! -f "$ZIP" ]; then
  (cd "$MODULE_DIR" && zip -qr "$ZIP" extension \
    -x 'extension/README.md' 'extension/build.sh' 'extension/build-installer.sh' 'extension/tests/*' 'extension/wasm/agent_core.wat')
fi

# 2) assemble .app
APP="$STAGE/WelineExtensionInstaller.app"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"
cp "$ZIP" "$APP/Contents/Resources/extension.zip"
cat > "$APP/Contents/Info.plist" <<'PLIST'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleExecutable</key>
    <string>installer</string>
    <key>CFBundleName</key>
    <string>Weline Extension Installer</string>
    <key>CFBundleIdentifier</key>
    <string>com.weline.acquisition.installer</string>
    <key>CFBundleVersion</key>
    <string>1.3.0</string>
    <key>CFBundleShortVersionString</key>
    <string>1.3.0</string>
    <key>CFBundlePackageType</key>
    <string>APPL</string>
    <key>LSUIElement</key>
    <true/>
</dict>
</plist>
PLIST

# 3) installer body via base64 (UTF-8 safe)
{
  echo 'IyEvYmluL2Jhc2gKIyBXZWxpbmUgYWNxdWlzaXRpb24gZXh0ZW5zaW9uIG9uZS1jbGljayBpbnN0YWxsZXIgKG1hY09TKQpz'
  echo 'ZXQgLXUKQVBQX0RJUj0iJChjZCAiJChkaXJuYW1lICIkMCIpLy4uLy4uIiAmJiBwd2QpIgpSRVM9IiRBUFBfRElSL0NvbnRl'
  echo 'bnRzL1Jlc291cmNlcyIKWklQPSIkUkVTL2V4dGVuc2lvbi56aXAiClRBUkdFVF9ESVI9IiRIT01FL0Rvd25sb2Fkcy9leHRl'
  echo 'bnNpb24gMyIKTE9HPSIkSE9NRS9Eb3dubG9hZHMvd2VsaW5lLWV4dGVuc2lvbi1pbnN0YWxsLmxvZyIKRVhUX0lEPSJvaGNw'
  echo 'b2NuY2Jra25na2tjYmtlZGJsbmVpZ2FrZGVvZSIKWkhfVElUTEU9IiQocHJpbnRmICVzIFYyVnNhVzVsSU9pT3QrV3VvdWFK'
  echo 'cWVXeGxlV3VpZWlqaGVXWnFBPT0gfCAvdXNyL2Jpbi9iYXNlNjQgLWQpIgpaSF9PSz0iJChwcmludGYgJXMgNW9tcDViR1Y1'
  echo 'YmV5NWE2SjZLT0Y3N3lJZGlSV1JWTHZ2SW5qZ0lMb3I3ZmxuS2dnWTJoeWIyMWxPaTh2WlhoMFpXNXphVzl1Y3lEcG9iWG5n'
  echo 'cm5rdUlEbXJLSGpnSXpwaDQzbWxyRGxpcURvdmIzamdJM21qSW5wa3E3dnZJemxobzNsaUxmbWxyRGxrSTdsajdEb3Y1RG9v'
  echo 'WXpwb2JYbGpiUGxqNi9rdmIvbmxLampnSUk9IHwgL3Vzci9iaW4vYmFzZTY0IC1kKSIKWkhfQkFEX0JVTkRMRT0iJChwcmlu'
  echo 'dGYgJXMgNWE2SjZLT0Y1WXlGNW8yZjVaMlA3N3lhNXB5cTVvbSs1WWl3SUdWNGRHVnVjMmx2Ymk1NmFYQT0gfCAvdXNyL2Jp'
  echo 'bi9iYXNlNjQgLWQpIgpaSF9CQURfVU5aSVA9IiQocHJpbnRmICVzIDZLZWo1WTZMNWFTeDZMU2w3N3lNNksrMzZZZU42SytW'
  echo 'IHwgL3Vzci9iaW4vYmFzZTY0IC1kKSIKWkhfQkFEX01BTklGRVNUPSIkKHByaW50ZiAlcyA1YTZKNktPRjVhU3g2TFNsNzd5'
  echo 'YWJXRnVhV1psYzNRdWFuTnZiaURudkxybHBMRT0gfCAvdXNyL2Jpbi9iYXNlNjQgLWQpIgpzYXkoKSB7IGVjaG8gIlskKGRh'
  echo 'dGUgJyslSDolTTolUycpXSAkKiIgPj4gIiRMT0ciOyB9Cm1zZygpIHsgL3Vzci9iaW4vb3Nhc2NyaXB0IC1lICJkaXNwbGF5'
  echo 'IGRpYWxvZyBcIiQxXCIgd2l0aCB0aXRsZSBcIiRaSF9USVRMRVwiIGJ1dHRvbnMge1wiT0tcIn0gZGVmYXVsdCBidXR0b24g'
  echo 'XCJPS1wiIHdpdGggaWNvbiBub3RlIiA+L2Rldi9udWxsIDI+JjE7IH0Kc2F5ICI9PT0gc3RhcnQgaW5zdGFsbCA9PT0iCmlm'
  echo 'IFsgISAtZiAiJFpJUCIgXTsgdGhlbgogIHNheSAiRVJST1I6IGV4dGVuc2lvbi56aXAgbm90IGZvdW5kIGluIGJ1bmRsZSIK'
  echo 'ICBtc2cgIiRaSF9CQURfQlVORExFIgogIGV4aXQgMQpmaQpta2RpciAtcCAiJFRBUkdFVF9ESVIiCmlmICEgdW56aXAgLW8g'
  echo 'LXEgIiRaSVAiIC1kICIkVEFSR0VUX0RJUiIgPi9kZXYvbnVsbCAyPiYxOyB0aGVuCiAgc2F5ICJFUlJPUjogdW56aXAgZmFp'
  echo 'bGVkIgogIG1zZyAiJFpIX0JBRF9VTlpJUCIKICBleGl0IDEKZmkKc2F5ICJmaWxlcyB3cml0dGVuIHRvOiAkVEFSR0VUX0RJ'
  echo 'UiIKaWYgWyAhIC1mICIkVEFSR0VUX0RJUi9tYW5pZmVzdC5qc29uIiBdOyB0aGVuCiAgc2F5ICJFUlJPUjogbWFuaWZlc3Qu'
  echo 'anNvbiBtaXNzaW5nIgogIG1zZyAiJFpIX0JBRF9NQU5JRkVTVCIKICBleGl0IDEKZmkKVkVSPSIkKC91c3IvYmluL3B5dGhv'
  echo 'bjMgLWMgJ2ltcG9ydCBqc29uLHN5cztwcmludChqc29uLmxvYWQob3BlbihzeXMuYXJndlsxXSkpLmdldCgidmVyc2lvbiIs'
  echo 'Ij8iKSknICIkVEFSR0VUX0RJUi9tYW5pZmVzdC5qc29uIiAyPi9kZXYvbnVsbCB8fCBlY2hvICc/JykiCnNheSAiZXh0ZW5z'
  echo 'aW9uIHZlcnNpb246ICRWRVIiCi91c3IvYmluL29zYXNjcmlwdCAtZSAndGVsbCBhcHBsaWNhdGlvbiAiR29vZ2xlIENocm9t'
  echo 'ZSIgdG8gYWN0aXZhdGUnID4vZGV2L251bGwgMj4mMQovdXNyL2Jpbi9vc2FzY3JpcHQgLWUgInRlbGwgYXBwbGljYXRpb24g'
  echo 'XCJHb29nbGUgQ2hyb21lXCIgdG8gb3BlbiBsb2NhdGlvbiBcImNocm9tZTovL2V4dGVuc2lvbnMvP2lkPSRFWFRfSURcIiIg'
  echo 'Pi9kZXYvbnVsbCAyPiYxCnNheSAib3BlbmVkIGNocm9tZTovL2V4dGVuc2lvbnMiCm1zZyAiJHtaSF9PSy8vXCRWRVIvJFZF'
  echo 'Un0iCnNheSAiPT09IGluc3RhbGwgZG9uZSA9PT0iCmV4aXQgMAo='
} | /usr/bin/base64 -d > "$APP/Contents/MacOS/installer"
chmod +x "$APP/Contents/MacOS/installer"

# 4) pack zip (ASCII name only)
OUT="$DL/weline-extension-installer-mac.zip"
rm -f "$OUT"
( cd "$STAGE" && zip -q -r -X "$OUT" WelineExtensionInstaller.app )
echo "BUILD_OK $OUT ($(stat -f%z "$OUT") bytes)"
