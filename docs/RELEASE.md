Erste Version der schlanken Kartenzahlungsseite für T2med und SumUp Solo.

- Leistungen direkt auf der Zahlungsseite anlegen, ändern und löschen.
- Server berechnet Beträge in Cent und speichert die damaligen Leistungen/Preise.
- Dokumentation ausschließlich durch den Abschlussbutton, mit Wiederholungsprüfung.
- Separate Apache-/PHP-FPM-Dienste für Debian, Ubuntu und Raspberry Pi OS, HTTPS-Port 7868.
- Zentrale TOML-Konfiguration; Server-Installer fragt Zugangsdaten ab.
- Selbstsigniertes Zertifikat mit 50 Jahren Laufzeit.
- Starter für Windows, macOS und Linux mit eigenem `kienzle-sumup://`-Schema.
- Mock-Modus für die vollständige Bedienung ohne Terminal und t2med.

Die Binärdateien sind Starter, keine vollständige Serverinstallation. Sie benötigen die
vom Server-Installer erstellte `client.json`. Anleitung im Repository beachten.

Echte Terminal- und t2med-Tests erfordern die Zugangsdaten und die Praxisumgebung.
