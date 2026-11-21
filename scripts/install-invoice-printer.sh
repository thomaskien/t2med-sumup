#!/usr/bin/env bash
# Richtet auf dem Druckserver (z. B. kienzlebox) den virtuellen Drucker „SumUp-Rechnung“ ein.
# Aufruf: sudo bash scripts/install-invoice-printer.sh [kienzle-sumup-drucker.json | Ordner]
# Ohne Angabe: Daten vom Kienzle-SumUp auf diesem Rechner.
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
source_dir=$PWD
queue=SumUp-Rechnung
if [ "$EUID" -ne 0 ]; then echo 'Bitte mit sudo ausführen: sudo bash scripts/install-invoice-printer.sh'; exit 1; fi
if [ ! -f /etc/debian_version ]; then echo 'Dieser Installer unterstützt Debian, Ubuntu und Raspberry Pi OS.'; exit 1; fi

if [ "${1:-}" = "--uninstall" ]; then
  lpadmin -x "$queue" 2>/dev/null || true
  rm -f /usr/lib/cups/backend/kienzle-sumup /usr/share/ppd/kienzle-sumup/sumup-rechnung.ppd
  rm -rf /etc/kienzle-sumup-printer
  echo "Drucker $queue entfernt."
  exit 0
fi

# Quelle der Einrichtungsdaten:
#   ohne Angabe: Kienzle-SumUp auf diesem Rechner (Einstellungen → T2med-Rechnungen übernehmen)
#   Datei:       kienzle-sumup-drucker.json aus der Einstellungsseite (entfernter Druckserver)
#   Ordner:      invoice-printer.json + server.crt (ältere Installationen)
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
source_arg=${1:-}
if [ -z "$source_arg" ] && [ -f /opt/kienzle-sumup/scripts/printer-setup.php ]; then
  runuser -u kienzle-sumup -- php /opt/kienzle-sumup/scripts/printer-setup.php > "$work/invoice-printer.json" || exit 1
  cp /etc/kienzle-sumup/server.crt "$work/server.crt"
elif [ -n "$source_arg" ] && [ -f "$source_arg" ]; then
  python3 - "$source_arg" "$work" <<'PY'
import json, sys, pathlib
data = json.load(open(sys.argv[1], encoding="utf-8")); out = pathlib.Path(sys.argv[2])
if not data.get("certificate", "").startswith("-----BEGIN CERTIFICATE-----"): sys.exit("Einrichtungsdatei ohne Serverzertifikat.")
(out / "invoice-printer.json").write_text(json.dumps({"server_url": data["server_url"], "inbox_key": data["inbox_key"]}))
(out / "server.crt").write_text(data["certificate"])
PY
elif [ -d "${source_arg:-/root/kienzle-sumup-printer}" ]; then
  cp "${source_arg:-/root/kienzle-sumup-printer}/invoice-printer.json" "${source_arg:-/root/kienzle-sumup-printer}/server.crt" "$work/"
else
  echo 'Keine Einrichtungsdaten gefunden.'
  echo 'Auf dem Kienzle-SumUp-Server: Einstellungen → „T2med-Rechnungen übernehmen“ aktivieren und dieses Skript ohne Angabe starten.'
  echo 'Auf einem anderen Druckserver: Einrichtungsdatei aus der Einstellungsseite herunterladen und als Pfad angeben.'
  exit 1
fi
setup=$work
if grep -q 'PRIVATE KEY' "$setup/server.crt"; then echo 'server.crt darf keinen privaten Schlüssel enthalten.'; exit 1; fi

DEBIAN_FRONTEND=noninteractive apt-get install -y cups python3 ghostscript

# Eine fremde Warteschlange gleichen Namens nicht überschreiben.
if lpstat -v "$queue" >/dev/null 2>&1 && ! lpstat -v "$queue" | grep -q 'kienzle-sumup:/'; then
  echo "Eine fremde Warteschlange $queue existiert bereits; keine Änderung."; exit 1
fi

umask 077
install -d -m 755 -o root -g root /etc/kienzle-sumup-printer
install -m 600 -o root -g root "$setup/invoice-printer.json" /etc/kienzle-sumup-printer/printer.json
install -m 644 -o root -g root "$setup/server.crt" /etc/kienzle-sumup-printer/server.crt
# 0700 root: CUPS startet das Backend als root; es liest Schlüssel/Spooldatei und arbeitet dann als lp.
install -m 700 -o root -g root "$source_dir/scripts/invoice-printer/kienzle-sumup-backend.py" /usr/lib/cups/backend/kienzle-sumup
install -d -m 755 /usr/share/ppd/kienzle-sumup
install -m 644 "$source_dir/scripts/invoice-printer/sumup-rechnung.ppd" /usr/share/ppd/kienzle-sumup/sumup-rechnung.ppd

echo 'Verbindung zum kienzle-sumup-Server prüfen …'
/usr/lib/cups/backend/kienzle-sumup --check

lpadmin -p "$queue" -E -v kienzle-sumup:/ -P /usr/share/ppd/kienzle-sumup/sumup-rechnung.ppd \
  -D "$queue" -L 'T2med-Rechnung an SumUp-Terminal' \
  -o printer-is-shared=true -o printer-error-policy=abort-job -o job-sheets-default=none,none
cupsenable "$queue"; cupsaccept "$queue"
if ! cupsctl | grep -q '^_share_printers=1'; then
  echo 'Hinweis: CUPS gibt Drucker noch nicht im Netz frei. Zum Freigeben: sudo cupsctl --share-printers'
fi
host=$(hostname)
echo "Fertig. Warteschlange $queue ist eingerichtet."
echo "Windows: IPP-Drucker http://$host:631/printers/$queue mit „Microsoft IPP Class Driver“ hinzufügen."
echo "Mac: „$queue @ $host“ hinzufügen, Treiber „Allgemeiner PDF-Drucker“ bzw. PDF-PPD wie beim Terminzettel."
echo 'In T2med die Rechnung auf diesen Drucker drucken und danach Kienzle-SumUp beim Patienten öffnen.'
