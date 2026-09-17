# Epson TM-m10 per Bluetooth an Linux anbinden und über Samba für macOS freigeben

Diese Anleitung beschreibt eine praxiserprobte Konfiguration für folgenden Aufbau:

```text
macOS
  │
  │ Epson TM-m10 Treiber
  ▼
Samba
  ▼
CUPS (RAW / serial backend)
  ▼
/dev/rfcomm0
  ▼
dauerhafte Bluetooth-RFCOMM-Verbindung
  ▼
Epson TM-m10
```

Der entscheidende Punkt ist, die Bluetooth-RFCOMM-Verbindung **dauerhaft offen zu halten**.

Beim TM-m10 kann ein ständiges Öffnen und Schließen der RFCOMM-Verbindung dazu führen, dass der erste Druck noch funktioniert, spätere Drucke aber ausbleiben oder nur noch der Schneidebefehl ankommt. Mit einer permanenten RFCOMM-Verbindung funktionieren mehrere Druckjobs hintereinander zuverlässig.

## Voraussetzungen

Diese Anleitung geht von einem Debian-/Ubuntu-artigen Linux-System aus.

Benötigt werden:

- Epson TM-m10 mit Bluetooth
- Linux-Rechner mit Bluetooth
- CUPS
- Samba
- BlueZ
- macOS-Client mit Epson TM-m10 Druckertreiber

Installation der benötigten Pakete:

```bash
sudo apt update
sudo apt install bluez cups samba
sudo systemctl enable --now bluetooth cups smbd
```

## 1. TM-m10 in den Pairing-Modus versetzen

Falls der Drucker beim Bluetooth-Scan nicht erscheint:

1. Drucker einschalten.
2. Papierdeckel öffnen.
3. `FEED` etwa 2 Sekunden gedrückt halten.
4. Papierdeckel wieder schließen.
5. Der Drucker druckt ein Statusblatt und ist für kurze Zeit auffindbar.

Bluetooth-Scan starten:

```bash
bluetoothctl
```

Dann:

```text
power on
agent on
default-agent
scan bredr
```

Der Drucker sollte ungefähr so erscheinen:

```text
TM-m10_000389
```

In diesem Beispiel lautet die Bluetooth-Adresse:

```text
00:01:90:C6:72:1F
```

Die Adresse muss in allen folgenden Befehlen durch die Adresse des eigenen Druckers ersetzt werden.

## 2. Pairing durchführen

In `bluetoothctl`:

```text
pair 00:01:90:C6:72:1F
trust 00:01:90:C6:72:1F
scan off
exit
```

Prüfen:

```bash
bluetoothctl info 00:01:90:C6:72:1F
```

Wichtig sind insbesondere:

```text
Paired: yes
Bonded: yes
Trusted: yes
```

## 3. RFCOMM-Kanal ermitteln

Der TM-m10 stellt einen Bluetooth Serial Port zur Verfügung.

Abfragen:

```bash
sudo sdptool browse 00:01:90:C6:72:1F
```

Typische Ausgabe:

```text
Service Name: Serial Port DevB
Service Class ID List:
  "Serial Port" (0x1101)

Protocol Descriptor List:
  "L2CAP" (0x0100)
  "RFCOMM" (0x0003)
    Channel: 1
```

In diesem Beispiel ist der RFCOMM-Kanal:

```text
1
```

## 4. Verbindung zunächst manuell testen

Vor dem ersten Test andere Druckdienste stoppen:

```bash
sudo systemctl stop cups
sudo rfcomm release all 2>/dev/null || true
```

Dauerhafte RFCOMM-Verbindung testweise starten:

```bash
sudo rfcomm -r connect rfcomm0 00:01:90:C6:72:1F 1
```

Erwartete Ausgabe:

```text
Connected /dev/rfcomm0 to 00:01:90:C6:72:1F on channel 1
Press CTRL-C for hangup
```

Dieser Befehl läuft im Vordergrund.

Daher entweder ein zweites Terminal öffnen oder die Verbindung im Hintergrund starten:

