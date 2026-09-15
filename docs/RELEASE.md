Version 1.1 der Kartenzahlungsseite für T2med und SumUp Solo.

- Großer Button **Beleg mailen an „emailadresse“** direkt unter **Zahlungsbeleg / PDF**.
  Die Adresse wird nach erfolgreicher Zahlung automatisch aus t2med geladen;
  ein Klick versendet den PDF-Anhang über den eingerichteten SMTP-Server.
- Fehlt die Adresse, öffnet der Button die Eingabe. Eine vorhandene Adresse kann über
  **Beleglink / E-Mail-Adresse ändern** angepasst werden.
- README mit Video-Vorschau sowie Anleitung für SumUp API-Key, Affiliate-Key, App-ID,
  Händlercode, Solo-Kopplung und Reader-ID.
- Starter für Windows, macOS und Linux mit Versionsangabe 1.1 und Prüfsummen.

Update auf dem Server:

```bash
git pull --ff-only
sudo bash scripts/install-server.sh
```

Bestehende Konfiguration und Zahlungen bleiben erhalten. Für den E-Mail-Versand muss
im Installer **PDF-Belege direkt per E-Mail senden** aktiviert und SMTP eingerichtet sein.

PHP-Kernprüfungen sowie die Browserprüfung für Ein-Klick-Versand, fehlende/geänderte
Adressen und schmale Bildschirme sind bestanden. Beim Test wurde der Versand simuliert;
es wurden keine echten E-Mails versendet.

Die Binärdateien sind Starter und benötigen die vom Server-Installer erstellte
`client.json`. Hinweise zur Einrichtung stehen in der README.
