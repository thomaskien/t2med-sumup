#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
source_dir=$PWD
if [ "$EUID" -ne 0 ]; then echo 'Bitte mit sudo ausführen: sudo bash scripts/install-server.sh'; exit 1; fi
if [ ! -f /etc/debian_version ]; then echo 'Dieser Installer unterstützt Debian, Ubuntu und Raspberry Pi OS.'; exit 1; fi
if ! command -v systemctl >/dev/null; then echo 'systemd wird benötigt.'; exit 1; fi
umask 077
# Läuft gerade ein anderer Paketvorgang (z. B. automatische Updates), bis zu 10 Minuten auf die Paketsperre warten statt abzubrechen.
apt-get() { command apt-get -o DPkg::Lock::Timeout=600 "$@"; }
export -f apt-get
# Optionen: --fragen (Einstellungen wie früher im Terminal abfragen), --admin-reset (neuer Einrichtungslink),
#           --ohne-terminzettel (virtuellen Drucker „Terminzettel“ nicht einrichten).
ask_flag=""; admin_reset=""; with_terminzettel=1
for arg in "$@"; do
  case "$arg" in
    --fragen) ask_flag="--fragen" ;;
    --admin-reset) admin_reset="--reset" ;;
    --ohne-terminzettel) with_terminzettel=0; install -d -m 750 /etc/kienzle-sumup; touch /etc/kienzle-sumup/ohne-terminzettel ;;
    --mit-terminzettel) rm -f /etc/kienzle-sumup/ohne-terminzettel ;;
    *) echo "Unbekannte Option: $arg (erlaubt: --fragen, --admin-reset, --ohne-terminzettel, --mit-terminzettel)"; exit 2 ;;
  esac
done
# Einmal gewählt, gilt „ohne Terminzettel“ auch für spätere Aktualisierungen aus dem Webinterface.
if [ -f /etc/kienzle-sumup/ohne-terminzettel ]; then with_terminzettel=0; fi
apt-get update
# Parallelbetrieb mit einem anderen Webserver (z. B. nginx auf Port 80): Der Paket-Apache wird nur als Programmdatei gebraucht,
# Kienzle-SumUp startet eine eigene Instanz auf dem eigenen Port. Belegt schon etwas Port 80, darf der Paket-Apache beim
# Installieren nicht starten (sonst bricht die Installation ab) und wird danach abgeschaltet.
stock_apache_off=0
if ss -ltn 2>/dev/null | grep -qE ':80[[:space:]]' || command -v nginx >/dev/null 2>&1; then
  stock_apache_off=1
  trap 'rm -f /usr/sbin/policy-rc.d' EXIT
  printf '#!/bin/sh\n[ "$1" = apache2 ] && exit 101\nexit 0\n' > /usr/sbin/policy-rc.d; chmod 755 /usr/sbin/policy-rc.d
fi
DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 php-cli php-fpm php-curl php-sqlite3 php-mbstring openssl ca-certificates python3 curl librsvg2-bin qpdf fonts-dejavu-core libphp-phpmailer poppler-utils tesseract-ocr tesseract-ocr-deu php-gd smbclient cups-client cups ghostscript nftables iproute2
if [ "$stock_apache_off" = 1 ]; then
  rm -f /usr/sbin/policy-rc.d
  systemctl disable --now apache2.service >/dev/null 2>&1 || true
fi
# AppArmor (Ubuntu 26.04): gs und qpdf sind dort eingeschränkt. Rechte für den Datenordner dieser Anwendung als lokale
# Ergänzung eintragen (bleibt bei Updates erhalten, mehrfaches Ausführen ändert nichts). Fehler sind unkritisch.
if command -v apparmor_parser >/dev/null 2>&1; then
  for prog in gs qpdf; do
    if [ -f "/etc/apparmor.d/$prog" ] && [ -d /etc/apparmor.d/local ]; then
      for rule in '/var/lib/kienzle-sumup/** rwk,' '/tmp/** rwk,'; do
        grep -qxF "$rule" "/etc/apparmor.d/local/$prog" 2>/dev/null || echo "$rule" >> "/etc/apparmor.d/local/$prog"
      done
      apparmor_parser -r "/etc/apparmor.d/$prog" 2>/dev/null || true
    fi
  done
