#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")"
case "$(uname -m)" in x86_64) arch=amd64;; aarch64|arm64) arch=arm64;; armv7l|armv6l) arch=arm;; *) echo 'Nicht unterstützte Architektur.'; exit 1;; esac
target="${XDG_DATA_HOME:-$HOME/.local/share}/kienzle-sumup"
applications="${XDG_DATA_HOME:-$HOME/.local/share}/applications"
test -f client.json && test -f server.crt || { echo 'client.json und server.crt aus der Serverinstallation fehlen.'; exit 1; }
command -v xdg-mime >/dev/null || { echo 'Bitte xdg-utils installieren.'; exit 1; }
umask 077
mkdir -p "$target" "$applications"
asset="kienzle-sumup-linux-$arch"
if [ ! -f "$asset" ]; then
  curl -fL --proto '=https' --tlsv1.2 "https://github.com/thomaskien/t2med-sumup/releases/download/v1.4.1/$asset" -o "$asset"
  curl -fL --proto '=https' --tlsv1.2 'https://github.com/thomaskien/t2med-sumup/releases/download/v1.4.1/SHA256SUMS' -o SHA256SUMS
fi
test -f SHA256SUMS || { echo 'SHA256SUMS fehlt.'; exit 1; }
expected=$(awk -v name="$asset" '$2==name {print $1}' SHA256SUMS)
actual=$(sha256sum "$asset"); actual=${actual%% *}
test -n "$expected" && test "$expected" = "$actual" || { echo 'Prüfsumme stimmt nicht.'; exit 1; }
install -m 700 "$asset" "$target/kienzle-sumup"
install -m 600 client.json "$target/client.json"
install -m 644 server.crt "$target/server.crt"
escaped=${target//\\/\\\\}; escaped=${escaped//\"/\\\"}; escaped=${escaped//\$/\\\$}; escaped=${escaped//\`/\\\`}; escaped=${escaped//%/%%}
cat > "$applications/kienzle-sumup.desktop" <<EOF
[Desktop Entry]
Type=Application
Name=Kienzle-SumUp
Exec="$escaped/kienzle-sumup" --url %u
Terminal=false
NoDisplay=true
MimeType=x-scheme-handler/kienzle-sumup;
EOF
chmod 644 "$applications/kienzle-sumup.desktop"
if command -v update-desktop-database >/dev/null; then update-desktop-database "$applications"; fi
xdg-mime default kienzle-sumup.desktop x-scheme-handler/kienzle-sumup
echo 'Starter eingerichtet. Kienzledoku bleibt separat registriert.'
echo "Für den Browser bitte das Zertifikat $target/server.crt als vertrauenswürdig importieren."
echo 'Der Starter prüft das exakte Serverzertifikat bereits über seinen Fingerabdruck.'
