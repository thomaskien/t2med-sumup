Version 1.4.1-rechnung.55 der Kartenzahlungsseite für T2med und SumUp Solo (Fork).

- Neu: T2med-Rechnung kassieren. Eine auf den virtuellen Drucker „SumUp-Rechnung“
  gedruckte Liquidation erscheint beim passenden Patienten als offene Rechnung und wird
  mit einem Klick am Solo kassiert. Rechnungsnummer als SumUp-Beschreibung, keine
  Patientendaten an SumUp, Akteneintrag mit Rechnungsbezug.
- Neu in rechnung.55: Terminzettel – Reihenfolge „erste Zeile, dann Adressbereich“, Änderungen daran verlangen auf gemerkten Geräten kein Passwort mehr. Update-Glocke grau, solange alles aktuell ist; roter Punkt bei neuer Version im abonnierten Kanal; ganz rot, wenn im Hauptzweig (stabil) eine neuere Version liegt. Dazu README mit Ablauf-Überblick, Hardware und Erstinstallation, Anleitung „T2med einrichten“, Installer-Anpassungen für Ubuntu 26.04 und Parallelbetrieb (AppArmor, Paket-Apache), MIT-Lizenz.
- Neu in rechnung.54: Der Beta-Kanal wird per Kästchen „Beta-Versionen abonnieren“ gewählt; ohne Häkchen kommen Updates aus dem ursprünglichen Projekt (stabil).
- Neu in rechnung.53: Der Fork heißt jetzt kienzle-suite-beta (hau-si/kienzle-suite-beta). Der Beta-Kanal liest die neue Adresse; .
- Neu in rechnung.52: Zwischenversion für die Umbenennung des Forks (kienzle-suite-beta): Die Update-Prüfung folgt GitHub-Weiterleitungen, damit Aktualisierungen auch nach dem Umbenennen des Repositorys ankommen.
- Neu in rechnung.51: „Dieses Gerät merken“ bei der Anmeldung (bis zu 6 Monate, nur aus dem Netz der Anmeldung, in den Einstellungen einsehbar und widerrufbar); heikle Änderungen (Einstellungen speichern, Update, Firewall, Zugang) verlangen auf gemerkten Geräten das Passwort erneut; Pakete mit Schlüsseln nur ohne Gerät merken (Datenbankversion 18).
- Neu in rechnung.50: Update-Kanal wählbar: „Stabil“ (Hauptzweig thomaskien/t2med-sumup, Standard bei Neuinstallation) oder „Beta“ (dieser Fork). Bestehende Installationen von diesem Fork bleiben zunächst auf Beta.
- Neu in rechnung.49: Abmeldezeiten bis 6 Monate einstellbar (Minuten, Stunden oder Tage).
- Neu in rechnung.48: Terminzettel – erste Zeile des Starttexts (z. B. Praxisname) mit eigener Schriftgröße und fett/nicht fett einstellbar.
- Neu in rechnung.47: Abmeldezeit (Inaktivität und Höchstdauer) einstellbar; Mitarbeiter-Zugang mit eigenem Passwort und ankreuzbaren Rechten (Bezahlt-Liste, Rechnungseingang, Post-Versand, Leistungen), ohne Zugriff auf Schlüssel und Einstellungen; Update-Glocke immer sichtbar; Datenbankversion 17.
- Neu in rechnung.46: T2med-Rechnungen werden bei Karte und Bar immer auf dem Praxisdrucker gedruckt (abschaltbar in den Einstellungen, `office_printing.invoice_auto`) und bei „Später (Überweisung)“ einmal; Hilfesymbol bei „Leistung auf Verlangen“ (Datenbankversion 16).
- Neu in rechnung.45: Leistungen mit GOÄ-Ziffer können eine feste Begründung für Faktoren über dem Schwellenwert hinterlegen (Kasse und Adminpanel); sie wird vorausgefüllt und bei fehlender Eingabe verwendet (Datenbankversion 15).
- Neu in rechnung.44: Letterxpress: Eine live gesendete Rechnung lässt sich nicht mehr ein zweites Mal senden (kein „Nochmal“); ein kleines Brief-Symbol zeigt den Versand. Einrichtungs-Überblick nennt auch die Ablage im Netzwerkordner (optional).
- Neu in rechnung.43: Einrichtungs-Überblick nennt die Letterxpress-Anbindung (optional); der PowerShell-Befehl für „SumUp-Rechnung“ stellt das Papier gleich auf A4.
- Neu in rechnung.42: Letterxpress schlanker: A4-Umwandlung, Vorabprüfung und PDF-Diagnose wieder entfernt, nur das Entfernen des Rechteschutzes bleibt. Hinweis: Das Papierformat des Windows-Druckers „SumUp-Rechnung“ muss A4 sein.
- Neu in rechnung.37: Rechnungseingang mit Auswahlkästchen: mehrere Rechnungen markieren und gesammelt per Post senden oder löschen (höchstens 50, mit Bestätigung).
- Neu in rechnung.35: Letterxpress: PDFs mit Rechteschutz werden vor dem Senden mit qpdf entfernt (qpdf kommt mit dem Server-Installer); verständliche Meldungen bei fehlender Anschrift bzw. verschlüsseltem PDF.
- Neu in rechnung.34: Rechnung per Post über Letterxpress (Einstellungen, Knopf auf Kasse und Rechnungseingang, Testmodus, Doppelsendeschutz).
- Ebenfalls rechnung.34: Die Aktualisierung steht wieder unter Einstellungen → Einrichtung (Block „Aktualisierung“); die Glocke
  rechts in der Reiterleiste bleibt und öffnet ihn. Bar bezahlte Rechnungen werden mit „_Bar“ statt „_Karte“ im Netzwerkordner abgelegt.