fi
# Automatische Sicherheitsupdates (nur Sicherheitsquelle der Distribution, kein automatischer Neustart).
# Vorhandene Einstellungen bleiben unverändert; Kernel-Updates und Neustarts macht die Praxis selbst.
DEBIAN_FRONTEND=noninteractive apt-get install -y unattended-upgrades
if ! grep -rqs 'APT::Periodic::Unattended-Upgrade' /etc/apt/apt.conf.d/; then
  printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades
fi
php -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("sodium") && extension_loaded("pdo_sqlite") && extension_loaded("curl") ? 0 : 1);' || { echo 'PHP >= 8.1 mit sodium, sqlite und curl benötigt.'; exit 1; }
if ! id kienzle-sumup >/dev/null 2>&1; then useradd --system --home-dir /var/lib/kienzle-sumup --shell /usr/sbin/nologin kienzle-sumup; fi
install -d -m 750 -o root -g kienzle-sumup /etc/kienzle-sumup
install -d -m 700 -o kienzle-sumup -g kienzle-sumup /var/lib/kienzle-sumup
# Drucker im Browser einrichten: CUPS-Verwaltung über die Gruppe lpadmin (nur lokaler Druckdienst).
usermod -aG lpadmin kienzle-sumup
systemctl enable --now cups.service >/dev/null 2>&1 || true
# Drucker „SumUp-Rechnung“: Backend und PPD einmalig als root ablegen; anlegen/entfernen danach im Browser
# (Einstellungen → T2med-Rechnungen übernehmen). Verbindungsdaten schreibt die Anwendung nach …/web.
install -d -m 755 -o root -g root /etc/kienzle-sumup-printer
install -d -m 700 -o kienzle-sumup -g kienzle-sumup /etc/kienzle-sumup-printer/web
install -m 700 -o root -g root "$source_dir/scripts/invoice-printer/kienzle-sumup-backend.py" /usr/lib/cups/backend/kienzle-sumup
install -d -m 755 /usr/share/ppd/kienzle-sumup
install -m 644 "$source_dir/scripts/invoice-printer/sumup-rechnung.ppd" /usr/share/ppd/kienzle-sumup/sumup-rechnung.ppd
# T2med-Terminzettel (Ordner terminzettel/): virtueller Drucker „Terminzettel“ → Bon auf dem Bondrucker.
# Ohne Rückfragen; Layout und Zielbondrucker kommen aus dem Webinterface. Bei Fehlern bleibt
# Kienzle-SumUp lauffähig, der Terminzettel-Installer nimmt seine Änderungen selbst zurück.
terminzettel_ok=0
if [ "$with_terminzettel" = 1 ]; then
  DEBIAN_FRONTEND=noninteractive apt-get install -y python3-tomli python3-cups python3-qrcode python3-pil tzdata samba avahi-daemon ghostscript libgxps-utils tesseract-ocr tesseract-ocr-deu fonts-dejavu-core
  if ! cupsctl 2>/dev/null | grep -q '^_share_printers=1'; then
    # Arbeitsplätze drucken per IPP auf „Terminzettel“; nur als freigegeben markierte Drucker sind sichtbar.
    cupsctl --share-printers && sleep 2
  fi
  if /usr/bin/python3 -B "$source_dir/terminzettel/install.py" < /dev/null; then
    terminzettel_ok=1
    usermod -aG terminzettel kienzle-sumup
    install -d -m 2750 -o kienzle-sumup -g terminzettel /etc/terminzettel/web
  else
    echo 'Warnung: Terminzettel konnte nicht eingerichtet werden (siehe Meldung oben). Kienzle-SumUp wird trotzdem installiert.'
  fi
