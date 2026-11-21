#!/usr/bin/env bash
# Aktualisierung aus dem Webinterface. Läuft als root über kienzle-sumup-update.service, ausgelöst
# durch die Datei /var/lib/kienzle-sumup/update-request (schreibt die Einstellungsseite). Der Inhalt
# der Anfrage wird nur als Kanalname gelesen („stable“ oder „beta“, fest hinterlegte Quellen unten); alles
# andere heißt: Quelle aus der root-eigenen /etc/kienzle-sumup/update.conf. Vor der Installation werden Programm und Datenbank
# gesichert; schlägt der Installer fehl, wird der vorherige Stand wiederhergestellt.
set -uo pipefail

# Von einer Kopie laufen: Der Installer überschreibt dieses Skript während der Aktualisierung.
if [ -z "${KS_UPDATE_COPY:-}" ]; then
  copy=$(mktemp /run/kienzle-sumup-update.XXXXXX) && cp -- "$0" "$copy" && KS_UPDATE_COPY="$copy" exec bash "$copy" "$@"
  echo 'Kopie des Aktualisierungsskripts fehlgeschlagen.'; exit 1
fi
trap 'rm -f -- "$KS_UPDATE_COPY"' EXIT

conf=${KS_UPDATE_CONF:-/etc/kienzle-sumup/update.conf}
state=${KS_UPDATE_STATE:-/var/lib/kienzle-sumup-update}
request=${KS_UPDATE_REQUEST:-/var/lib/kienzle-sumup/update-request}
app=${KS_APP_DIR:-/opt/kienzle-sumup}
data=${KS_DATA_DIR:-/var/lib/kienzle-sumup}
services=${KS_SERVICES-kienzle-sumup-apache.service kienzle-sumup-php.service}
owner=${KS_OWNER:-kienzle-sumup}

channel=$(head -c 12 -- "$request" 2>/dev/null | tr -dc 'a-z')
rm -f -- "$request"   # Anfrage verbrauchen (auch ein Symlink wird nur selbst entfernt)
umask 022
install -d -m 755 "$state"
log="$state/update.log"; status="$state/status.json"
: > "$log"; chmod 640 "$log"; chgrp "$owner" "$log" 2>/dev/null || true
exec >>"$log" 2>&1

from=$(tr -dc '0-9A-Za-z.+-' < "$app/VERSION" 2>/dev/null | head -c 40)
to=''; started=$(date +%s)
write_status() {   # Zustand, feste Meldung
  local tmp; tmp=$(mktemp "$state/.status.XXXXXX")
  printf '{"state":"%s","message":"%s","from":"%s","to":"%s","started":%s,"finished":%s}\n' \
    "$1" "$2" "$from" "$to" "$started" "$( [ "$1" = running ] && echo null || date +%s )" > "$tmp"
  chmod 644 "$tmp"; mv -f -- "$tmp" "$status"
}
services_do() { [ -n "$services" ] && systemctl "$1" $services; }
write_status running 'Aktualisierung läuft.'
echo "Kienzle-SumUp-Aktualisierung gestartet: $(date '+%d.%m.%Y %H:%M:%S'), installiert: $from"

repo=$(sed -nE 's/^repo=([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)$/\1/p' "$conf" 2>/dev/null | head -1)
branch=$(sed -nE 's/^branch=([A-Za-z0-9_.\/-]+)$/\1/p' "$conf" 2>/dev/null | head -1)
case "$channel" in   # feste Quellen der Kanäle (müssen zu Updater::STABLE / Updater::BETA passen)
  stable) repo=thomaskien/t2med-sumup; branch=main ;;
  beta)   repo=hau-si/kienzle-suite-beta; branch=rechnungseingang ;;
esac
if [ -z "$repo" ] || [ -z "$branch" ]; then echo "Quelle fehlt in $conf."; write_status failed 'Quelle der Aktualisierung fehlt (update.conf).'; exit 1; fi
url=${KS_UPDATE_URL:-https://codeload.github.com/$repo/tar.gz/refs/heads/$branch}
proto='=https'; [ -n "${KS_UPDATE_URL:-}" ] && proto='=https,file'
echo "Quelle: $repo ($branch)"

work=$(mktemp -d "$state/work.XXXXXX"); trap 'rm -f -- "$KS_UPDATE_COPY"; rm -rf -- "$work"' EXIT
if ! curl -fsSL --proto "$proto" --proto-redir =https --max-time 300 --max-filesize 100000000 "$url" -o "$work/source.tar.gz"; then
  echo 'Download fehlgeschlagen.'; write_status failed 'Download von GitHub fehlgeschlagen. Internetverbindung prüfen.'; exit 1
fi
mkdir "$work/src"
if ! tar -xzf "$work/source.tar.gz" -C "$work/src" --strip-components=1 --no-same-owner; then
  write_status failed 'Download beschädigt.'; exit 1
fi
to=$(tr -dc '0-9A-Za-z.+-' < "$work/src/VERSION" 2>/dev/null | head -c 40)
if ! [[ $to =~ ^[0-9]+(\.[0-9]+)+([.+-][0-9A-Za-z.-]+)?$ ]] || [ ! -f "$work/src/scripts/install-server.sh" ]; then
  to=''; write_status failed 'Download enthält keine gültige Kienzle-SumUp-Version.'; exit 1
fi
echo "Neue Version: $to"

# Sicherung: Programm und Datenbank (bei gestoppten Diensten, damit die Datenbank konsistent ist).
backup="$state/previous"
rm -rf -- "$backup"; mkdir -p "$backup/db"
cp -a -- "$app" "$backup/app"
services_do stop || true
for f in "$data"/payments.sqlite "$data"/payments.sqlite-wal "$data"/payments.sqlite-shm; do [ -f "$f" ] && cp -a -- "$f" "$backup/db/"; done
echo 'Sicherung erstellt. Starte Installer …'

installer=${KS_INSTALLER:-scripts/install-server.sh}
if (cd "$work/src" && bash "$installer" < /dev/null); then
  echo "Aktualisierung abgeschlossen: $from → $to"
  write_status done "Aktualisiert auf $to."
  exit 0
fi

echo 'Installer fehlgeschlagen – stelle den vorherigen Stand wieder her.'
services_do stop || true
rm -rf -- "$app"; cp -a -- "$backup/app" "$app"
rm -f -- "$data"/payments.sqlite-wal "$data"/payments.sqlite-shm
for f in "$backup"/db/*; do [ -f "$f" ] && cp -a -- "$f" "$data/"; done
[ -n "$services" ] && systemctl daemon-reload
services_do start || true
write_status failed "Aktualisierung auf $to fehlgeschlagen; vorheriger Stand $from wiederhergestellt. Details im Protokoll."
exit 1
