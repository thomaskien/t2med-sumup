# kienzle-sumup für T2med

Eine kleine PHP-Webanwendung für Kartenzahlungen am SumUp Solo. Leistungen auswählen,
Betrag ans Terminal senden und nach erfolgreicher Zahlung bewusst in der Patientenakte
dokumentieren. **Version 0.1**, von Dr. Thomas Kienzle.

## Ablauf

1. In T2med beim Patienten den Button **Kienzle-SumUp** betätigen.
2. Leistungen auswählen oder einen sonstigen Betrag eingeben.
3. **Mit Karte kassieren** anklicken und Zahlung am Solo durchführen.
4. Die Seite zeigt den von SumUp bestätigten Zahlungsstatus.
5. **Dokumentation in der Akte** schreibt einen Freitext-Eintrag und schließt den Vorgang ab.
   Das Fenster wird anschließend nach Möglichkeit geschlossen; andernfalls bleibt die
   Abschlussmeldung sichtbar und das Fenster kann manuell geschlossen werden.

**Leistungen verwalten** öffnet die Bearbeitung auf derselben Seite. Leistungen lassen sich
anlegen, bearbeiten und löschen. Löschen entfernt sie aus der Auswahl; gespeicherte
Zahlungen behalten ihre damaligen Bezeichnungen und Preise.

Kein Hintergrundjob und kein Webhook. Bei geschlossenem Browser findet keine weitere
Statusabfrage oder automatische Dokumentation statt. Erneut aus demselben t2med-Kontext
öffnen setzt einen unvollständigen Vorgang fort. Ein neuer Behandlungskontext kann eine
alte Zahlung nicht automatisch übernehmen.

## Server installieren

Unterstütztes Ziel: Debian 12+, Ubuntu 22.04+ und Raspberry Pi OS Bookworm+ mit systemd,
PHP 8.1 oder neuer; x86_64, ARM64 und ARMv6/7. Die FHIR-Schnittstelle von T2med muss
vom Server erreichbar sein. Internetzugang wird für SumUp und Paketinstallation benötigt.

```bash
git clone https://github.com/thomaskien/t2med-sumup.git
cd t2med-sumup
sudo bash scripts/install-server.sh
```

Der Installer fragt Serveradresse, Port, Betriebsmodus, SumUp-Zugangsdaten und die
t2med-Konfiguration ab. Zugangsschlüssel werden verdeckt eingegeben. Standardport ist
**7868 (SUMU)**. Ein belegter Port wird erkannt. Für den ersten Test `mock` wählen;
später denselben Installer zum Umschalten auf `live` ausführen.

Es entstehen eine eigene Apache-Instanz und ein eigener PHP-FPM-Dienst. Die Anwendung
verwendet die Distributionspakete, ihre eigene Konfiguration und eigene Laufzeitdateien.
Der Installer bearbeitet keine vorhandenen Apache-VirtualHosts. Die Paketverwaltung
kann beim erstmaligen Installieren von Apache/PHP deren Standarddienste aktivieren.

| Inhalt | Ort |
| --- | --- |
| Zentrale TOML-Konfiguration | `/etc/kienzle-sumup/kienzle-sumup.toml` |
| Anwendung | `/opt/kienzle-sumup` |
| Datenbank und Sperrdateien | `/var/lib/kienzle-sumup` |
| Serverzertifikat und Schlüssel | `/etc/kienzle-sumup/server.crt`, `server.key` |
| Apache-/PHP-Konfiguration | `/etc/kienzle-sumup/apache.conf`, `php-fpm.conf` |
| Fehlerprotokolle | `/var/log/kienzle-sumup` |
| Client-Einrichtungsordner | `/root/kienzle-sumup-clients` |

```bash
sudo systemctl status kienzle-sumup-apache kienzle-sumup-php
```

Der Installer erzeugt ein selbstsigniertes RSA-Zertifikat mit 50 Jahren Laufzeit und
Servernamen bzw. IP im Subject Alternative Name. Vorhandene Zertifikate werden bei Updates
beibehalten. Das Zertifikat muss auf den Clients vertraut werden; eine lange Laufzeit
allein erzeugt kein Browservertrauen. TLS bleibt geprüft. Für t2med kann ein eigenes
öffentliches PEM-Zertifikat/CA hinterlegt werden. Keine privaten t2med-Schlüssel kopieren.

Die Firewall für TCP 7868 nur im Praxisnetz öffnen. Der Installer ändert keine Firewall.

## Starter auf den Arbeitsplätzen

Den Ordner `/root/kienzle-sumup-clients` geschützt auf die Arbeitsplätze kopieren.
Er enthält `client.json` mit Serveradresse, Starter-Schlüssel und Zertifikat-Fingerabdruck
sowie das öffentliche Serverzertifikat. **Diesen Ordner nicht ins Repository oder unter
den Webroot legen.** Er ist ausschließlich für berechtigte Praxisarbeitsplätze vorgesehen.