fi
install -d -m 750 -o kienzle-sumup -g kienzle-sumup /var/log/kienzle-sumup
python3 scripts/configure.py $ask_flag
chown root:kienzle-sumup /etc/kienzle-sumup/kienzle-sumup.toml
chmod 640 /etc/kienzle-sumup/kienzle-sumup.toml
if [ -f /etc/kienzle-sumup/t2med-ca.pem ]; then chown root:kienzle-sumup /etc/kienzle-sumup/t2med-ca.pem; fi
chmod 600 /etc/kienzle-sumup/server.key
chmod 644 /etc/kienzle-sumup/server.crt
# Ab hier nur die eigenen Dienste stoppen; vorhandene Apache-Konfigurationen nicht ändern.
systemctl stop kienzle-sumup-apache.service kienzle-sumup-php.service 2>/dev/null || true
touch /var/log/kienzle-sumup/php.log
chown kienzle-sumup:kienzle-sumup /var/log/kienzle-sumup/php.log
chmod 640 /var/log/kienzle-sumup/php.log

install -d -m 755 /opt/kienzle-sumup
for item in src public scripts config client terminzettel VERSION README.md; do
  if [ -e "$source_dir/$item" ] && [ "$source_dir" != /opt/kienzle-sumup ]; then cp -R "$source_dir/$item" /opt/kienzle-sumup/; fi
