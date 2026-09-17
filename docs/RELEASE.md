Version 1.3 der Kartenzahlungsseite für T2med und SumUp Solo.

- Bei belegtem Terminal erscheint **Anderen Vorgang abbrechen** direkt in der
  Leistungsauswahl, zusammen mit Betrag, neutraler Referenz und **Status prüfen**.
- Der Zahlungsstatus wird vor dem Abbruch frisch abgeglichen. Eine bereits erfolgreiche
  Zahlung bleibt erhalten und wird weiterhin im ursprünglichen Vorgang dokumentiert.
- Nach der bestätigten Beendigung ist der Knopf zum Kassieren wieder verfügbar.
  Leistungsauswahl und Betrag bleiben erhalten; die neue Zahlung startet erst beim Klick.
- Ein angenommener Abbruchauftrag allein gibt das Terminal noch nicht frei. Die offene
  Seite prüft den Status weiter; ein erneuter Abbruchversuch ist möglich. Ein veralteter
  Klick kann keinen inzwischen neu gestarteten Vorgang abbrechen.
- Starter und Download-URLs gemeinsam auf Version 1.3 angehoben.

Update auf dem Anwendungsserver:

```bash
git pull --ff-only
sudo bash scripts/install-server.sh
```

Bestehende Konfiguration und Zahlungen bleiben erhalten. Es sind keine neuen
TOML-Einstellungen nötig. Anschließend die Zahlungsseite neu laden.

Die Binärdateien sind Starter und benötigen die vom Server-Installer erstellte
`client.json`. Hinweise zur Einrichtung stehen in der README.