- Neu in rechnung.33: Offene T2med-Rechnung mit „Bar bezahlt“ (sofort erfasst, Akteneintrag, Liste/Excel mit
  Zahlungsart) und „Später (Überweisung)“; Quittung für Barzahlung auf dem Bondrucker, automatisch einstellbar
  (`printing.cash_receipt_auto`); neue Rechnungen erscheinen ohne Neuladen. Einstellungsseite aufgeräumt:
  Aktualisierungs-Leiste oben mit Glocke, Reiter Abrechnung/Leistungen/Einrichtung, Einrichtung in einklappbaren
  Blöcken (Terminzettel eigenständig). README-Screenshots erneuert.
- Neu in rechnung.32: PHPMailer 6.9.3 wird mitgeliefert (`src/vendor/phpmailer`, nur Exception/PHPMailer/SMTP, LGPL 2.1) und dient als Rückfall,
  wenn das Systempaket `libphp-phpmailer` fehlt; das Systempaket hat Vorrang. Der Installer richtet `unattended-upgrades`
  ein, falls keine automatischen Sicherheitsupdates konfiguriert sind. `tests/mail-mime.php` läuft damit auch ohne Systempaket.
- Neu in rechnung.31: Leistungsverwaltung (Leistungen, GOÄ-Angaben, Rechner, Kombinationen) auch im Adminpanel, Reiter „Praxis & Rechnungen“.
  Gemeinsame Logik in `src/Catalog.php`, gemeinsamer GOÄ-Rechner in `public/assets/goae-calc.js`.
- Neu in rechnung.30: README mit Funktionsübersicht und aktuellen Screenshots (Testdaten, `docs/screenshots/`).
- Neu in rechnung.29: Rechnungseingang zeigt Name und Geburtsdatum und erlaubt das Löschen nicht bezahlter Rechnungen.
  Bezahlte T2med-Rechnungen lassen sich nachträglich stornieren oder im Betrag korrigieren (Statistik und Excel;
  Original bleibt erhalten, Datenbank Version 13).