Die Installer laden den passenden fertigen Starter aus GitHub Release `v0.1` und prüfen
seine SHA-256-Prüfsumme. Python, Go und PHP werden auf dem Arbeitsplatz nicht benötigt.
Alternativ vorab die Release-Dateien und `SHA256SUMS` in diesen Ordner legen.

- **Windows 10/11:** `install-windows.cmd` ausführen. Der Starter wird pro Benutzer unter
  `%LOCALAPPDATA%\Kienzle-SumUp` installiert. Das Zertifikat kommt in den Zertifikatsspeicher
  des aktuellen Benutzers.
- **macOS:** `install-macos.command` ausführen (falls nötig `chmod +x install-macos.command`).
  Eintrag in `~/Applications/Kienzle-SumUp.app`, Starter und Konfiguration in
  `~/Library/Application Support/Kienzle-SumUp`. Der Installer fügt das Zertifikat dem
  Anmeldeschlüsselbund hinzu; macOS kann eine Bestätigung verlangen.
- **Linux-Desktop:** `bash install-linux.sh` ausführen. Benötigt `curl` und `xdg-utils`.
  Installation unter `~/.local/share/kienzle-sumup` und Registrierung per `.desktop`-Datei.
  `server.crt` anschließend im verwendeten Browser/Vertrauensspeicher importieren.

Browser mit eigenem Zertifikatsspeicher können auch unter Windows/macOS einen eigenen
Import verlangen. Der Starter selbst prüft das exakte, bei der Einrichtung übertragene
Zertifikat einschließlich Name und Laufzeit. Er überträgt den Kontext per HTTPS an PHP,
öffnet einen einmal verwendbaren Browserlink und beendet sich. Es läuft kein lokaler Dienst.

## T2med-Button einrichten

Die Platzhalter folgen dem bereits verwendeten Kienzledoku-Aufruf. Unter
**Drittanbieter-Zugriffe verwalten** eine zusätzliche URL mit dem Namen **Kienzle-SumUp**
anlegen und einem eigenen Menüleisten-Button zuweisen:

```text
kienzle-sumup://?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}
```

Der Starter registriert ausschließlich `kienzle-sumup://`. Vorhandene Registrierungen von
`kienzledoku://`, `whisperdoku://` und `T2demo://` bleiben erhalten. Für live werden die
zur Drittanbieterdefinition passenden t2med-FHIR-Zugangsdaten benötigt.

`fhir.launch_urls` enthält die exakten erlaubten Aufrufadressen. `fhir.base_url` ist das
vom PHP-Server erreichbare feste Ziel. Dadurch lässt sich eine im t2med-Aufruf enthaltene
Client-Loopback-Adresse explizit auf den tatsächlich erreichbaren Praxisserver abbilden.
Der Client kann keinen beliebigen FHIR-Server vorgeben.

## SumUp Solo einrichten

Benötigt werden API-Key, Affiliate-Key, zugehörige App-ID, Händlercode und die ID eines
mit dem Händlerkonto gekoppelten Solo-Readers. Die Reader-Kopplung erfolgt über die
SumUp-Entwicklerwerkzeuge/Reader API. Der Installer nimmt die bestehende Reader-ID auf.
Benötigte Berechtigungen: Reader-Checkout starten/lesen/abbrechen sowie Transaktionen lesen.
Firmware und Kontofreigaben müssen die Cloud API unterstützen.

Die Anwendung sendet ausschließlich Betrag, EUR, neutrale Referenz und erforderliche
technische Affiliate-Metadaten. Patient und Leistungsbezeichnungen werden nie an SumUp
gesendet. Keine Kartendaten werden lokal gespeichert.

