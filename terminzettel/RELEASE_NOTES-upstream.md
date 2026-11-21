# Version 1.3.1

Behebt abgeschnittene Wochentage bei Windows-Druckaufträgen über IPP: Der virtuelle Drucker meldet jetzt **0 mm Druckrand** für die vollständige Eingangsseite. Der bisherige Rand von 6,35 mm konnte den Anfang von „Mo.“ abschneiden und dadurch die Terminverarbeitung verhindern. Auch der Mac-PDF-Treiber enthält die korrigierten Randangaben.

Die vorhandene lokale OCR bleibt erhalten. Ohne den fehlerhaften Beschnitt wurden im untersuchten Windows-Auftrag alle vier Termine einschließlich Name, Datum, Uhrzeit und Terminart korrekt gelesen. Beschädigte Terminzeilen mit unbekanntem Wochentag werden abgewiesen, damit bei mehreren Terminen keine Zeile unbemerkt entfällt.

## Installation / Update

Auf dem Raspberry Pi:

```bash
cd ~ &&
curl -fL https://github.com/thomaskien/t2med-terminzettel/archive/refs/tags/v1.3.1.tar.gz -o terminzettel-1.3.1.tar.gz &&
tar -xzf terminzettel-1.3.1.tar.gz &&
cd t2med-terminzettel-1.3.1/terminzettel-installer &&
sudo ./install-terminzettel.sh
```

Als root kann `sudo` entfallen. Die vorhandene TOML wird übernommen.

**Unter Windows anschließend den Drucker „Terminzettel“ entfernen und erneut hinzufügen**, damit Windows die neuen Druckeigenschaften übernimmt. Adresse: `http://kienzlebox:631/printers/Terminzettel`, Treiber: **Microsoft IPP Class Driver**. Bei abweichendem Rechnernamen diesen ersetzen. T2med danach neu öffnen und einen echten Terminzettel drucken; eine normale Drucker-Testseite enthält keine verwertbaren Termine.

Auf dem Mac den aktualisierten PDF-Treiber wie in der [README](https://github.com/thomaskien/t2med-terminzettel#drucker-auf-dem-mac-hinzufügen) beschrieben übernehmen.

## Prüfung

145 lokale Tests: 141 bestanden, vier wegen lokal fehlender oder nicht ausführbarer OCR-/PostScript-Werkzeuge übersprungen. Die betroffenen OCR- und Konvertierungswege wurden zusätzlich auf der kienzlebox geprüft. Beide PPDs bestehen die CUPS-Prüfung; die korrigierte Warteschlange meldet dort für alle vier Ränder 0 mm. GitHub veröffentlicht diese Version erst nach erfolgreichen Python- und CUPS-Integrationstests.

Die Bestätigung eines vollständigen Windows-Ausdrucks nach dem erneuten Hinzufügen des Druckers steht noch aus; der Abgleich ohne Beschnitt am erfassten Auftrag war erfolgreich. Details stehen im [Teststatus](https://github.com/thomaskien/t2med-terminzettel/blob/v1.3.1/TESTSTATUS.md).
