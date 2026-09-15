# kienzle-sumup für T2med

Eine kleine PHP-Webanwendung für Kartenzahlungen am SumUp Solo. Leistungen auswählen,
Betrag ans Terminal senden und nach erfolgreicher Zahlung bewusst in der Patientenakte
dokumentieren. **Version 1.1**, von Dr. Thomas Kienzle.

## Video: Sumup in T2med anbinden

<p align="center">
  <a href="https://youtu.be/VqhrRvIP_0Q">
    <img src="https://i.ytimg.com/vi/VqhrRvIP_0Q/hqdefault.jpg" width="640" alt="Video ansehen: Sumup in T2med anbinden von Thomas Kienzle">
  </a>
</p>

**[▶ Video auf YouTube ansehen](https://youtu.be/VqhrRvIP_0Q)**

## Ablauf

1. In T2med beim Patienten den Button **Kienzle-SumUp** betätigen.
2. Leistungen auswählen oder einen sonstigen Betrag eingeben.
3. **Mit Karte kassieren** anklicken und Zahlung am Solo durchführen.
4. Die Seite zeigt den von SumUp bestätigten Zahlungsstatus.
   Bei Bedarf **Zahlungsbeleg / PDF** anklicken: Der Originalbeleg von SumUp wird
   als PDF geöffnet und kann gedruckt oder gespeichert werden.
5. Optional **Beleg mailen an „emailadresse“** direkt unter dem PDF-Button anklicken.
   Ein Klick versendet den PDF-Anhang an die angezeigte Adresse.
6. **Dokumentation in der Akte** schreibt einen Freitext-Eintrag und schließt den Vorgang ab.
   Das Fenster wird anschließend nach Möglichkeit geschlossen; andernfalls bleibt die
   Abschlussmeldung sichtbar und das Fenster kann manuell geschlossen werden.

**Leistungen verwalten** öffnet die Bearbeitung auf derselben Seite. Leistungen lassen sich
anlegen, bearbeiten und löschen. Löschen entfernt sie aus der Auswahl; gespeicherte
Zahlungen behalten ihre damaligen Bezeichnungen und Preise.

**Beleglink / E-Mail-Adresse ändern** zeigt den von SumUp gelieferten Originalbeleg-Link sowie
**PDF herunterladen**. Die PDF wird aus der SVG (alternativ PNG) des Originalbelegs
erzeugt. Voraussetzung ist ein gültiger Beleglink in der geprüften Transaktion.
Die frühere zusätzliche JSON-Belegabfrage wird nicht mehr verwendet.

Für **PDF per E-Mail senden** im Server-Installer den E-Mail-Versand aktivieren und
SMTP-Server, Port, Verschlüsselung (`starttls`, meist 587, oder `smtps`, meist 465),
Benutzername, Passwort und Absender eintragen. Das Passwort wird verdeckt abgefragt;
die Daten bleiben unter `[mail]` in der geschützten TOML-Datei. Bestehende Einstellungen
bleiben bei Updates erhalten. Ohne SMTP bleibt der PDF-Download nutzbar.

Die bevorzugte aktuelle E-Mail-Adresse wird nach erfolgreicher Zahlung automatisch
aus t2med geladen und auf dem großen Button **Beleg mailen an „emailadresse“**
angezeigt. Ein Klick darauf sendet die PDF über den konfigurierten Mailserver.
Unter **Beleglink / E-Mail-Adresse ändern** kann die Adresse vorher geändert werden.
Fehlt eine Adresse, öffnet der große Button die Eingabe. Es läuft
kein Hintergrundversand. Die Bestätigung bedeutet, dass der Mailserver die Nachricht
angenommen hat; die Zustellung an das Postfach kann später scheitern. Bei einer
verlorenen Antwort wird derselbe Versandversuch geprüft; ein bewusster erneuter
Versand ist möglich. Nach Abschluss der Aktendokumentation ist der t2med-Zugriff
beendet; nach einem Neuladen kann die Adresse dann nur noch manuell eingetragen werden.

Belegabruf, Drucken und E-Mail-Versand verändern weder Zahlung noch Akteneintrag.
Der Browser kann nur auf Belege seiner erfolgreich bestätigten Zahlungen zugreifen.
Der Originalbeleg wird ohne API-Key vom geprüften SumUp-Beleglink geladen und lokal
mit `rsvg-convert` in PDF umgewandelt. Konverter und PHPMailer kommen als
Distributionspakete (`librsvg2-bin`, `libphp-phpmailer`) über den Installer.
Im Testmodus wird ein markierter Testbeleg erzeugt und kein SMTP-Versand ausgeführt.

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

`php.log` enthält Anwendungsfehler, `fpm.log` die PHP-FPM-Dienstmeldungen und
`apache-error.log` die Apache-Fehler. Vorhandene Logs bleiben bei Updates erhalten.

```bash
sudo systemctl status kienzle-sumup-apache kienzle-sumup-php
```

Der Installer erzeugt ein selbstsigniertes RSA-Zertifikat mit 50 Jahren Laufzeit und
Servernamen bzw. IP im Subject Alternative Name. Vorhandene Zertifikate werden bei Updates
beibehalten. Das Zertifikat muss auf den Clients vertraut werden; eine lange Laufzeit
allein erzeugt kein Browservertrauen. TLS bleibt geprüft. Für t2med kann ein eigenes
öffentliches PEM-Zertifikat/CA hinterlegt werden. Keine privaten t2med-Schlüssel kopieren.

Für ein t2med-Zertifikat ohne passenden Hostnamen kann die Verbindung gezielt an dessen
öffentlichen Schlüssel gebunden werden. Dazu das **öffentliche Serverzertifikat** direkt
vom t2med-Server oder seiner lokalen Schnittstelle beziehen und geschützt als `ca_file`
hinterlegen. Hierfür genau ein Serverzertifikat verwenden; kein CA-Bundle und keine
ungeprüfte Übernahme aus einem fremden Netzwerk. Unter dem vorhandenen Abschnitt `[fhir]`:

```toml
ca_file = "/etc/kienzle-sumup/t2med-ca.pem"
pin_certificate = true
```

Nur bei dieser ausdrücklich aktivierten Option ersetzt die Schlüsselbindung den
Hostnamenvergleich für FHIR. Zertifikatsvertrauen und Gültigkeit bleiben geprüft;
ein anderer öffentlicher Schlüssel wird vor der Übertragung von Zugangsdaten abgelehnt.
Bei einem beabsichtigten Schlüsselwechsel die Zertifikatsdatei erneut sicher übertragen.
Standard ist `false` mit normaler Hostnamenprüfung. Der Installer erhält diese Einstellung.

Die Firewall für TCP 7868 nur im Praxisnetz öffnen. Der Installer ändert keine Firewall.

## Starter auf den Arbeitsplätzen

Den Ordner `/root/kienzle-sumup-clients` geschützt auf die Arbeitsplätze kopieren.
Er enthält `client.json` mit Serveradresse, Starter-Schlüssel und Zertifikat-Fingerabdruck
sowie das öffentliche Serverzertifikat. **Diesen Ordner nicht ins Repository oder unter
den Webroot legen.** Er ist ausschließlich für berechtigte Praxisarbeitsplätze vorgesehen.

Die Installer laden den passenden fertigen Starter aus GitHub Release `v1.1` und prüfen
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

Zur Fehlerhilfe: `app.base_url` ist der Hostname der Zahlungsseite, `fhir.base_url` die
feste FHIR-Zieladresse und `fhir.launch_urls` die Liste erlaubter t2med-Aufrufadressen.
Die Fehlermeldung zeigt die tatsächlichen Werte; an diesen sollte sich die Korrektur
orientieren. In der laufenden Installation nur `launch_urls` in
`/etc/kienzle-sumup/kienzle-sumup.toml` um die verifizierte, reine t2med-Adresse ergänzen;
dafür ist kein Dienstneustart nötig. Danach erneut aus t2med öffnen. Keine Schlüssel oder
vollständigen Token-Links teilen.

## SumUp Solo einrichten

Der Installer benötigt fünf Werte aus deinem **SumUp-Händlerkonto**:

| Installer-Feld | Bedeutung |
| --- | --- |
| SumUp API-Key | Geheimer Schlüssel für die API-Aufrufe deines Kontos. |
| SumUp Affiliate-Key | Kennzeichnet die Anbindung bei Kartenzahlungen. |
| SumUp App-ID | Zum Affiliate-Key hinterlegte Kennung; Vorgabe: `de.kienzle.sumup`. |
| SumUp Händlercode | Eindeutige Kennung der Praxis bei SumUp, beispielsweise `MK01A8C2`. |
| SumUp Reader-ID | API-ID des mit diesem Händlerkonto gekoppelten Solo. |

### API-Key und Affiliate-Key anlegen

1. Bei [SumUp](https://me.sumup.com) anmelden und über das Profil **Einstellungen →
   Für Entwickler / For Developers → Toolkit → API Keys** öffnen.
2. **Create / Erstellen** wählen, beispielsweise `kienzle-sumup` als Namen vergeben
   und den geheimen Key für die Eingabe im Installer aufbewahren. Der dort ebenfalls
   angezeigte **Public Key** ist hierfür ungeeignet.
3. Unter **Affiliate Keys** einen Schlüssel für die App-ID `de.kienzle.sumup` anlegen.
   Affiliate-Key und genau diese App-ID im Installer eintragen. Ein Affiliate-Key
   ersetzt den API-Key nicht.

Anleitungen von SumUp: [API-Key erstellen](https://developer.sumup.com/tools/authorization/api-keys),
[Affiliate-Key und App-ID](https://developer.sumup.com/tools/authorization/affiliate-keys).
Die eigenen geheimen Schlüssel ausschließlich im Server-Installer eingeben.
Der t2med-Demo-Key gilt nur für t2med und kann keinen SumUp-Key ersetzen.

### Händlercode ermitteln

Der Händlercode (`merchant_code`) bezeichnet dein Unternehmen bei SumUp und sieht
beispielsweise wie `MK01A8C2` aus. Er gehört zu dem Händlerkonto, für das der API-Key
erstellt wurde. [SumUp: Händler und Händlercode](https://developer.sumup.com/tools/glossary/merchant)

Mit deinem API-Key lässt sich das zugehörige Profil auf dem Linux-Server in Bash
abfragen. In der JSON-Antwort nach `merchant_code` suchen:

```bash
read -r -s -p 'SumUp API-Key: ' SUMUP_API_KEY
printf '\n'
curl --fail --silent --show-error https://api.sumup.com/v0.1/me \
  -H "Authorization: Bearer $SUMUP_API_KEY"
```

Diese [Profilabfrage](https://developer.sumup.com/tools/authorization/api-keys#authorize-requests-with-an-api-key)
verwendet denselben API-Key wie der Installer.

### Solo koppeln und Reader-ID übernehmen

1. Solo mit WLAN verbinden und bei einem angemeldeten Händlerkonto zunächst am
   Gerät abmelden: **Einstellungen → Über / About → Abmelden**.
2. Im Gerätemenü **Verbindungen / Connections → API → Verbinden / Connect** öffnen.
   Der angezeigte Pairing-Code ist fünf Minuten gültig.
3. Den Reader einmal über die API mit dem Händlerkonto verbinden. In derselben
   Bash-Sitzung den Händlercode eingeben und `PAIRINGCODE` im Befehl ersetzen:

```bash
read -r -p 'SumUp Händlercode: ' SUMUP_MERCHANT_CODE
curl --fail --silent --show-error \
  "https://api.sumup.com/v0.1/merchants/$SUMUP_MERCHANT_CODE/readers" \
  -H "Authorization: Bearer $SUMUP_API_KEY" \
  -H 'Content-Type: application/json' \
  --data '{"pairing_code":"PAIRINGCODE","name":"Praxis Solo"}'
```

4. **`id` aus der Antwort** als Reader-ID in den Installer übernehmen. Pairing-Code,
   Seriennummer und Händlercode sind andere Werte.

Bereits gekoppelte Reader lassen sich ohne erneute Kopplung abfragen:

```bash
curl --fail --silent --show-error \
  "https://api.sumup.com/v0.1/merchants/$SUMUP_MERCHANT_CODE/readers" \
  -H "Authorization: Bearer $SUMUP_API_KEY"
unset SUMUP_API_KEY SUMUP_MERCHANT_CODE
```

Bei mehreren Geräten die `id` des gewünschten Readers anhand seines Namens auswählen.
Details: [Solo-Kopplung](https://developer.sumup.com/terminal-payments/cloud-api#pairing-solo-reader-via-cloud-api),
[Reader anlegen und auflisten](https://developer.sumup.com/api/readers).
Der Installer übernimmt die Reader-ID; er führt die Kopplung nicht selbst durch.

### Betrieb und Belege

Das Solo muss online sein; SumUp empfiehlt für die Cloud API eine dauerhafte
Stromversorgung. WLAN und eine für die Cloud API geeignete Firmware/Kontofreigabe
sind nötig. [SumUp Cloud API](https://developer.sumup.com/terminal-payments/cloud-api)

Die Anwendung nutzt den PDF-Beleg zum Drucken am Praxisdrucker. Einen automatischen
Druckdialog am Solo oder dessen Druckstation löst sie nicht aus. E-Mail-Belege werden
auf Klick über den im Installer eingerichteten SMTP-Server verschickt.

Die Anwendung sendet ausschließlich Betrag, EUR, neutrale Referenz und erforderliche
technische Affiliate-Metadaten an SumUp. Patient und Leistungsbezeichnungen werden nie
an SumUp gesendet. Keine Kartendaten werden lokal gespeichert.

## Fehler und erneute Versuche

- Startanfragen mit derselben lokalen ID geben den bestehenden Zahlungsversuch zurück.
  Eine neue ID startet ebenfalls keine zweite Zahlung für denselben offenen Vorgang.
- Solange ein Terminalvorgang ungeklärt ist, startet die Anwendung dort keine neue Zahlung.
- Ein Timeout ist kein Zahlungsfehlschlag. Die Seite gleicht über die gespeicherte
  SumUp-ID oder die vor dem Start gespeicherte neutrale Referenz ab.
- Erfolg wird über den Transaktionsstatus mit passendem Händler, Referenz, Betrag und EUR
  bestätigt. Ein angenommener Abbruchauftrag allein gilt noch nicht als Abbruch.
- FHIR-Schreibzugriffe erfolgen ausschließlich durch den Dokumentationsbutton; Polling schreibt nie.
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

Bei der ersten Einrichtung der t2med-Anbindung übernimmt eine leere Eingabe beim
FHIR API-Key den hinterlegten Demo-Key. Ein eingegebener eigener Schlüssel hat Vorrang;
bei Updates behält Enter den bereits gespeicherten Schlüssel.

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

PHP 8.1+ mit `curl`, `pdo_sqlite`, `mbstring` und `sodium`; für PDFs außerdem
`rsvg-convert` (Linux: `librsvg2-bin`, macOS: Homebrew `librsvg`):

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
Ein Tag `v1.1` veröffentlicht die Pakete und Prüfsummen automatisch als GitHub Release.

## Stand der Integration

Die t2med-Profile und Header wurden aus der vorhandenen Kienzledoku-FHIR-Anbindung
übernommen; SumUp-Endpunkte aus der offiziellen Referenz (September 2026). Automatisierte
Prüfungen ersetzen keinen Test mit dem konkreten Solo und der t2med-Installation.
Insbesondere die Suche nach Zahlungskennungen und bedingtes Anlegen sind serverabhängig;
ein CapabilityStatement kann fehlen. Vor Nutzung mit echten Patienten den gesamten Ablauf
mit einem vorgesehenen Testkontext prüfen. Details der tatsächlichen Ausführung stehen in
`docs/VALIDATION.md`.