- [SumUp Cloud API](https://developer.sumup.com/terminal-payments/cloud-api)
- [Reader API](https://developer.sumup.com/api/readers)
- [Transactions API](https://developer.sumup.com/api/transactions)

## Fehler und erneute Versuche

- Startanfragen mit derselben lokalen ID geben den bestehenden Zahlungsversuch zurück.
  Eine neue ID startet ebenfalls keine zweite Zahlung für denselben offenen Vorgang.
- Solange ein Terminalvorgang ungeklärt ist, startet die Anwendung dort keine neue Zahlung.
- Ein Timeout ist kein Zahlungsfehlschlag. Die Seite gleicht über die gespeicherte
  SumUp-ID oder die vor dem Start gespeicherte neutrale Referenz ab.
- Erfolg wird über den Transaktionsstatus mit passendem Händler, Referenz, Betrag und EUR
  bestätigt. Ein angenommener Abbruchauftrag allein gilt noch nicht als Abbruch.
- FHIR wird ausschließlich durch den Dokumentationsbutton aufgerufen; Polling schreibt nie.
- Bei eindeutiger Ablehnung bleibt die Zahlung bezahlt und der Button erlaubt einen neuen
  Dokumentationsversuch. Nach bestätigtem Schreiben wird kein zweiter Eintrag erzeugt.
- Wenn die FHIR-Antwort verloren geht, prüft ein erneuter Klick zuerst den vorhandenen
  Eintrag anhand der Zahlungsreferenz. Unterstützt t2med laut CapabilityStatement
  bedingtes Anlegen, kann ein Wiederholungsaufruf damit dedupliziert werden. Andernfalls
  bleibt ein ungeklärter Schreibversuch offen, bis der passende Eintrag nachweisbar ist;
  er wird nicht blind erneut geschrieben. Ein einfacher erfolgreicher POST funktioniert
  auch ohne Unterstützung für bedingtes Anlegen.
- Ein nicht auflösbarer SumUp-Vorgang oder eine unklare t2med-Übertragung muss anhand der
  tatsächlichen Transaktion/Akte geprüft werden. Es gibt bewusst keinen Button, der eine
  Zahlung ohne Bestätigung als bezahlt markiert oder die Schutzsperre pauschal zurücksetzt.

Patientenkontext und Token sind einer Sitzung zugeordnet. Tokens werden verschlüsselt
in SQLite gespeichert und beim Abschluss gelöscht; abgelaufene Tokens werden beim nächsten
Start entfernt. Browser erhalten nur eine Sitzung, keine SumUp-/FHIR-Schlüssel. Einmalige
Starttickets laufen nach 90 Sekunden ab und werden aus der Adresszeile entfernt.
Die App ist für eine Praxis mit einem Terminal gedacht; keine komplexe Benutzerverwaltung.
Alle berechtigten Starter-Benutzer können Leistungen verwalten.

## TOML und Updates

Die Konfiguration verwendet einen kleinen, strikt geprüften TOML-Teilumfang: einfache
Tabellen, einzeilige Strings, boolesche Werte, positive Dezimal-Ganzzahlen und einzeilige
Arrays aus doppelt zitierten Strings. Beispiel: `config/kienzle-sumup.example.toml`.
Kein INI-Parser, keine automatische Typumwandlung bei Geldbeträgen.

Nach manuellen Änderungen den Installer erneut ausführen, damit Apache-Port und
Clientkonfiguration zur TOML passen. Bestehende API-Schlüssel, Verschlüsselungsschlüssel,
Datenbank und Serverzertifikat bleiben bei gewöhnlichen Updates erhalten. Nach Änderung
von Serveradresse, Starter-Schlüssel oder Zertifikat die Clients erneut einrichten.

Vor Updates offene Zahlungen abschließen. Anwendung aktualisieren und Installer ausführen:

```bash
git pull --ff-only
sudo bash scripts/install-server.sh
```

Für Sicherungen die SQLite-Backup-Funktion verwenden (oder die eigenen Dienste kurz stoppen)
und `/var/lib/kienzle-sumup` sowie `/etc/kienzle-sumup` geschützt sichern. Der
Verschlüsselungsschlüssel wird zum Lesen offener Sitzungstokens benötigt.

## Lokaler Test ohne Terminal und t2med

PHP 8.1+ mit `curl`, `pdo_sqlite`, `mbstring` und `sodium`:

```bash
php scripts/dev.php
```

`http://127.0.0.1:7868` öffnen, **Testpatient öffnen** wählen und Leistungen anlegen.
Nach dem Kassierklick über **Zahlung erfolgreich** oder **Zahlung abgelehnt** simulieren.
Ein t2med-Fehler lässt sich direkt auf der Seite ein-/ausschalten. Testeinträge werden nur
lokal in `mock_records` gespeichert. Entwicklungsmodus erlaubt keine echten Schnittstellen.

```bash
php tests/critical.php
cd client && go test ./...
```

Die wenigen Tests decken Betrag/Snapshot, Zugriffsbindung, Doppelaufrufe, verlorene
Zahlungsantworten und wiederholbare manuelle Dokumentation ab. Starter aus Quellen bauen:

```bash
bash scripts/build-client.sh
```

GitHub Actions prüft diese Abläufe und baut die Starter für Windows, macOS und Linux.
Ein Tag `v0.1` veröffentlicht die Pakete und Prüfsummen automatisch als GitHub Release.

## Stand der Integration

Die t2med-Profile und Header wurden aus der vorhandenen Kienzledoku-FHIR-Anbindung
übernommen; SumUp-Endpunkte aus der offiziellen Referenz (September 2026). Automatisierte
Prüfungen ersetzen keinen Test mit dem konkreten Solo und der t2med-Installation.
Insbesondere die Suche nach Zahlungskennungen und bedingtes Anlegen sind serverabhängig;
ein CapabilityStatement kann fehlen. Vor Nutzung mit echten Patienten den gesamten Ablauf
mit einem vorgesehenen Testkontext prüfen. Details der tatsächlichen Ausführung stehen in
`docs/VALIDATION.md`.