```bash
sudo rfcomm -r connect rfcomm0 00:01:90:C6:72:1F 1 \
  </dev/null >/tmp/tmm10-rfcomm.log 2>&1 &
```

Prüfen:

```bash
ls -l /dev/rfcomm0
```

Wichtig: `/dev/rfcomm0` muss ein **Character Device** sein. Die Ausgabe beginnt dann mit `c`, zum Beispiel:

```text
crw-rw---- 1 root dialout ...
```

Falls dort stattdessen eine normale Datei liegt, z. B.:

```text
-rw-r--r-- ...
```

muss diese gelöscht werden:

```bash
sudo rm -f /dev/rfcomm0
```

Danach RFCOMM erneut starten.

## 5. Direkten ESC/POS-Test durchführen

Mit bestehender RFCOMM-Verbindung:

```bash
printf '\x1b\x40TEST 1\n\n\n\x1d\x56\x00' | sudo tee /dev/rfcomm0 >/dev/null
```

Danach mehrere weitere Tests durchführen:

```bash
printf '\x1b\x40TEST 2\n\n\n\x1d\x56\x00' | sudo tee /dev/rfcomm0 >/dev/null
printf '\x1b\x40TEST 3\n\n\n\x1d\x56\x00' | sudo tee /dev/rfcomm0 >/dev/null
```

Alle Ausdrucke sollten erscheinen und jeweils geschnitten werden.

### Wichtig

Wenn nur der erste Druck funktioniert und spätere Verbindungen fehlschlagen, sollte **nicht** versucht werden, für jeden Druckjob eine neue RFCOMM-Verbindung aufzubauen.

Die funktionierende Lösung ist:

> Eine einzige RFCOMM-Verbindung dauerhaft geöffnet halten.

## 6. Dauerhafte RFCOMM-Verbindung mit systemd

Vorhandene manuelle RFCOMM-Verbindungen beenden:

```bash
sudo pkill -f 'rfcomm -r connect rfcomm0' 2>/dev/null || true
sudo rfcomm release rfcomm0 2>/dev/null || true
```

Systemd-Service erstellen:

```bash
sudo nano /etc/systemd/system/tm-m10-rfcomm.service
```

Inhalt:

```ini
[Unit]
Description=Persistent Bluetooth RFCOMM connection to Epson TM-m10
After=bluetooth.service
Wants=bluetooth.service
StartLimitIntervalSec=0

[Service]
Type=simple

ExecStartPre=-/usr/bin/rfcomm release rfcomm0
ExecStartPre=/bin/sh -c 'if [ -e /dev/rfcomm0 ] && [ ! -c /dev/rfcomm0 ]; then rm -f /dev/rfcomm0; fi'

ExecStart=/usr/bin/rfcomm -r connect rfcomm0 00:01:90:C6:72:1F 1

ExecStartPost=/bin/sh -c 'for i in $(seq 1 20); do [ -c /dev/rfcomm0 ] && exit 0; sleep 0.5; done; exit 1'

ExecStop=-/usr/bin/rfcomm release rfcomm0

Restart=always
RestartSec=5
TimeoutStopSec=5

[Install]
WantedBy=multi-user.target
```

MAC-Adresse und gegebenenfalls RFCOMM-Kanal anpassen.

Service aktivieren:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now tm-m10-rfcomm.service
```

Status prüfen:

```bash
systemctl status tm-m10-rfcomm.service --no-pager
rfcomm
ls -l /dev/rfcomm0
```

## 7. CUPS nach RFCOMM starten lassen

Damit CUPS erst nach dem Bluetooth-Dienst startet:

```bash
sudo mkdir -p /etc/systemd/system/cups.service.d
```

```bash
sudo nano /etc/systemd/system/cups.service.d/tm-m10.conf
```

Inhalt:

```ini
[Unit]
Wants=tm-m10-rfcomm.service
After=tm-m10-rfcomm.service
```

Danach:

```bash
sudo systemctl daemon-reload
sudo systemctl restart cups
```

## 8. CUPS-RAW-Queue einrichten

Dem CUPS-Benutzer Zugriff auf serielle Geräte geben:

```bash
sudo usermod -aG dialout lp
sudo systemctl restart cups
```

Drucker anlegen:

```bash
sudo lpadmin \
  -p TMm10 \
  -E \
  -v 'serial:/dev/rfcomm0?baud=115200+parity=none+flow=none' \
  -m raw