- Neu in rechnung.28: Einrichtungs-Überblick: Der Drucker „SumUp-Rechnung“ wird unter „Auf den Client-PCs“ installiert; in T2med folgt erst danach die Auswahl bzw. das Drucken auf den installierten Drucker.
- Neu in rechnung.27: Druckeranleitungen zeigen die erkannten IP-Adressen des Servers; die PowerShell-Befehle sind damit sofort kopierbar (z. B. für Zugriffe aus dem VPN).
- Neu in rechnung.26: PowerShell-Anleitung: bereits vorhandener Druckeranschluss ist kein Fehler mehr.
- Neu in rechnung.25: Anleitung für Windows 11 mit PowerShell-Befehlen, um den „Microsoft IPP Class Driver“ (Internet-Druckclient) zu aktivieren und den Drucker anzulegen.
- Neu in rechnung.24: Anleitung „Drucker auf den Arbeitsplätzen hinzufügen“ um Hinweise für andere Netze (VPN) ergänzt.
- Neu in rechnung.23: Tooltexte: In T2med unter Einstellungen → Kalender → Terminliste (DIN A6) den Drucker „Terminzettel“ wählen.
- Neu in rechnung.22: Rechnungserkennung robuster: eigene Textschnipsel je Feld (Nummer, Datum, Gesamtsumme, Überweisungsbetrag, Patient),
  mehr eingebaute Bezeichnungen, zweiter Leseversuch in Lesereihenfolge. Neue Karte „Rechnungseingang“ (Abrechnung) mit Status
  der Rechnungen und abgelehnten Drucken samt maskierter Zeilenübersicht (keine Namen, Daten oder PDFs).
- Neu in rechnung.21: Bild-PDFs ohne Textebene werden vor dem Lesen per Texterkennung (Tesseract, Deutsch) umgewandelt;
  der Installer richtet `tesseract-ocr` ein. Erkannter Text gilt nur mit bestätigtem Zahlbetrag. Der Rechnungsleser meldet jetzt genau, was fehlt („nicht gefunden“ mit der gesuchten Bezeichnung
  oder „mehrere verschiedene Werte“), statt nur „nicht eindeutig lesbar“.
- Neu in rechnung.19: GOÄ-Rechner trifft den gewünschten Endbetrag immer genau: Der Faktor bekommt bei Bedarf eine
  3. oder 4. Nachkommastelle (10,72 € → 25,00 € = Faktor 2,332). Bis 100 € Einfachsatz ist jeder Betrag möglich.
  Faktor wird mit allen Stellen gespeichert, geprüft, auf Belegen ausgegeben; Begründungspflicht über dem
  Schwellenwert gilt auch bei 2,301. Rechner im Browser und auf dem Server liefern dieselben Faktoren (Test).
- Neu in rechnung.18: Einrichtungs-Überblick in drei Teile gegliedert: „In diesem Webfenster“, „Auf den Client-PCs“
  und „In T2med“ (FHIR-Schnittstelle, Button „Kienzle-SumUp“, Einstellungen → Kalender → Terminliste (DIN A6) mit Drucker „Terminzettel“,
  Drucker „SumUp-Rechnung“). Bei der T2med-Anbindung steht jetzt die Aufruf-Adresse für den Button zum Kopieren.
- Neu in rechnung.17: Fehlerbehebung Aktualisierung: Der Installer meldete „Port 7868 ist bereits belegt“, obwohl nur
  kürzlich geschlossene Verbindungen des gestoppten Dienstes (TIME_WAIT) den Port hielten. Die Portprüfung
  verwendet jetzt SO_REUSEADDR und erkennt weiterhin ein wirklich lauschendes Programm.
- Neu in rechnung.16: Firewall kennt die Dienste der Kienzle-Projekte (Middleware 8766, AMI 5038, SIP 5060,
  RTP, Kienzlefon-Weboberfläche 8443/8088, WireGuard 51820, App 80/443) und beliebige eigene Ports; lauschende
  Dienste werden vorgewählt, vor „Alles andere sperren“ warnt die Seite vor betroffenen Ports.
- Neu in rechnung.15: Netzwerk und Firewall im Webinterface: Server erkennt Netze, Router, bekannte Geräte und
  offene Ports, Zugriff nur aus gewählten privaten Netzen (Weboberfläche, SSH, Druckfreigabe). Schutz vor
  Aussperren durch Prüfung des eigenen Geräts und automatisches Zurücksetzen ohne Bestätigung. Root-Dienst mit
  eigener nftables-Tabelle; Installer ergänzt `nftables` und `iproute2`.
