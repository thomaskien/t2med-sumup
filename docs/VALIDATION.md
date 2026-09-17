# Prüfungen

Stand: 17. September 2026. Die ursprünglichen Starter-/Installationsprüfungen
vom 13. September sind unten gesondert aufgeführt.

Version 1.3: `php tests/reader-cancel.php` und die bestehenden PHP-Kernprüfungen
bestanden. Mit simulierten SumUp-Antworten geprüft: anderer offener Vorgang,
frischer Statusabgleich vor dem Abbruch, inzwischen erfolgreiche Zahlung,
verlorene Abbruchantwort mit Wiederholung, verzögerte Checkout-Bestätigung und
veralteter Klick nach Start einer neuen Zahlung. Fehlende Statusbestätigung gibt
das Terminal nicht frei. Die Übersicht enthält nur Betrag, neutrale Referenz und
technischen Status; vollständige fremde Zahlungs- und Patientendaten bleiben gesperrt.

Im separaten Chrome-Testbrowser mit zwei simulierten Vorgängen geprüft: sichtbarer
Abbruchknopf, CSRF-Ablehnung, Beibehalten des ausgewählten Betrags, automatische
Statusklärung nach verlorener HTTP-Antwort und Neustart nur durch bewussten Klick.
Das ursprüngliche Fenster zeigt den bestätigten Abbruch. Desktop- und Mobilansicht
visuell kontrolliert, keine JavaScript-Fehler. Dabei wurde kein echter Terminalvorgang
abgebrochen und keine Patientenakte beschrieben.

Ergänzung zur Druckdiagnose: `php tests/critical.php` bestanden. Technische
Samba-Statuscodes werden sowohl aus stdout als auch stderr übernommen; bei
fehlendem Statuscode erscheint der Prozess-Exit-Code. Die Prüfung verwendet lokale
Testprozesse ohne Netzwerk. Rohantworten gelangen weder in die Oberfläche noch ins
Anwendungslog, und ein erfolgreicher Aufruf bleibt trotz stderr-Hinweis erfolgreich.

Ergänzung für den optionalen Direktdruck: PHP-Kernprüfungen und
`tests/print-format.php` bestanden. Geprüft sind Migration bestehender Daten,
Zahlungszuordnung, Druck erst nach erfolgreicher Zahlung, wiederholte Auftragskennung,
verlorene Übergabeantwort und bewusster Nachdruck. Zahlung und Aktendokumentation
bleiben dabei unverändert. Die Rasterprüfung kontrolliert proportionale Skalierung
auf 420 Punkte, Epson-Grafikstreifen, Pixelreihenfolge, weiße Füllbits und genau einen
abschließenden Schnitt beziehungsweise Vorschub bei deaktiviertem Schnitt.

Die Konfigurationsabfrage wurde in temporären Verzeichnissen mit echter TOML-Prüfung
ausgeführt: neue Installation standardmäßig ohne Druck, UNC-Freigabe, Aktivieren,
Deaktivieren, Wiederaktivieren mit vorhandenen Werten und ungültige Eingaben.
Im separaten Chrome-Testbrowser geprüft: Button nur nach Zahlungserfolg und bei
aktivierter Funktion, manueller Druck, Doppelklickschutz, Nachdruck, verlorene
HTTP-Antwort mit Neuladen und Wiederholung desselben Auftrags sowie Deaktivierung.
Desktop- und Mobilansicht wurden visuell kontrolliert. Dabei liefen nur simulierte
Druckaufträge; die Druckerfreigabe wurde dabei nicht angesprochen.

Praxisbestätigung für Version 1.2 am 17. September 2026: Der Anwender hat den
funktionierenden Direktdruck nach Korrektur der Samba-Konfiguration bestätigt.
`guest ok = yes` beseitigte die Zugriffsverweigerung an der Freigabe; anschließend
behob `disable spoolss = no` mit Samba-Neustart den Fehler
`Could not connect to spoolss pipe: NT_STATUS_OBJECT_NAME_NOT_FOUND` beim Anlegen
des Druckauftrags. Die CUPS-RAW-Warteschlange TMm10 verwendet
`serial:/dev/rfcomm0?baud=115200+parity=none+flow=none`. Der Druck vom Mac inklusive
Schnitt war laut Anwender bereits zuvor erfolgreich. Diese Geräteprüfung stammt
vom Anwender, nicht aus den automatisierten Tests.