```

Prüfen:

```bash
lpstat -v TMm10
```

Erwartete Ausgabe:

```text
device for TMm10: serial:/dev/rfcomm0?baud=115200+parity=none+flow=none
```

RAW-Test:

```bash
printf '\x1b\x40CUPS TEST\n\n\n\x1d\x56\x00' >/tmp/tmtest.raw
lp -d TMm10 -o raw /tmp/tmtest.raw
```

## 9. Samba-Freigabe konfigurieren

Spool-Verzeichnis anlegen:

```bash
sudo mkdir -p /var/spool/samba
sudo chmod 1777 /var/spool/samba
```

In `/etc/samba/smb.conf` im vorhandenen `[global]`-Abschnitt ergänzen:

```ini
printing = cups
printcap name = cups
```

Anschließend eine Druckerfreigabe hinzufügen:

```ini
[TMm10]
    comment = Epson TM-m10 Bluetooth
    path = /var/spool/samba
    printable = yes
    browseable = yes
    read only = yes
    printer name = TMm10
    cups options = raw
```

Konfiguration prüfen:

```bash
testparm
```

Samba neu starten:

```bash
sudo systemctl restart smbd
```

## 10. Einrichtung unter macOS

Auf dem Mac den offiziellen Epson-Treiber für den TM-m10 installieren.

Der Netzwerkdrucker kann anschließend über die Samba-Freigabe eingebunden werden, beispielsweise:

```text
smb://kienzlebox/TMm10
```

oder über die IP-Adresse:

```text
smb://192.168.x.x/TMm10
```

Als Treiber sollte ausdrücklich der **Epson TM-m10 Treiber** verwendet werden, nicht AirPrint oder ein generischer PostScript-Treiber.

Der Datenweg lautet dann:

```text
macOS-Anwendung
    ↓
Epson TM-m10 Treiber
    ↓
Samba
    ↓
CUPS RAW
    ↓
serial backend
    ↓
/dev/rfcomm0
    ↓
dauerhafte Bluetooth-Verbindung
    ↓
TM-m10
```

## 11. Schneidefunktion

Ein wichtiger Punkt dieser Konfiguration:

**Keinen zusätzlichen serverseitigen Cut-Befehl anhängen, wenn der Epson-macOS-Treiber bereits schneidet.**

Im Test zeigte sich zunächst folgendes Verhalten:

- Druckdaten kamen an.
- Der vom Mac-Treiber gesendete Cut-Befehl lag offenbar am Ende des Datenstroms.
- Bei einer ständig neu aufgebauten RFCOMM-Verbindung ging dieser letzte Teil teilweise verloren.
- Ein zusätzlich serverseitig angehängter ESC/POS-Cut ließ den Drucker zwar schneiden, führte nach Umstellung auf die permanente Verbindung aber zu **zwei Schnitten**.

Mit permanenter RFCOMM-Verbindung funktioniert der vom Epson-Treiber erzeugte Schneidebefehl zuverlässig.

Daher lautet die endgültige Konfiguration:

```text
CUPS Device URI:
serial:/dev/rfcomm0?baud=115200+parity=none+flow=none
```

Kein eigenes AutoCut-Backend erforderlich.

## 12. Reboot-Test

System neu starten:

```bash
sudo reboot
```

Danach prüfen:

```bash
systemctl status tm-m10-rfcomm.service --no-pager
```

```bash
rfcomm
```

```bash
ls -l /dev/rfcomm0
```

```bash
lpstat -v TMm10
```

Erwartet:

- `tm-m10-rfcomm.service` läuft.
- `/dev/rfcomm0` existiert als Character Device.
- CUPS verwendet `serial:/dev/rfcomm0?...`.
- Mehrere Druckjobs hintereinander funktionieren.
- Der Epson-Treiber löst am Ende jedes Jobs genau einen Schnitt aus.

# Fehlerbehebung

## `Can't connect RFCOMM socket: Host is down`