- Neu in rechnung.14: Härtung: Sperre bei Fehlversuchen je Absender statt für alle, Gesamtbremse, Sitzungen
  höchstens 12 Stunden, schwache Passwörter abgelehnt, Drosselung falscher Starter- und Druckserver-Schlüssel,
  Sicherheitsprotokoll in den Einstellungen, Anfragen anderer Websites abgewiesen, zusätzliche Schutz-Header,
  Webserver gegen langsame Verbindungen, strengere TLS-Verfahren, Systemd-Sandbox für Webserver und PHP,
  gefährliche PHP-Funktionen abgeschaltet, Größenbegrenzung beim Update-Download.
- Neu in rechnung.13: Aktualisierung im Webinterface: neue Version auf GitHub erkennen (Hinweis oben,
  Neuerungen aus RELEASE.md), mit einem Klick installieren. Ein root-Dienst lädt den eingestellten
  Zweig, sichert Programm und Datenbank, führt den Installer aus und stellt bei Fehlern den vorherigen
  Stand wieder her. Quelle in `/etc/kienzle-sumup/update.conf`. „Ohne Terminzettel“ bleibt bei
  Aktualisierungen erhalten.
- Neu in rechnung.12: Drucker „SumUp-Rechnung“ ohne `sudo`: Der Server-Installer legt Backend und PPD
  ab, die Einstellungsseite legt die Warteschlange beim Einschalten von „T2med-Rechnungen übernehmen“
  an bzw. entfernt sie und schreibt die Verbindungsdaten (`/etc/kienzle-sumup-printer/web`). Das
  Backend liest diese nur als echte Datei (kein Symlink). `install-invoice-printer.sh` bleibt für
  entfernte Druckserver.
- Neu in rechnung.11: Einstellungsseite in Reitern – Abrechnung (laufend), Praxis & Rechnungen,
  Drucker & Bons, Einrichtung (einmalig, mit Überblick der offenen Schritte). Zähler an den Reitern,
  Verweise springen in den passenden Reiter.
- Neu in rechnung.10: Zahlungsart Bar oder Karte im Rechnungstool (Karte vorausgewählt, ein Klick für
  Bar). Barzahlungen ohne Terminal, sofort bezahlt, eigene Rechnungsnummer `BAR-JJJJ-NNNN`, Beleg und
  Akteneintrag als Barzahlung. Export kennzeichnet „Bar“ bzw. „Kartenzahlung“, Monatssummen mit
  „davon bar“/„davon Kartenzahlung“. Datenbank-Version 12.
- Neu in rechnung.9: GOÄ-Rechner beim Anlegen von Leistungen (Einfachsatz + gewünschter Endbetrag →
  nächstliegender Faktor mit zwei Nachkommastellen, Preis = Einfachsatz × Faktor; Hinweise zu
  Schwellenwert und Höchstsatz; Serverprüfung). Liste aller bezahlten T2med-Rechnungen zum Abhaken in
  T2med (nach Rechnungsnummer, „abgehakt“ merken, Excel-Liste). Datenbank-Version 11.
- Neu in rechnung.8: Mehrwertsteuer je Leistung („mit MwSt.“, Preis bleibt Endpreis), Satz und
  Kleinunternehmerregelung (§ 19 UStG) unter Einstellungen → Umsatzsteuer, Steuernummer/USt-IdNr. und
  Hinweistexte auf der Rechnung, Netto/MwSt. je Satz unter der Summe. Der Satz wird je Zahlung
  festgehalten. Excel-Export (XLSX, ohne Zusatzpakete) aller Rechnungen aus dem Rechnungstool je Jahr
  oder Monat mit Blättern Rechnungen, Positionen und Monatssummen. Datenbank-Version 10.
- Neu in rechnung.7: T2med-Terminzettel integriert (aus thomaskien/t2med-terminzettel 1.3.1,
  Ordner `terminzettel/`), vom Server-Installer ohne Rückfragen eingerichtet. Einstellungsbereich
  „Terminzettel“: Schriftgröße getrennt für Starttext, Überschrift, Termine und Endtext, Position
  (rechts/links/unter dem Termin) und Größe des Kalender-QR, Live-Vorschau des Bons aus denselben
  ESC/POS-Daten, Testdruck. Bondrucker und Breite (TM-m10 420, TM-m30III 576 Punkte) kommen
  automatisch aus den Druckereinstellungen. Ohne Webeinstellungen druckt der Terminzettel
  byte-identisch wie bisher.
