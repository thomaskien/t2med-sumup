# Prüfung von Version 0.1

Stand: 13. September 2026.

Belegerweiterung vom 15. September 2026: PHP-Kernprüfungen und Syntaxprüfungen
bestanden. Ergänzt sind Belegzuordnung nach Transaktion, Händler, Betrag und Status,
wiederholter Abruf ohne neue Zahlung/Aktendokumentation, erlaubte SumUp-Beleglinks
und bevorzugte E-Mail-Adresse aus FHIR. Im separaten Chrome-Testbrowser geprüft:
Button erst nach Erfolg, eigenes Druckfenster, Testbeleg-Kennzeichnung auch im
Drucklayout, Abweisung ohne Browsersitzung sowie Linkanzeige und E-Mail-Vorschlag.
Browser-Linkdaten waren kontrollierte Testantworten. Es wurden keine E-Mails
versendet und keine echten Beleglinks geöffnet. Tatsächliche Bereitstellung eines
Beleglinks und Live-Belegdaten des konkreten SumUp-Kontos müssen beim Anwender
geprüft werden; es werden keine Links aus Transaktions-IDs erfunden.

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
