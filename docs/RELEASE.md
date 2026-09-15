Version 1.0 der schlanken Kartenzahlungsseite für T2med und SumUp Solo.

Neu seit Version 0.1:

- Originalbeleg von SumUp als PDF anzeigen, herunterladen und drucken. Die bisherige
  zusätzliche JSON-Belegabfrage entfällt.
- PDF-Anhang auf Klick direkt per SMTP versenden; E-Mail-Adresse aus t2med wird
  vorausgefüllt und kann geändert werden. SMTP-Einrichtung erfolgt im Server-Installer.
- Verlorene Versandantworten werden berücksichtigt; ein bewusster erneuter Versand
  ist möglich. Kein Hintergrundversand und keine automatische Aktendokumentation.
- t2med-Demo-Key als Vorgabe bei leerer Ersteingabe; eigene und vorhandene Schlüssel
  haben Vorrang.
- t2med-Zertifikate ohne passenden Hostnamen können an ihren öffentlichen Schlüssel
  gebunden werden.

Weiterhin enthalten:

- Leistungen direkt auf der Zahlungsseite anlegen, ändern und löschen.
- Server berechnet Beträge in Cent und speichert die damaligen Leistungen/Preise.
- Dokumentation ausschließlich durch den Abschlussbutton, mit Wiederholungsprüfung.
- Separate Apache-/PHP-FPM-Dienste für Debian, Ubuntu und Raspberry Pi OS, HTTPS-Port 7868.
- Zentrale TOML-Konfiguration; Server-Installer fragt Zugangsdaten ab.
- Selbstsigniertes Zertifikat mit 50 Jahren Laufzeit.
- Starter für Windows, macOS und Linux mit eigenem `kienzle-sumup://`-Schema.
- Mock-Modus für die vollständige Bedienung ohne Terminal und t2med.

Für das Update im vorhandenen Repository ausführen:

```bash
git pull --ff-only
sudo bash scripts/install-server.sh
```

Der Installer ergänzt PDF-Konverter, Mailbibliothek und Versandtabelle; bestehende
Zahlungen und Konfiguration bleiben erhalten. Für den Versand die neue Frage
„PDF-Belege direkt per E-Mail senden“ mit `ja` beantworten und SMTP-Daten eingeben.

Die Binärdateien sind Starter, keine vollständige Serverinstallation. Sie benötigen die
vom Server-Installer erstellte `client.json`. Anleitung im Repository beachten.

PDF-Erzeugung, E-Mail-Anhang, Wiederholungen und Browserbedienung wurden mit
kontrollierten Testdaten geprüft. Ein echter SMTP-Versand mit den Zugangsdaten des
Anwenders wurde im Entwicklungssystem nicht durchgeführt.