- Neu in rechnung.6: Info-Symbol mit Erklärung und Beispielwert zu jeder Einstellung und jeder
  Auswahlmöglichkeit. „Drucker einrichten“ im Browser: TM-m10, TM-m30III und A4-Netzwerkdrucker
  (IPP Everywhere) suchen, als CUPS-Warteschlange anlegen, automatisch übernehmen, testen und
  entfernen; der Installer ergänzt dafür `cups` und die Gruppe `lpadmin`. „Arbeitsplätze
  einrichten“: Installationspaket (ZIP) für Windows, macOS und Linux zum Herunterladen, mit den
  vom Server geladenen und geprüften Starterprogrammen.
- Neu in rechnung.5: Einstellungen im Browser (`/settings.php`, Link „Einstellungen“ auf der
  Kartenzahlungsseite), geschützt durch ein Admin-Passwort. Der Server-Installer stellt keine
  Fragen mehr (alte Abfragen mit `--fragen`), installiert alle Zusatzpakete und gibt einen
  einmaligen Einrichtungslink aus; `--admin-reset` erzeugt einen neuen. SumUp, T2med,
  Praxisdaten, E-Mail, Bon- und Praxisdrucker, Rechnungseingang und Netzwerkablage werden dort
  eingetragen, mit Verbindungs- und Testdruck-Knöpfen. Werte verschlüsselt in der Datenbank,
  Geheimnisse werden nie angezeigt. `install-invoice-printer.sh` holt die Daten ohne Angabe vom
  lokalen Server oder aus der heruntergeladenen Einrichtungsdatei. Datenbank-Version 9.
- Neu in rechnung.4: Häkchen „Rechnung nach der Zahlung drucken“ (praxisweit voreinstellbar,
  pro Zahlung abwählbar). T2med-Rechnungen auf dem Praxisdrucker (A4, CUPS), App-Rechnungen
  wahlweise A4 oder Bon. Bondrucker-Modell Epson TM-m30III (80 mm, 576 Punkte) und Anbindung
  über eine CUPS-RAW-Warteschlange als Alternative zu Samba. Datenbank-Version 8.
- Neu in rechnung.3: Auch die in der App erzeugten Rechnungen/Leistungsbelege werden nach
  erfolgreicher Zahlung automatisch im Netzwerkordner abgelegt (Installer-Abfrage,
  `invoice_export.app_invoices`), auch ohne Rechnungseingang. Stündlicher Timer
  `kienzle-sumup-export.timer` holt fehlgeschlagene Ablagen nach; `TEST_`-Präfix im
  Testmodus. Datenbank-Version 7.
- Neu in rechnung.2: Original-PDF der Rechnung optional per SMB in einen Netzwerkordner
  kopieren (z. B. für die Steuerberatung), wahlweise nur bezahlte (`paid`) oder jede
  gedruckte Rechnung (`received`), mit Unterordnern `{jahr}/{monat}`. Datenbank-Version 6.
- Rechnungseingang `/invoice-inbox.php` mit eigenem Schlüssel; PDF bis 5 MB.
- Strikte Prüfung von Rechnungsnummer, Datum, Gesamtsumme, Zahlbetrag, Patient und
  Geburtsdatum. Schutz vor Doppelzahlung derselben Rechnungsnummer.
- Druckserver-Installer `scripts/install-invoice-printer.sh`, CUPS-Backend und
  Mac-PDF-Treiber `macos/SumUp-Rechnung-PDF.ppd`.
- Datenbank-Version 5 (neue Tabelle `invoice_inbox`); bestehende Daten bleiben erhalten.
- Starter für die Arbeitsplätze unverändert; die Client-Installer laden weiterhin `v1.4.1`.

Update auf dem Anwendungsserver:

```bash
git pull --ff-only
sudo bash scripts/install-server.sh
```

Die neue Frage **T2med-Rechnungen über virtuellen Drucker „SumUp-Rechnung“ übernehmen**
mit `ja` beantworten, optional die Ablage im Netzwerkordner einrichten und danach auf dem Druckserver
`sudo bash scripts/install-invoice-printer.sh /root/kienzle-sumup-printer` ausführen.