Zunächst alle automatischen Zugriffe stoppen:

```bash
sudo systemctl stop cups
sudo systemctl stop tm-m10-rfcomm.service
sudo rfcomm release all 2>/dev/null || true
```

Bluetooth-Discovery ausschalten:

```bash
bluetoothctl
```

```text
scan off
exit
```

Kontrolle:

```bash
bluetoothctl show | grep Discovering
```

Erwartet:

```text
Discovering: no
```

Falls der Drucker weiterhin nicht erreichbar ist:

1. TM-m10 ausschalten.
2. Einige Sekunden warten.
3. Wieder einschalten.
4. Gegebenenfalls erneut in den Pairing-Modus versetzen.
5. Pairing auf Linux entfernen und neu durchführen.

Pairing löschen:

```bash
bluetoothctl
```

```text
remove 00:01:90:C6:72:1F
scan bredr
```

Sobald der Drucker wieder erscheint:

```text
pair 00:01:90:C6:72:1F
trust 00:01:90:C6:72:1F
scan off
exit
```

Danach erneut:

```bash
sudo rfcomm -r connect rfcomm0 00:01:90:C6:72:1F 1
```

## Drucker ist sichtbar, verbindet sich aber nicht

Prüfen:

```bash
bluetoothctl info 00:01:90:C6:72:1F
```

Erwartet:

```text
Paired: yes
Bonded: yes
Trusted: yes
```

Beim Einsatz ausschließlich mit Linux/macOS kann es außerdem sinnvoll sein, am TM-m10 die Funktion **Auto Re-Connect iOS** zu deaktivieren.

## Nur erster Druck funktioniert

Das ist das zentrale Problem, das diese Anleitung löst.

Nicht:

```text
Job 1 → Bluetooth verbinden → drucken → trennen
Job 2 → Bluetooth verbinden → drucken → trennen
```

sondern:

```text
Bluetooth einmal verbinden
          │
          ├── Job 1
          ├── Job 2
          ├── Job 3
          └── ...
```

Dazu muss `rfcomm connect` als dauerhafter Dienst laufen.

## Drucker schneidet zweimal

Dann wird zusätzlich zum Epson-Treiber noch ein eigener ESC/POS-Cut gesendet.

Eigenes AutoCut-Backend entfernen bzw. die CUPS-Queue wieder direkt auf das normale Serial-Backend setzen:

```bash
sudo lpadmin \
  -p TMm10 \
  -v 'serial:/dev/rfcomm0?baud=115200+parity=none+flow=none'
```

Danach:

```bash
sudo systemctl restart cups
```

## Drucker schneidet, aber druckt nicht

Wenn ein selbstgebautes CUPS-Backend verwendet wurde, dieses zunächst entfernen und auf das originale CUPS-Serial-Backend zurückgehen:

```bash
sudo lpadmin \
  -p TMm10 \
  -v 'serial:/dev/rfcomm0?baud=115200+parity=none+flow=none'
```

Die funktionierende Konfiguration benötigt kein eigenes CUPS-Backend.

# Zusammenfassung

Die wesentliche Erkenntnis lautet:

> Der Epson TM-m10 sollte bei dieser Konfiguration nicht für jeden Druckjob per RFCOMM neu verbunden werden.

Die stabile Lösung ist:

1. TM-m10 einmal pairen und als `Trusted` markieren.
2. RFCOMM-Kanal ermitteln.
3. `rfcomm -r connect` permanent als systemd-Dienst laufen lassen.
4. `/dev/rfcomm0` als CUPS-Serial-Gerät verwenden.
5. CUPS-Queue RAW betreiben.
6. CUPS über Samba freigeben.
7. Auf macOS den Epson TM-m10 Treiber verwenden.
8. Keine zusätzlichen serverseitigen Cut-Befehle einfügen, wenn der Epson-Treiber bereits schneidet.

Damit funktionieren mehrere Druckjobs hintereinander stabil und der automatische Schnitt des Epson-Treibers erreicht den Drucker zuverlässig.
