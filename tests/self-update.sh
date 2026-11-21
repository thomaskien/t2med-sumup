#!/usr/bin/env bash
# Aktualisierungsdienst: Erfolg, Rücksicherung bei Installerfehler, kaputter Download, ungültige Quelle.
# Ohne systemd (KS_SERVICES leer); braucht Schreibrecht auf /run (in CI mit sudo).
set -euo pipefail
here=$(cd -- "$(dirname -- "$0")/.." && pwd)
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
fail() { echo "FEHLER: $*"; cat "$T/state/update.log" 2>/dev/null; exit 1; }
mkdir -p "$T/pkg/x/scripts" "$T/app" "$T/data" "$T/state"
echo 1.4.1-rechnung.99 > "$T/pkg/x/VERSION"
cat > "$T/pkg/x/scripts/install-server.sh" <<EOF
cp VERSION "$T/app/VERSION"
if [ -f "$T/fail" ]; then echo kaputt > "$T/app/broken"; echo neu > "$T/data/payments.sqlite"; exit 3; fi
EOF
(cd "$T/pkg" && tar -czf "$T/src.tgz" x)
printf 'repo=hau-si/kienzle-suite-beta\nbranch=rechnungseingang\n' > "$T/conf"
export KS_UPDATE_CONF="$T/conf" KS_UPDATE_STATE="$T/state" KS_UPDATE_REQUEST="$T/data/update-request" KS_APP_DIR="$T/app" \
  KS_DATA_DIR="$T/data" KS_SERVICES= KS_OWNER="$(id -gn)" KS_UPDATE_URL="file://$T/src.tgz"
status() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$T/state/status.json" "$1"; }

echo 1.4.1-rechnung.12 > "$T/app/VERSION"; echo alt > "$T/data/payments.sqlite"; touch "$T/data/update-request"
bash "$here/scripts/self-update.sh" || fail 'Aktualisierung nicht erfolgreich'
[ "$(status state)" = done ] && [ "$(cat "$T/app/VERSION")" = 1.4.1-rechnung.99 ] && [ ! -e "$T/data/update-request" ] || fail 'Erfolgsfall falsch'
[ "$(stat -c %a "$T/state/update.log")" = 640 ] || fail 'Protokoll nicht 640'

echo 1.4.1-rechnung.12 > "$T/app/VERSION"; touch "$T/fail"
if bash "$here/scripts/self-update.sh"; then fail 'Fehlschlag nicht gemeldet'; fi
[ "$(status state)" = failed ] && [ "$(cat "$T/app/VERSION")" = 1.4.1-rechnung.12 ] && [ ! -e "$T/app/broken" ] && [ "$(cat "$T/data/payments.sqlite")" = alt ] || fail 'Rücksicherung falsch'

echo murks > "$T/src.tgz"
if bash "$here/scripts/self-update.sh"; then fail 'Kaputter Download akzeptiert'; fi
[ "$(status message)" = 'Download beschädigt.' ] || fail 'Meldung bei kaputtem Download'

printf 'repo=x;rm -rf /\nbranch=main\n' > "$T/conf"
if bash "$here/scripts/self-update.sh"; then fail 'Ungültige Quelle akzeptiert'; fi
status message | grep -q 'Quelle' || fail 'Meldung bei ungültiger Quelle'
printf 'repo=hau-si/kienzle-suite-beta\nbranch=rechnungseingang\n' > "$T/conf"
echo 1.4.1-rechnung.12 > "$T/app/VERSION"; rm -f "$T/fail"; printf stable > "$T/data/update-request"
bash "$here/scripts/self-update.sh" >/dev/null 2>&1 || true; grep -q 'Quelle: thomaskien/t2med-sumup (main)' "$T/state/update.log" || fail 'Kanal stable nutzt falsche Quelle'
printf beta > "$T/data/update-request"
bash "$here/scripts/self-update.sh" >/dev/null 2>&1 || true; grep -q 'Quelle: hau-si/kienzle-suite-beta (rechnungseingang)' "$T/state/update.log" || fail 'Kanal beta nutzt falsche Quelle'
printf '1778000000' > "$T/data/update-request"
bash "$here/scripts/self-update.sh" >/dev/null 2>&1 || true; grep -q 'Quelle: hau-si/kienzle-suite-beta (rechnungseingang)' "$T/state/update.log" || fail 'Ohne Kanal nicht die Quelle aus update.conf'
echo 'Aktualisierungsdienst ok'