done
chown -R root:root /opt/kienzle-sumup
find /opt/kienzle-sumup -type d -exec chmod 755 {} +
find /opt/kienzle-sumup -type f -exec chmod 644 {} +
port=$(php -r 'require $argv[1]; echo KienzleSumup\Config::load("/etc/kienzle-sumup/kienzle-sumup.toml")->get("app","port");' "$source_dir/src/bootstrap.php")
php_version=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
fpm="/usr/sbin/php-fpm$php_version"
test -x "$fpm" || { echo "PHP-FPM $php_version fehlt."; exit 1; }
cat > /etc/kienzle-sumup/php-fpm.conf <<EOF
[global]
pid = /run/kienzle-sumup-php/php.pid
error_log = /var/log/kienzle-sumup/fpm.log
daemonize = no
[kienzle-sumup]
user = kienzle-sumup
group = kienzle-sumup
listen = /run/kienzle-sumup-php/php.sock
listen.owner = kienzle-sumup
listen.group = kienzle-sumup
listen.mode = 0600
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 10s
pm.max_requests = 500
request_terminate_timeout = 90s
clear_env = yes
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_flag[expose_php] = off
php_admin_flag[allow_url_fopen] = off
php_admin_flag[allow_url_include] = off
php_admin_flag[session.use_strict_mode] = on
php_admin_value[disable_functions] = exec,shell_exec,system,passthru,popen,pcntl_exec,pcntl_fork,pcntl_signal,dl,show_source,highlight_file,symlink,link,proc_nice,posix_kill,posix_setuid,posix_setgid,putenv,mail
php_admin_value[memory_limit] = 64M
php_admin_value[max_execution_time] = 60
php_admin_value[upload_max_filesize] = 6M
php_admin_value[max_input_vars] = 500
php_admin_value[post_max_size] = 8M
php_admin_value[error_log] = /var/log/kienzle-sumup/php.log
EOF
cat > /etc/kienzle-sumup/apache.conf <<EOF
ServerRoot /etc/kienzle-sumup
ServerName localhost
Listen $port https
PidFile /run/kienzle-sumup-apache/apache.pid
DefaultRuntimeDir /run/kienzle-sumup-apache
LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule authz_host_module /usr/lib/apache2/modules/mod_authz_host.so
LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so
LoadModule mime_module /usr/lib/apache2/modules/mod_mime.so
LoadModule ssl_module /usr/lib/apache2/modules/mod_ssl.so
LoadModule socache_shmcb_module /usr/lib/apache2/modules/mod_socache_shmcb.so
LoadModule proxy_module /usr/lib/apache2/modules/mod_proxy.so
LoadModule proxy_fcgi_module /usr/lib/apache2/modules/mod_proxy_fcgi.so
LoadModule setenvif_module /usr/lib/apache2/modules/mod_setenvif.so
LoadModule headers_module /usr/lib/apache2/modules/mod_headers.so
LoadModule reqtimeout_module /usr/lib/apache2/modules/mod_reqtimeout.so
User kienzle-sumup
Group kienzle-sumup
ServerTokens Prod
ServerSignature Off
TraceEnable Off
Timeout 90
ProxyTimeout 80
KeepAlive On
MaxKeepAliveRequests 50
KeepAliveTimeout 3
# Schutz vor langsamen Verbindungen (Slowloris) und überlangen Anfragen
RequestReadTimeout handshake=10 header=20-40,MinRate=500 body=20,MinRate=500
LimitRequestFields 50
LimitRequestFieldSize 8190
LimitRequestLine 4096
StartServers 1
ServerLimit 2
ThreadsPerChild 16
MaxRequestWorkers 32
TypesConfig /etc/mime.types
ErrorLog /var/log/kienzle-sumup/apache-error.log
LogLevel warn
# Kein AccessLog: Starttickets, Patienten-/Vorgangskennungen werden nicht protokolliert.
SSLSessionCache shmcb:/run/kienzle-sumup-apache/sslcache(512000)
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<VirtualHost *:$port>
    SSLEngine on
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCipherSuite ECDHE+AESGCM:ECDHE+CHACHA20:!aNULL:!MD5
    SSLHonorCipherOrder off
    SSLSessionTickets off
    SSLCompression off
    SSLCertificateFile /etc/kienzle-sumup/server.crt
    SSLCertificateKeyFile /etc/kienzle-sumup/server.key
    DocumentRoot /opt/kienzle-sumup/public
    DirectoryIndex index.php
    LimitRequestBody 32768
    Header always set X-Content-Type-Options nosniff
    Header always set Referrer-Policy no-referrer
    Header always set X-Frame-Options DENY
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()"
    Header always set Cross-Origin-Opener-Policy same-origin
    Header always set Cross-Origin-Resource-Policy same-origin
    # Bewusst kein HSTS: Das Zertifikat ist selbst ausgestellt; HSTS würde bei einem Zertifikatswechsel die Seite komplett sperren.
    # Versteckte Dateien (.git, .env …) und Sicherungen nie ausliefern
    <FilesMatch "^\.|~$|\.(bak|orig|sqlite|log|toml|sh|md|sql)$">
        Require all denied
    </FilesMatch>
    <Directory /opt/kienzle-sumup/public>
        Options -Indexes -ExecCGI -Includes -FollowSymLinks
        AllowOverride None
        Require all granted
        <LimitExcept GET POST HEAD>
            Require all denied
        </LimitExcept>
        <FilesMatch "\\.php$">
            SetHandler "proxy:unix:/run/kienzle-sumup-php/php.sock|fcgi://localhost"
        </FilesMatch>
    </Directory>
    # Rechnungseingang vom Druckserver: PDF bis 5 MB, sonst gilt das kleine Limit oben.
    <Location "/invoice-inbox.php">
        LimitRequestBody 5300000
    </Location>
    SetEnvIfNoCase Authorization "^(.*)$" HTTP_AUTHORIZATION=\$1