Ergänzung für Version 1.1: PHP-Kernprüfungen und Syntaxprüfungen bestanden. Die neue
Adressabfrage prüft die Zahlungs-/Patientenzuordnung und ruft SumUp nicht auf.
Im separaten Chrome-Testbrowser geprüft: Mail-Button erst nach Zahlungserfolg,
automatisch geladene Adresse ohne Versand, volle Buttonbreite direkt unter dem
PDF-Button, genau eine Versandanfrage nach einem Klick und sichtbare Bestätigung.
Fehlende und manuell geänderte Adressen sowie eine lange Adresse bei 390 Pixeln
Bildschirmbreite sind geprüft. Desktop- und Mobilansicht wurden visuell kontrolliert.
Es wurden ausschließlich Testdaten verwendet und keine echten E-Mails versendet.

PDF-/SMTP-Erweiterung vom 15. September 2026: PHP-Kernprüfungen und Syntaxprüfungen
bestanden. Geprüft sind Originalbeleg-Abruf über einen erlaubten SumUp-Link der
zugeordneten Transaktion, PDF-Erzeugung mit librsvg, erneuter Abruf nach Fehlern,
bevorzugte E-Mail-Adresse aus FHIR und unveränderte Zahlungs-/Dokumentationsdaten.
Die Datenbankmigration erhält bestehende Zahlungen. Kontrollierte Mailer-Antworten
prüfen die Wiederholung desselben Versandschlüssels, eine verlorene Versandantwort
und einen bewusst neu gestarteten Versand. `tests/mail-mime.php` bestätigt mit
PHPMailer 6.9.3 den richtigen Empfänger, Betreff und unveränderten PDF-Anhang, ohne
eine SMTP-Verbindung aufzubauen.

Im separaten Chrome-Testbrowser geprüft: Belegbutton erst nach Zahlungserfolg,
PDF-Antwort und sichtbarer PDF-Inhalt im Browser, Download, E-Mail-Vorschlag,
simulierter Versand und Abweisung ohne Browsersitzung. Die erzeugte Test-PDF wurde
zusätzlich mit Poppler gerendert und visuell kontrolliert. Es wurden keine echten
E-Mails versendet. Original-SVG des konkreten SumUp-Kontos und SMTP-Versand mit den
Zugangsdaten des Anwenders müssen dort noch geprüft werden. Der Anwender bestätigt
bereits den funktionierenden SVG-Download über den SumUp-Beleglink.

Ergänzung vom 15. September 2026: `python3 tests/tls-pin.py` mit echten lokalen
TLS-Verbindungen bestanden. Ein selbstsigniertes V1-Zertifikat ohne Erweiterungen/SAN
wird nur bei aktivierter Schlüsselbindung akzeptiert. Ein anderer öffentlicher Schlüssel
trotz vertrauenswürdiger Zertifikatskette wird mit cURL 90 vor HTTP-Datenübertragung
abgewiesen; fehlende Zertifikatsdatei und normale Hostnamenprüfung lehnen sicher ab.
Die bestehenden PHP-Kernprüfungen sind ebenfalls bestanden.

Lokal unter macOS ausgeführt:

- PHP-Syntaxprüfung und `php tests/critical.php` mit PHP 8.5.8: bestanden.
- `go test ./...` mit Go 1.26.5: bestanden.
- Starter für Windows amd64/arm64, macOS amd64/arm64 und Linux amd64/arm64/ARMv6 gebaut.
- Shell-Syntax der Installer und Python-Syntax der Konfiguration geprüft.
- Oberfläche im Browser: Testpatient geöffnet, Leistung angelegt, Leistung plus sonstiger
  Betrag korrekt summiert, Zahlung simuliert, Dokumentationsfehler simuliert, erneut
  dokumentiert und Abschlussmeldung bestätigt.

Die wenigen automatisierten Ablaufprüfungen verwenden SQLite und kontrollierte
Schnittstellenantworten. Sie prüfen serverseitige Beträge, unveränderliche Preisstände,
Sitzungszuordnung, Doppelstarts, verlorene Startantworten, fehlende Übereinstimmung des
Zahlungsbetrags und wiederholbare Dokumentation. Polling erzeugt keinen Akteneintrag.

Der Installer-/HTTPS-Test auf Ubuntu 24.04 wurde in GitHub Actions erfolgreich ausgeführt:
Installation, eigener Apache-Dienst, eigener PHP-FPM-Dienst und Abruf der Zahlungsseite
mit geprüftem Serverzertifikat. GitHub Actions wiederholt diesen kurzen Test sowie die
Kernprüfungen bei Änderungen. Die Ergebnisse stehen im Actions-Bereich des Repositories.

Noch nicht mit echten Geräten/Zugangsdaten geprüft: SumUp Solo, die konkrete
t2med-Installation sowie Installation/Protokollstart auf Windows und Linux bzw. macOS.
Die Starter sind für diese Plattformen gebaut; ein erfolgreicher Build ist kein
Nachweis einer dort ausgeführten Installation. Debian und Raspberry Pi OS wurden
nicht separat gebootet. Vor dem Praxisbetrieb ist ein gemeinsamer Live-Durchlauf nötig.
