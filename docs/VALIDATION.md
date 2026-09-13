# Prüfung von Version 0.1

Stand: 13. September 2026.

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