</VirtualHost>
EOF
cat > /etc/systemd/system/kienzle-sumup-php.service <<EOF
[Unit]
Description=Kienzle-SumUp PHP-FPM
After=network.target
[Service]
Type=simple
ExecStart=$fpm --nodaemonize --fpm-config /etc/kienzle-sumup/php-fpm.conf
ExecReload=/bin/kill -USR2 \$MAINPID
RuntimeDirectory=kienzle-sumup-php
RuntimeDirectoryMode=0755
UMask=0077
Restart=on-failure
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
NoNewPrivileges=true
PrivateDevices=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectKernelLogs=true
ProtectControlGroups=true
ProtectClock=true
ProtectHostname=true
RestrictSUIDSGID=true
RestrictRealtime=true
RestrictNamespaces=true
LockPersonality=true
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6 AF_NETLINK
ReadWritePaths=/var/lib/kienzle-sumup /var/log/kienzle-sumup /run/kienzle-sumup-php -/etc/terminzettel/web /etc/kienzle-sumup-printer/web
[Install]
WantedBy=multi-user.target
EOF
cat > /etc/systemd/system/kienzle-sumup-apache.service <<'EOF'
[Unit]
Description=Kienzle-SumUp Apache HTTPS
After=network.target kienzle-sumup-php.service
Requires=kienzle-sumup-php.service
[Service]
Type=simple
ExecStart=/usr/sbin/apache2 -f /etc/kienzle-sumup/apache.conf -DFOREGROUND
ExecReload=/bin/kill -USR1 $MAINPID
RuntimeDirectory=kienzle-sumup-apache
RuntimeDirectoryMode=0755
UMask=0077
Restart=on-failure
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
NoNewPrivileges=true
PrivateDevices=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectKernelLogs=true
ProtectControlGroups=true
ProtectClock=true
ProtectHostname=true
RestrictSUIDSGID=true
RestrictRealtime=true
RestrictNamespaces=true
LockPersonality=true
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
ReadWritePaths=/var/log/kienzle-sumup /run/kienzle-sumup-apache
[Install]
WantedBy=multi-user.target
EOF
chmod 644 /etc/systemd/system/kienzle-sumup-{apache,php}.service
chmod 640 /etc/kienzle-sumup/{apache.conf,php-fpm.conf}
runuser -u kienzle-sumup -- php /opt/kienzle-sumup/scripts/init-db.php
install -d -m 755 /run/kienzle-sumup-apache /run/kienzle-sumup-php
/usr/sbin/apache2 -f /etc/kienzle-sumup/apache.conf -t
"$fpm" --fpm-config /etc/kienzle-sumup/php-fpm.conf -t
for file in install-linux.sh install-macos.command install-windows.ps1 install-windows.cmd; do
  install -m 700 "client/$file" "/root/kienzle-sumup-clients/$file"
done
if [ -d dist ]; then cp dist/kienzle-sumup-* dist/SHA256SUMS /root/kienzle-sumup-clients/; fi
# Starterprogramme für die Download-Pakete der Einstellungsseite (öffentliche Release-Dateien,
# per SHA256SUMS geprüft). Ohne Internet laden die Arbeitsplätze sie bei der Einrichtung selbst.
starter_version=v1.4.1
install -d -m 755 /opt/kienzle-sumup/client-dist
if [ -d dist ] && [ -f dist/SHA256SUMS ]; then
  cp dist/kienzle-sumup-* dist/SHA256SUMS /opt/kienzle-sumup/client-dist/
