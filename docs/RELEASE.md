Version 1.2 der Kartenzahlungsseite für T2med und SumUp Solo.

- Optionaler großer Button **Beleg drucken** nach erfolgreicher Zahlung: SumUp-Originalbeleg
  direkt auf einen Epson TM-m10 mit 58-mm-Rolle drucken, über eine Samba-RAW-Freigabe
  ohne Passwort. Nachdruck ist auf Wunsch möglich.
- Installer und TOML konfigurieren Aktivierung, Freigabe, Druckbreite (420 Punkte)
  und Schnitt. Bei neuen Installationen ist der Direktdruck ausgeschaltet.
- Wiederholte Auftragskennungen verhindern eine doppelte Übergabe nach verlorener
  Browserantwort. Konkrete Samba-Fehlercodes erscheinen in Oberfläche und Fehlerlog.
- README und Druckeranleitung enthalten die vollständige erforderliche Samba-Konfiguration,
  insbesondere **guest ok = yes** und **disable spoolss = no**, sowie die klare Zuordnung
  der Einstellungen zum Druckserver (kienzlebox) oder Anwendungsserver (t2medtest).
- Der Anwender hat den funktionierenden Direktdruck nach Aktivierung von spoolss bestätigt.
- Starter für Windows, macOS und Linux mit Versionsangabe 1.2 und Prüfsummen.

Update auf dem Anwendungsserver:

```bash
git pull --ff-only
sudo bash scripts/install-server.sh
```

Bestehende Konfiguration und Zahlungen bleiben erhalten. Im Installer bei Bedarf
**Direktdruck auf Epson TM-m10 über Samba aktivieren** mit **ja** beantworten.
Vorgaben: `//kienzlebox/TMm10`, 420 Punkte, Schnitt aktiviert.

Zusätzlich auf dem Druckserver die vorhandenen Samba-Abschnitte in
`/etc/samba/smb.conf` ergänzen oder korrigieren; andere Freigaben beibehalten:

```ini
[global]
    printing = cups
    printcap name = cups
    map to guest = Bad User
    disable spoolss = no

[TMm10]
    comment = Epson TM-m10 Bluetooth
    path = /var/spool/samba
    printable = yes
    browseable = yes
    read only = yes
    guest ok = yes
    printer name = TMm10
    cups options = raw
```

Spool-Verzeichnis und CUPS-RAW-Warteschlange müssen auf dem Druckserver eingerichtet
sein, wie in der Druckeranleitung beschrieben. Nach Speichern dort ausführen:

```bash
sudo testparm -s && sudo systemctl restart smbd
```

PHP-Kernprüfungen, ESC/POS-Rasterprüfung und Browserprüfung sind bestanden.
Die automatisierten Prüfungen simulieren Druckübergaben. Die Bestätigung des echten
Ausdrucks stammt vom Anwender.

Die Binärdateien sind Starter und benötigen die vom Server-Installer erstellte
`client.json`. Hinweise zur Einrichtung stehen in der README.
