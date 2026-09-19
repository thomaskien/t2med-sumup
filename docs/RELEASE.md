Version 1.4 der Kartenzahlungsseite für T2med und SumUp Solo.

- Einzelleistungen mit frei festgelegtem Preis sowie optionaler GOÄ-Ziffer und
  Steigerungsfaktor direkt auf der Webseite pflegen. Der Faktor verändert den Preis nicht.
- Kombinationen aus vorhandenen Leistungen anlegen und auswählen. Gemeinsame Positionen
  wie eine Blutentnahme werden auch über mehrere Kombinationen hinweg nur einmal berechnet.
- Lokaler Leistungsbeleg bzw. GOÄ-Rechnung mit Praxis- und Patientendaten, Leistungsdatum,
  Positionen, Faktoren, Begründungen und Gesamtsumme. Darunter die SumUp-Zahlungsbestätigung.
- Als Rechnungsnummer dient die SumUp-Transaktions-ID. Sie erscheint auch im manuellen
  Akteneintrag. Medizinische Leistungsdaten bleiben bei der Zahlungsabwicklung lokal.
- PDF, E-Mail-Anhang und optionaler 58-mm-Direktdruck verwenden denselben lokalen Beleg.
  Belegdaten werden beim Zahlungsstart fest gespeichert und durch spätere Änderungen
  nicht verändert. Bestehende Zahlungen behalten ihren bisherigen Beleg.
- Installer und TOML enthalten die Praxisdaten. Starter und Download-URLs auf Version 1.4.

Update auf dem Anwendungsserver:

```bash
git pull --ff-only
sudo bash scripts/install-server.sh
```

Im Installer **Praxis / Rechnungsaussteller**, Straße und Hausnummer sowie PLZ und Ort
angeben. Der Kontakt ist optional. Die gleichen Felder stehen unter `[practice]` in
`/etc/kienzle-sumup/kienzle-sumup.toml`. Danach die Zahlungsseite neu laden.

Bestehende Einstellungen, Leistungen und Zahlungen werden erhalten. Der Installer
aktualisiert die Datenbank. Die Binärdateien sind Starter und benötigen die vom
Server-Installer erstellte `client.json`.