elif ! (cd /opt/kienzle-sumup/client-dist && test -f SHA256SUMS && sha256sum --quiet --ignore-missing -c SHA256SUMS >/dev/null 2>&1 && grep -q "$starter_version" .version 2>/dev/null); then
  starter_tmp=$(mktemp -d)
  if curl -fsSL --proto '=https' --tlsv1.2 --max-time 60 "https://github.com/thomaskien/t2med-sumup/releases/download/$starter_version/SHA256SUMS" -o "$starter_tmp/SHA256SUMS"; then
    for asset in kienzle-sumup-windows-amd64.exe kienzle-sumup-windows-arm64.exe kienzle-sumup-darwin-amd64 kienzle-sumup-darwin-arm64 kienzle-sumup-linux-amd64 kienzle-sumup-linux-arm64 kienzle-sumup-linux-arm; do
      curl -fsSL --proto '=https' --tlsv1.2 --max-time 120 "https://github.com/thomaskien/t2med-sumup/releases/download/$starter_version/$asset" -o "$starter_tmp/$asset" || rm -f "$starter_tmp/$asset"
    done
    if (cd "$starter_tmp" && sha256sum --quiet --ignore-missing -c SHA256SUMS); then
      cp "$starter_tmp"/* /opt/kienzle-sumup/client-dist/ && echo "$starter_version" > /opt/kienzle-sumup/client-dist/.version
    else
      echo 'Warnung: Prüfsumme der Starterprogramme stimmt nicht; Pakete ohne Starterprogramme.'
    fi
  else
    echo 'Hinweis: Starterprogramme nicht geladen (kein Internet?). Die Arbeitsplätze laden sie bei der Einrichtung selbst.'
  fi
  rm -rf "$starter_tmp"
fi
chmod 644 /opt/kienzle-sumup/client-dist/* 2>/dev/null || true
# Stündliche Nachholung der Rechnungsablage (ohne aktivierte Ablage ohne Wirkung).
if true; then
  cat > /etc/systemd/system/kienzle-sumup-export.service <<'UNIT'
[Unit]
Description=Kienzle-SumUp Rechnungsablage nachholen
After=network-online.target
[Service]
Type=oneshot
User=kienzle-sumup
Group=kienzle-sumup
ExecStart=/usr/bin/php /opt/kienzle-sumup/scripts/export-invoices.php --quiet
UMask=0077
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/var/lib/kienzle-sumup /var/log/kienzle-sumup
UNIT
  cat > /etc/systemd/system/kienzle-sumup-export.timer <<'UNIT'
[Unit]
Description=Kienzle-SumUp Rechnungsablage stündlich nachholen
[Timer]
OnBootSec=10min
OnUnitActiveSec=1h
RandomizedDelaySec=5min
[Install]
WantedBy=timers.target
UNIT
  chmod 644 /etc/systemd/system/kienzle-sumup-export.service /etc/systemd/system/kienzle-sumup-export.timer
  export_timer=1
fi
# Aktualisierung aus dem Webinterface: root-Dienst, ausgelöst durch eine Anfragedatei der Anwendung.
install -m 755 -o root -g root scripts/self-update.sh /usr/local/sbin/kienzle-sumup-update
install -d -m 755 -o root -g root /var/lib/kienzle-sumup-update
if [ ! -f /etc/kienzle-sumup/update.conf ]; then
  # Quelle aus dem Git-Ordner übernehmen, aus dem installiert wird (sonst das Originalprojekt).
  # Standard: Hauptzweig des Projekts („Stabil“). Die Beta-Version dieses Forks wählt man in den Einstellungen.
  update_repo=thomaskien/t2med-sumup; update_branch=main
  printf '# Quelle für „Aktualisieren“ in den Einstellungen (GitHub)\nrepo=%s\nbranch=%s\n' "$update_repo" "$update_branch" > /etc/kienzle-sumup/update.conf
fi
chown root:kienzle-sumup /etc/kienzle-sumup/update.conf; chmod 640 /etc/kienzle-sumup/update.conf
cat > /etc/systemd/system/kienzle-sumup-update.path <<'UNIT'
[Unit]
Description=Kienzle-SumUp Aktualisierung auf Anforderung aus den Einstellungen
[Path]
PathExists=/var/lib/kienzle-sumup/update-request
Unit=kienzle-sumup-update.service
[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/kienzle-sumup-update.service <<'UNIT'
[Unit]
Description=Kienzle-SumUp aktualisieren (GitHub)
After=network-online.target
Wants=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/kienzle-sumup-update
TimeoutStartSec=1800
UNIT
chmod 644 /etc/systemd/system/kienzle-sumup-update.path /etc/systemd/system/kienzle-sumup-update.service
# Firewall aus dem Webinterface: root-Dienst, ausgelöst durch eine Anfragedatei; eigene nftables-Tabelle.
install -m 755 -o root -g root scripts/firewall-apply.py /usr/local/sbin/kienzle-sumup-firewall
install -d -m 755 -o root -g root /var/lib/kienzle-sumup-firewall
app_uid=$(id -u kienzle-sumup)
cat > /etc/systemd/system/kienzle-sumup-firewall.path <<'UNIT'
[Unit]
Description=Kienzle-SumUp Firewall-Änderung aus den Einstellungen
[Path]
PathExists=/var/lib/kienzle-sumup/firewall-request
Unit=kienzle-sumup-firewall-apply.service
[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/kienzle-sumup-firewall-apply.service <<UNIT
[Unit]
Description=Kienzle-SumUp Firewall anwenden
[Service]
Type=oneshot
Environment=KS_FW_UID=$app_uid
ExecStart=/usr/local/sbin/kienzle-sumup-firewall
TimeoutStartSec=300
UNIT
cat > /etc/systemd/system/kienzle-sumup-firewall.service <<'UNIT'
[Unit]
Description=Kienzle-SumUp Firewall-Regeln beim Start laden
Before=network-pre.target
Wants=network-pre.target
[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/sbin/kienzle-sumup-firewall --boot
[Install]
WantedBy=multi-user.target
UNIT
chmod 644 /etc/systemd/system/kienzle-sumup-firewall{.path,-apply.service,.service}
systemctl daemon-reload
systemctl enable --now kienzle-sumup-php.service kienzle-sumup-apache.service
if [ "$export_timer" = 1 ]; then systemctl enable --now kienzle-sumup-export.timer; fi
systemctl enable --now kienzle-sumup-update.path
systemctl enable --now kienzle-sumup-firewall.path
systemctl enable kienzle-sumup-firewall.service
echo "Bereit auf HTTPS-Port $port. Client-Einrichtung: /root/kienzle-sumup-clients"
echo 'Diesen Ordner geschützt auf die Arbeitsplätze übertragen; er enthält den Starter-Schlüssel.'
echo 'Firewall: Einstellungen → Einrichtung → Netzwerk & Firewall (Netze werden automatisch erkannt).'
# Drucker „SumUp-Rechnung“ an die gespeicherten Einstellungen angleichen (bei eingeschaltetem Rechnungseingang).
runuser -u kienzle-sumup -- php /opt/kienzle-sumup/scripts/invoice-printer-sync.php || echo 'Warnung: Drucker „SumUp-Rechnung“ nicht abgeglichen (Einstellungen → Speichern wiederholt es).'
if [ "$terminzettel_ok" = 1 ]; then
  # Zielbondrucker und gespeichertes Layout an den Terminzettel übergeben.
  runuser -u kienzle-sumup -- php /opt/kienzle-sumup/scripts/terminzettel-sync.php || echo 'Warnung: Terminzettel-Einstellungen nicht übertragen.'
  echo "Terminzettel: Drucker http://$(hostname):631/printers/Terminzettel (Layout unter Einstellungen → Terminzettel)."
fi
echo
# Einstellungen im Browser: beim ersten Mal (oder nach --admin-reset) mit einmaligem Einrichtungslink.
setup_url=$(runuser -u kienzle-sumup -- php /opt/kienzle-sumup/scripts/admin-setup.php $admin_reset)
if [ -n "$setup_url" ]; then
  echo 'Ersteinrichtung im Browser (Link einmalig gültig, nicht weitergeben):'
  echo "  $setup_url"
  echo 'Dort Admin-Passwort festlegen und SumUp, T2med, Drucker usw. eintragen.'
else
  echo "Einstellungen: $(runuser -u kienzle-sumup -- php -r 'require "/opt/kienzle-sumup/src/bootstrap.php"; echo rtrim(ks_base_config()->get("app","base_url"),"/");')/settings.php"
  echo 'Admin-Passwort vergessen? sudo bash scripts/install-server.sh --admin-reset'
fi
