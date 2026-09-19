Version 1.4.1 der Kartenzahlungsseite für T2med und SumUp Solo.

- Optional folgt nach den Rechnungspositionen und der Gesamtsumme „Vielen Dank.“ und
  anschließend der originale SumUp-Beleg. Die lokale Zahlungsbestätigung entfällt dabei.
- Rechnung und Originalbeleg bilden einen durchgehenden Bon ohne Zwischenschnitt.
  Die vorhandene Einstellung `printing.cut` steuert ausschließlich den Schnitt am Ende.
- PDF, E-Mail-Anhang und Direktdruck verwenden dieselbe Belegvariante. Bei einem
  Abruffehler des Originals wird kein unvollständiger Beleg gedruckt oder versandt;
  ein erneuter Versuch ist möglich.
- Im Installer und unter `[practice]` mit `append_sumup_receipt = true` aktivierbar.
  Standardmäßig bleibt die Option aus. Bereits gespeicherte lokale Rechnungen lassen
  sich ebenfalls mit angehängtem Originalbeleg ausgeben; ihre Rechnungsdaten bleiben erhalten.
- Starter und Download-URLs auf Version 1.4.1 aktualisiert.

Update auf dem Anwendungsserver:

```bash
git pull --ff-only
sudo bash scripts/install-server.sh
```

Im Installer **Originalen SumUp-Beleg nach Vielen Dank statt lokaler Zahlungsbestätigung
anhängen** mit `ja` beantworten. Alternativ im vorhandenen Abschnitt `[practice]` der
Datei `/etc/kienzle-sumup/kienzle-sumup.toml` setzen:

```toml
append_sumup_receipt = true
```

Danach die Zahlungsseite neu laden. Für überhaupt keinen Schnitt zusätzlich im vorhandenen
Abschnitt `[printing]` `cut = false` setzen.

Bestehende Einstellungen, Leistungen und Zahlungen werden erhalten. Die Binärdateien
sind Starter und benötigen die vom Server-Installer erstellte `client.json`.
