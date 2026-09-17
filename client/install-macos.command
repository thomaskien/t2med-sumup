#!/bin/bash
set -euo pipefail
cd -- "$(dirname -- "$0")"
case "$(uname -m)" in arm64) arch=arm64;; x86_64) arch=amd64;; *) echo 'Nicht unterstützte Architektur.'; exit 1;; esac
test -f client.json && test -f server.crt || { echo 'client.json und server.crt aus der Serverinstallation fehlen.'; exit 1; }
umask 077
target="$HOME/Library/Application Support/Kienzle-SumUp"
app="$HOME/Applications/Kienzle-SumUp.app"
mkdir -p "$target" "$HOME/Applications"
asset="kienzle-sumup-darwin-$arch"
if [ ! -f "$asset" ]; then
  curl -fL --proto '=https' --tlsv1.2 "https://github.com/thomaskien/t2med-sumup/releases/download/v1.3/$asset" -o "$asset"
  curl -fL --proto '=https' --tlsv1.2 'https://github.com/thomaskien/t2med-sumup/releases/download/v1.3/SHA256SUMS' -o SHA256SUMS
fi
expected=$(awk -v name="$asset" '$2==name {print $1}' SHA256SUMS)
actual=$(shasum -a 256 "$asset"); actual=${actual%% *}
test -n "$expected" && test "$expected" = "$actual" || { echo 'Prüfsumme stimmt nicht.'; exit 1; }
install -m 700 "$asset" "$target/kienzle-sumup"
install -m 600 client.json "$target/client.json"
install -m 644 server.crt "$target/server.crt"
source_file=$(mktemp -t kienzle-sumup)
trap 'rm -f "$source_file"' EXIT
cat > "$source_file" <<'APPLESCRIPT'
on run
    display dialog "Kienzle-SumUp wird aus der Patientenakte in T2med gestartet." buttons {"OK"} default button "OK"
end run
on open location theURL
    set starterPath to (POSIX path of (path to home folder)) & "Library/Application Support/Kienzle-SumUp/kienzle-sumup"
    do shell script (quoted form of starterPath) & " --url " & (quoted form of theURL) & " >/dev/null 2>&1 &"
end open location
APPLESCRIPT
osacompile -o "$app" "$source_file"
plist="$app/Contents/Info.plist"
/usr/libexec/PlistBuddy -c 'Delete :CFBundleURLTypes' "$plist" 2>/dev/null || true
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes array' "$plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0 dict' "$plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes array' "$plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes:0 string kienzle-sumup' "$plist"
/usr/libexec/PlistBuddy -c 'Set :CFBundleIdentifier de.kienzle.sumup.starter' "$plist" 2>/dev/null || /usr/libexec/PlistBuddy -c 'Add :CFBundleIdentifier string de.kienzle.sumup.starter' "$plist"
/usr/libexec/PlistBuddy -c 'Add :LSUIElement bool true' "$plist" 2>/dev/null || true
codesign --force --deep --sign - "$app"
'/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister' -f "$app"
security add-trusted-cert -r trustRoot -k "$HOME/Library/Keychains/login.keychain-db" "$target/server.crt"
echo 'Kienzle-SumUp eingerichtet. Kienzledoku bleibt separat registriert.'
