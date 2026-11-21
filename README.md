# kienzle-sumup für T2med

Eine kleine PHP-Webanwendung für Kartenzahlungen am SumUp Solo. Leistungen auswählen,
Betrag ans Terminal senden und nach erfolgreicher Zahlung bewusst in der Patientenakte
dokumentieren. **Version 1.4.1-rechnung.57**, von Dr. Thomas Kienzle.

> **Fork mit T2med-Rechnungseingang:** Diese Fassung ergänzt das Kassieren einer in T2med
> erstellten Rechnung über den virtuellen Drucker **SumUp-Rechnung**, siehe
> [T2med-Rechnung kassieren](#t2med-rechnung-kassieren). Die Starter für die Arbeitsplätze
> sind unverändert und kommen weiterhin aus dem Original-Release `v1.4.1`.

## So funktioniert es

Die Suite läuft auf einem Server in der Praxis. T2med gibt Rechnungen und Patientendaten an die Suite; sie kassiert
und schreibt die Zahlung zurück in die Akte. Alle Bilder zeigen Testmodus und Beispieldaten.

<p align="center"><img src="docs/screenshots/flow-ueberblick.png" width="760" alt="Überblick des Ablaufs"></p>

**Erklärvideo** (ca. 4:40 Min., ohne Ton, nur Beispieldaten): [docs/video/Kienzle-Suite-Erklaervideo.mp4](docs/video/Kienzle-Suite-Erklaervideo.mp4)

**1. Rechnung drucken.** In T2med wie gewohnt die Rechnung erstellen und auf den Drucker „SumUp-Rechnung“ drucken. Sie landet im Rechnungseingang des Servers.

**2. Kassieren per Funktionsleiste.** Ein Klick auf die Schaltfläche beim Patienten schickt die Patientendaten per FHIR an den Server. Die Kasse öffnet sich mit der offenen Rechnung; bezahlt wird mit Karte (SumUp), bar oder „Später“.

<p align="center"><img src="docs/screenshots/t2med-8-rechnung-drucken.png" width="420" alt="Rechnung in T2med auf den Server drucken"> <img src="docs/screenshots/t2med-7-privatrechnung-drucker.png" width="330" alt="Drucker in den T2med-Einstellungen"> <img src="docs/screenshots/flow-kasse-patient.png" width="300" alt="Kasse mit offener Rechnung"> <img src="docs/screenshots/flow-beleg.png" width="300" alt="Zahlung erfolgreich mit Beleg"></p>

**3. Rückschrieb.** Die Zahlung wird per FHIR als Eintrag mit Zahlart, Betrag, Rechnungsnummer und Transaktionsreferenz in die T2med-Akte geschrieben (schematische Darstellung).

<p align="center"><img src="docs/screenshots/flow-akte.png" width="560" alt="Rückschrieb in die Akte"></p>

**4. IGeL-Tool ohne T2med-Rechnung.** Eine Rechnung in T2med ist aufwendig. Für Atteste und kurze Leistungen genügt ein Klick auf dieselbe Schaltfläche: Die Suite erkennt, dass keine T2med-Rechnung vorliegt, und zeigt die Leistungsliste zum sofortigen Kassieren.

<p align="center"><img src="docs/screenshots/flow-igel.png" width="320" alt="IGeL-Leistungen"></p>

**5. Terminzettel.** Der T2med-Zettel ist klein gedruckt und ohne QR-Code. Die Suite druckt ihn groß und gut lesbar auf dem Bondrucker, mit Praxiskopf (erste Zeile eigens gestaltbar) und Kalender-QR je Termin.

<p align="center"><img src="docs/screenshots/flow-terminzettel.png" width="220" alt="Terminzettel mit QR-Codes"></p>

**6. Per Post, Ablage, Excel.** Rechnungen lassen sich über die Letterxpress-API direkt als Brief versenden, bezahlte Rechnungen als PDF in einen Netzwerkordner für die Steuerberatung ablegen und als Excel exportieren. Einstellungen, Schlüssel und Zugangsdaten sieht nur der Admin; ein Mitarbeiter-Zugang kann auf einzelne Bereiche beschränkt werden.

Die einmalige Einrichtung in T2med (Drittanbieter-Zugriff und Funktionsleiste) ist in der
[Anleitung „T2med einrichten“](docs/T2MED-EINRICHTUNG.md) mit Bildern beschrieben; im Adminpanel ist sie im Einrichtungs-Überblick verlinkt.

<details>
<summary>Bildergalerie: die Folien aus dem Erklärvideo</summary>

**Überblick**

<img src="docs/video/s01.jpg" width="700" alt="Überblick">

**Rechnung in T2med drucken**

<img src="docs/video/s08.jpg" width="700" alt="Rechnung in T2med drucken">

**Funktionsleiste beim Patienten anklicken**

<img src="docs/video/s09.jpg" width="700" alt="Funktionsleiste beim Patienten anklicken">

**Rechnungseingang auf dem Server**

<img src="docs/video/s10.jpg" width="700" alt="Rechnungseingang auf dem Server">

**Zahlungsart wählen**

<img src="docs/video/s11.jpg" width="700" alt="Zahlungsart wählen">

**Zahlungsfenster**

<img src="docs/video/s12.jpg" width="700" alt="Zahlungsfenster">

**Beleg und Dokumentation**

<img src="docs/video/s13.jpg" width="700" alt="Beleg und Dokumentation">

**Rückschrieb in die T2med-Akte**

<img src="docs/video/s14.jpg" width="700" alt="Rückschrieb in die T2med-Akte">

**Barzahlung**

<img src="docs/video/s15.jpg" width="700" alt="Barzahlung">

**IGeL-Tool: kurze Leistungen schnell kassieren**

<img src="docs/video/s16.jpg" width="700" alt="IGeL-Tool: kurze Leistungen schnell kassieren">

**Leistungen auswählen**

<img src="docs/video/s17.jpg" width="700" alt="Leistungen auswählen">

**Abschluss mit Leistungsbeleg**

<img src="docs/video/s18.jpg" width="700" alt="Abschluss mit Leistungsbeleg">

**Leistungen und GOÄ verwalten**

<img src="docs/video/s19.jpg" width="700" alt="Leistungen und GOÄ verwalten">

**Einrichtungs-Dashboard**

<img src="docs/video/s20.jpg" width="700" alt="Einrichtungs-Dashboard">

**Terminzettel: vorher und nachher**

<img src="docs/video/s21.jpg" width="700" alt="Terminzettel: vorher und nachher">

**Terminzettel einstellen**

<img src="docs/video/s22.jpg" width="700" alt="Terminzettel einstellen">

**Briefversand über Letterxpress**

<img src="docs/video/s23.jpg" width="700" alt="Briefversand über Letterxpress">

**Letterxpress einrichten**

<img src="docs/video/s24.jpg" width="700" alt="Letterxpress einrichten">

**Netzwerkordner und Excel-Export**

<img src="docs/video/s25.jpg" width="700" alt="Netzwerkordner und Excel-Export">

**Bezahlt-Liste für T2med**

<img src="docs/video/s26.jpg" width="700" alt="Bezahlt-Liste für T2med">

**Zugang, Abmeldezeit und Mitarbeiter (nur Admin)**

<img src="docs/video/s27.jpg" width="700" alt="Zugang, Abmeldezeit und Mitarbeiter (nur Admin)">

**Updates und Beta-Kanal (nur Admin)**

<img src="docs/video/s28.jpg" width="700" alt="Updates und Beta-Kanal (nur Admin)">

</details>

## Empfohlene Hardware und Geräte

**Server:** eine virtuelle Maschine (VM) auf dem Praxisserver mit mindestens **2 Kernen** und **100 GB Speicher**.
Dazu Ubuntu Server (siehe [Erstinstallation Schritt für Schritt](#erstinstallation-schritt-für-schritt)).

**Kartenterminal:** **SumUp Solo.** Das SumUp Terminal lässt sich nicht über die API ansteuern und ist deshalb nicht nutzbar.

**Bondrucker** (Belege, Quittungen, Terminzettel), unterstützt sind zwei Epson-Modelle:

| | Epson TM-m10 | Epson TM-m30III |
|---|---|---|
| Rollenbreite | 58 mm | 80 mm |
| Rollen | Spezialrollen 58 mm | günstige 80-mm-Standardkassenrollen |
| Geschwindigkeit | langsamer | schneller |
| Anbindung | LAN/WLAN | LAN/WLAN |
| Zeichen je Zeile (Normalschrift) | 35 | 48 |

Der TM-m30III ist die Empfehlung: schneller, mehr Platz pro Zeile und die gängigen 80-mm-Rollen sind günstiger.
Beide Drucker hängen im Praxisnetz und werden per LAN oder WLAN angesprochen, nicht per USB am Server.
Einrichtung: [Bondrucker](#bondrucker-epson-tm-m10-oder-tm-m30iii).

## Funktionen im Überblick

Alle Bilder zeigen die Anwendung im Testmodus mit erfundenen Beispieldaten.

**Kassieren aus T2med**
- Leistungen und Kombinationen auswählen, Betrag am SumUp Solo kassieren (Karte) oder bar buchen,
  danach bewusst in der Patientenakte dokumentieren (FHIR, Aktenkürzel einstellbar).
- **Leistungsverwaltung im Adminpanel** (Reiter „Leistungen“): auswählbare Leistungen und Kombinationen
  anlegen, ändern und löschen, mit GOÄ-Ziffer, Gebührenrahmen und MwSt.; zusätzlich weiter an der Kasse möglich.
- **Offene T2med-Rechnung mit drei Wegen:** *Mit Karte (EC) kassieren* am Solo, *Bar bezahlt* (sofort erfasst,
  mit Akteneintrag und in der Liste der bezahlten Rechnungen) oder *Später (Überweisung)*, die Rechnung bleibt
  dann offen. Neu gedruckte Rechnungen erscheinen auf der offenen Kasse-Seite ohne Neuladen. Bei Barzahlung
  gibt es eine **Quittung** (Rechnungsnummer, Name, Datum, Betrag, „bar erhalten“) auf dem Bondrucker, auf
  Wunsch automatisch (Einstellung „Quittung automatisch drucken bei Barzahlung“ unter Bondrucker).
- **GOÄ-Angaben und GOÄ-Rechner:** Der Faktor wird so berechnet (bis zu vier Nachkommastellen), dass der
  gewünschte Euro-Betrag genau getroffen wird; Begründung ab dem Schwellenwert.
- Eigener Beleg als PDF (Rechnung oder Leistungsbeleg, Mehrwertsteuer je Leistung oder Kleinunternehmer),
  per E-Mail versendbar, auf Wunsch direkt auf dem Epson-Bondrucker (TM-m10 oder TM-m30III) oder dem
  Praxisdrucker gedruckt.
- Schutz vor Doppelzahlungen, Terminalsperre, Statusabgleich bei verlorenen Antworten.

<p align="center"><img src="docs/screenshots/01-kasse-t2med-rechnung.png" width="420" alt="Kasse mit offener T2med-Rechnung"> <img src="docs/screenshots/03-goae-rechner.png" width="420" alt="GOÄ-Rechner"></p>
<p align="center"><img src="docs/screenshots/12-leistungen-goae-verwaltung.png" width="420" alt="Leistungsverwaltung im Adminpanel"></p>

**T2med-Rechnungen kassieren** (virtueller Drucker „SumUp-Rechnung“)
- Eine in T2med erstellte Rechnung wird auf den Drucker „SumUp-Rechnung“ gedruckt, vom Server gelesen
  (Textebene, zweiter Leseversuch in Lesereihenfolge, bei Bild-PDFs Texterkennung) und beim passenden Patienten
  zum Kassieren angeboten. Die Zuordnung erfolgt über **Name und Geburtsdatum**.
- Robuste Erkennung auch für andere Rechnungsvorlagen: eigene **Textschnipsel** vor Nummer, Datum, Gesamtsumme,
  Überweisungsbetrag und Patient lassen sich einstellen. Abweichende Beträge werden abgelehnt.
- Karte **Rechnungseingang**: alle eingegangenen Rechnungen mit Status, Löschen nicht bezahlter Rechnungen und
  abgelehnte Drucke mit Grund und maskierter Zeilenübersicht für die Fehlersuche.
- Liste der bezahlten Rechnungen zum **Abhaken in T2med**, mit **nachträglicher Korrektur** (stornieren oder
  Betrag ändern, Original bleibt erhalten) und Excel-Export.

<p align="center"><img src="docs/screenshots/04-rechnungseingang.png" width="420" alt="Rechnungseingang"> <img src="docs/screenshots/05-bezahlte-rechnungen-korrektur.png" width="420" alt="Bezahlte Rechnungen mit Korrektur"></p>

**Buchhaltung und Drucken**
- Excel-Export für die Steuerberatung (Jahr oder Monat, Brutto/Netto/MwSt., Barzahlungen gekennzeichnet),
  Rechnungs-PDFs automatisch in einen Netzwerkordner (SMB) ablegen.
- **Terminzettel:** Der virtuelle Drucker „Terminzettel“ druckt Terminlisten aus T2med als Bon mit
  Kalender-QR auf dem Bondrucker.

<p align="center"><img src="docs/screenshots/11-export-steuerberatung.png" width="420" alt="Export für die Steuerberatung"> <img src="docs/screenshots/10-terminzettel.png" width="420" alt="Terminzettel-Einstellungen"></p>

**Einrichtung und Betrieb im Browser**
- **Einrichtungs-Überblick** in drei Teilen: „In diesem Webfenster“, „Auf den Client-PCs“, „In T2med“; optionale Punkte
  wie die Letterxpress-Anbindung (Rechnung per Post) und die Ablage in einen Netzwerkordner stehen mit drin.
- Drucker am Server einrichten, Arbeitsplatz-Pakete (Windows, macOS, Linux) laden, Anleitungen für die
  Druckerinstallation am Arbeitsplatz einschließlich PowerShell-Befehlen und der erkannten Server-Adressen.
- **Aktualisieren per Klick** mit Sicherung und automatischem Zurücksetzen bei Fehlern.
- **Netzwerk & Firewall:** Der Server erkennt sein Netz, schlägt die erlaubten Netze vor und richtet die
  Firewall ein (nur private Netze, Dienste der Kienzle-Projekte vorbereitet, 2-Minuten-Bestätigung mit
  automatischem Zurücksetzen).
- **Sicherheit:** Sperre nach Fehlversuchen, Sitzungsablauf, Sicherheitsprotokoll, gehärtete Dienste und
  Webserver, Absicherung gegen Fremdzugriffe (siehe [Sicherheit](#sicherheit)).

<p align="center"><img src="docs/screenshots/06-einrichtungs-ueberblick.png" width="420" alt="Einrichtungs-Überblick"> <img src="docs/screenshots/08-firewall.png" width="420" alt="Netzwerk und Firewall"></p>
<p align="center"><img src="docs/screenshots/07-update.png" width="420" alt="Aktualisierung"> <img src="docs/screenshots/09-sicherheitsprotokoll.png" width="420" alt="Sicherheitsprotokoll"></p>

## Video: Sumup in T2med anbinden

<p align="center">
  <a href="https://youtu.be/VqhrRvIP_0Q">
    <img src="https://i.ytimg.com/vi/VqhrRvIP_0Q/hqdefault.jpg" width="640" alt="Video ansehen: Sumup in T2med anbinden von Thomas Kienzle">
  </a>
</p>

**[▶ Video auf YouTube ansehen](https://youtu.be/VqhrRvIP_0Q)**

## Ablauf

1. In T2med beim Patienten den Button **Kienzle-SumUp** betätigen.
2. Einzelleistungen oder Kombinationen auswählen, Leistungsdatum prüfen und ggf. einen sonstigen Betrag eingeben.
3. **Mit Karte kassieren** anklicken und Zahlung am Solo durchführen.
4. Die Seite zeigt den von SumUp bestätigten Zahlungsstatus.
   Bei eingerichteten Praxisdaten öffnet **Rechnung / Leistungsbeleg als PDF** den lokal
   erstellten Beleg mit allen Positionen und der SumUp-Zahlungsbestätigung darunter.
   Bestandszahlungen behalten ihren bisherigen **Zahlungsbeleg / PDF** von SumUp.
5. Optional **Beleg mailen an „emailadresse“** direkt unter dem PDF-Button anklicken.
   Ein Klick versendet den PDF-Anhang an die angezeigte Adresse.
6. Optional **Beleg drucken** anklicken, wenn der Direktdruck auf den TM-m10 aktiviert ist.
7. **Dokumentation in der Akte** schreibt einen Freitext-Eintrag und schließt den Vorgang ab.
   Das Fenster wird anschließend nach Möglichkeit geschlossen; andernfalls bleibt die
   Abschlussmeldung sichtbar und das Fenster kann manuell geschlossen werden.

**Leistungen verwalten** öffnet die Bearbeitung auf derselben Seite. Leistungen lassen sich
anlegen, bearbeiten und löschen. Löschen entfernt sie aus der Auswahl; gespeicherte
Zahlungen behalten ihre damaligen Bezeichnungen und Preise.

## T2med-Rechnung kassieren

Eine in T2med erstellte Rechnung (Liquidation) lässt sich direkt am Solo kassieren,
ohne Leistungen ein zweites Mal auszuwählen:

1. In T2med die Rechnung wie gewohnt erstellen und auf den Drucker **SumUp-Rechnung** drucken.
2. Beim selben Patienten den Button **Kienzle-SumUp** betätigen. Oben erscheint
   **Offene T2med-Rechnung** mit Rechnungsnummer, Datum und Betrag.
3. **„42,50 € mit Karte kassieren“** anklicken und am Solo bezahlen lassen.
4. Wie bisher **Dokumentation in der Akte**. Der Eintrag nennt
   „T2med-Rechnung RE2026-0042 vom 15.03.2026“ mit Betrag und SumUp-Referenz.

Ablauf und Schutz:

- Der Druckserver reicht die PDF unverändert per HTTPS an `/invoice-inbox.php` weiter
  (Serverzertifikat geprüft, eigener Rechnungseingangs-Schlüssel). Der Anwendungsserver
  liest mit `pdftotext` **Re.-Nr.**, **Re.-Datum**, **Gesamtsumme** sowie Patient und
  Geburtsdatum. Jede dieser Angaben muss eindeutig sein; der Betrag wird mit dem Satz
  „Bitte überweisen Sie den Betrag von …“ gegengeprüft. Gutschriften, Beträge über
  `max_amount_cents` werden abgelehnt; der Druckauftrag meldet dann einen Fehler.
  Hat das PDF keine Textebene (Bild-PDF) oder fehlen die Beschriftungen, liest der Server die
  ersten Seiten per Texterkennung (`tesseract-ocr`, Deutsch). Erkannter Text wird nur akzeptiert,
  wenn der Satz „Bitte überweisen Sie den Betrag von …“ erkannt wird und zur Gesamtsumme passt.
- **Andere Rechnungsvorlagen:** Der Server kennt neben „Re.-Nr.:“ auch „Rechnungsnummer:“,
  „Rechnungsdatum:“, „Gesamtbetrag:“, „Patientin:“ u. a. und liest bei Bedarf auch in
  Lesereihenfolge (Wert in der Zeile unter der Beschriftung). Weicht deine Vorlage ab, trägst du unter
  „T2med-Rechnungen übernehmen“ eigene **Textschnipsel** ein (der Text unmittelbar vor Nummer, Datum,
  Gesamtsumme, Überweisungsbetrag bzw. Patient), je einer pro Zeile.
- Karte **Rechnungseingang** (Reiter „Abrechnung“): eingegangene Rechnungen mit Status (offen, bezahlt,
  am Terminal, ersetzt, abgelaufen), mit Name und Geburtsdatum des Patienten (nur nach Anmeldung sichtbar).
  Nicht bezahlte Rechnungen lassen sich löschen; bezahlte oder gerade kassierte bleiben erhalten. Abgelehnte Drucke
  stehen dort mit Grund und einer Zeilenübersicht der erkannten Beschriftungen – dafür werden weder
  Namen noch Geburtsdaten noch das PDF gespeichert.
- **Nachträgliche Korrektur:** In der Liste der bezahlten T2med-Rechnungen (Reiter „Abrechnung“) lässt sich
  über „Korrigieren“ eine Zahlung stornieren oder der Betrag für Statistik und Excel-Liste ändern, jeweils
  mit Notiz. Das Original (SumUp-Betrag, Transaktion) bleibt gespeichert und die Korrektur lässt sich
  zurücknehmen. Eine Erstattung bei SumUp löst das nicht aus; sie erfolgt im SumUp-Konto. Stornierte
  Zahlungen zählen nicht mehr in der Summe und erscheinen nicht mehr unter „Noch nicht abgehakt“.
- Eine Rechnung wird nur angeboten, wenn **Name und Geburtsdatum** mit dem Patienten aus dem
  T2med-Kontext übereinstimmen. Ein vorangestellter Titel auf der Rechnung ist erlaubt.
- An SumUp gehen nur **Betrag**, **„Rechnung RE2026-0042“** als Beschreibung in den Umsätzen und die
  neutrale Referenz. Keine Patientendaten.
- Erneutes Drucken derselben Rechnungsnummer ersetzt die ältere Fassung. Eine bereits bezahlte
  oder gerade laufende Rechnung wird abgelehnt und nicht erneut angeboten – auch nicht in einem
  neuen Behandlungskontext. Nach abgelehnter Zahlung kann erneut kassiert werden.
- Terminalsperre, verlorene Antworten, Statusabgleich und Aktendokumentation laufen über
  denselben Weg wie bei ausgewählten Leistungen.
- Es entsteht **keine zweite Rechnung**: Als Beleg dient der SumUp-Zahlungsbeleg; die
  Rechnung selbst bleibt die aus T2med.
- Nicht kassierte Rechnungen werden nach `max_age_days` (Standard 14) nicht mehr angeboten
  und beim nächsten Eingang gelöscht.

### Rechnung automatisch drucken

Über der Leistungsauswahl steht das Häkchen **„Rechnung nach der Zahlung drucken“**. Ist es
gesetzt, wird die Rechnung nach der erfolgreichen Zahlung automatisch gedruckt:

- **T2med-Rechnungen** immer auf dem **Praxisdrucker** (A4): die Original-PDF aus T2med, unverändert. Das gilt für
  Karte und Bar ohne Abfrage und auch für **„Später (Überweisung)“**: Dort wird die Rechnung einmal gedruckt (Hinweis
  „gedruckt am …“), eine spätere Zahlung druckt sie nicht noch einmal. Beim Versand per Post (Letterxpress) wird nicht
  gedruckt. Abschalten lässt sich das in den Einstellungen beim Praxisdrucker („T2med-Rechnungen immer automatisch
  drucken“, TOML `office_printing.invoice_auto`).
- **In der App erzeugte Rechnungen/Leistungsbelege** wahlweise auf dem **Praxisdrucker**
  (als A4-Seite mit vergrößertem Beleg) oder auf dem **Bondrucker** (wie „Beleg drucken“).

Unter **Druckeinstellungen** legt die Praxis fest, ob das Häkchen standardmäßig gesetzt ist
(gilt für alle Arbeitsplätze) und auf welchem Drucker App-Rechnungen landen. Für eine einzelne
Zahlung lässt sich das Häkchen vor dem Kassieren abwählen. Nach der Zahlung zeigt die Seite
„Rechnung gedruckt: Praxisdrucker „Laserdrucker“, Auftrag Laserdrucker-42“; bei einem Fehler
erscheint **Rechnung erneut drucken**. Jede Rechnung wird höchstens einmal automatisch gedruckt;
ein Nachdruck ist bis zum Abschluss möglich. Ein Druckfehler blockiert weder Zahlung noch
Akteneintrag. Im Testmodus wird die Rechnung aufbereitet, aber nicht gedruckt.

Praxisdrucker über CUPS (lokal oder auf dem Druckserver):

```toml
[office_printing]
enabled = true
cups_server = "kienzlebox:631"   # leer: CUPS auf diesem Server
queue = "Laserdrucker"           # Name aus lpstat -p
media = "A4"
duplex = false
```

### Bondrucker: Epson TM-m10 oder TM-m30III

```toml
[printing]
enabled = true
model = "tm-m30iii"      # tm-m10 (58 mm, 420 Punkte) oder tm-m30iii (80 mm, 576 Punkte)
transport = "cups"       # samba: Freigabe //kienzlebox/TMm10; cups: RAW-Warteschlange
queue = "TMm10"
cups_server = ""         # leer: dieser Server
width_dots = 576
cut = true
```

Der TM-m30III druckt den Beleg auf 72 mm bedruckbarer Breite (80-mm-Rolle). Mit `transport = "cups"`
geht der Bon unverändert als ESC/POS an eine RAW-Warteschlange, z. B. den per LAN angebundenen
Drucker (`lpadmin -p TMm10 -v socket://IP:9100 -m raw`); Samba ist dafür nicht nötig. Beim
TM-m30III im Web-Config „Sicherer Druck“ deaktivieren, sonst verwirft er RAW-Aufträge ohne Fehler.
Modell, Verbindung und Praxisdrucker stehen unter **Einstellungen → Bondrucker / Praxisdrucker (A4)**;
mit „Testbon drucken“ bzw. „Testseite drucken“ lässt sich die Verbindung sofort prüfen.

### Rechnung per Post senden (Letterxpress)

Optional lässt sich eine offene T2med-Rechnung mit einem Klick als Brief über [Letterxpress](https://www.letterxpress.de)
verschicken (API v3). Einstellungen → Praxis → „Letterxpress (Rechnung per Post)“: Benutzername und API-Key,
Betrieb (Test oder Live), Schwarz-Weiß/Farbe, beidseitig, Versandart und Einschreiben werden dort einmal festgelegt.
„Verbindung und Guthaben prüfen“ testet die Zugangsdaten.

- Der Knopf „Per Post senden“ erscheint auf der Kassenseite und im Rechnungseingang (Admin), nur für offene Rechnungen mit
  gespeichertem PDF. Vor dem Senden fragt ein Dialog nach; im Testbetrieb landet der Brief nur im Postausgang bei Letterxpress.
- Die Anschrift liest Letterxpress aus dem Adressfenster der T2med-Rechnung. Eine live gesendete Rechnung lässt sich
  nicht ein zweites Mal senden: Stattdessen zeigt ein kleines Brief-Symbol (✉) mit Datum, dass sie raus ist. Das gilt auch nach
  einem unklaren Zeitüberschreitungsfehler (Symbol „✉ ?“, bitte den Postausgang bei Letterxpress prüfen). Testbriefe sperren nicht.
- Im Rechnungseingang lassen sich mehrere Rechnungen über Auswahlkästchen markieren und gesammelt per Post senden oder löschen (höchstens 50, mit Bestätigung; bereits live gesendete werden übersprungen).
- Datenschutz: Die Rechnung mit Patientendaten geht an Letterxpress. Einen Auftragsverarbeitungsvertrag muss die Praxis selbst abschließen.
- Das PDF muss A4 hoch sein: Papierformat des Windows-Druckers „SumUp-Rechnung“ auf A4 stellen. Ein Rechteschutz im PDF wird mit `qpdf` entfernt (vom Server-Installer installiert).
- Nicht in der Testumgebung geprüft: die echte Letterxpress-API.

### Rechnungen in einen Netzwerkordner (Steuerberatung)

Optional werden Rechnungen als PDF per SMB in eine Netzwerkfreigabe kopiert, z. B. auf das
NAS oder einen Ordner, den die Steuerberatung abholt. Das gilt für die Original-PDF der
T2med-Rechnungen und – nach eigener Abfrage im Installer – auch für die **in dieser App
erzeugten** Rechnungen und Leistungsbelege:

```toml
[invoice_export]
enabled = true
share = "//nas/steuerberater"
directory = "Rechnungen/{jahr}/{monat}"   # Platzhalter aus dem Rechnungsdatum
username = "praxis"                         # leer: Gastzugriff
password = "…"
domain = ""
when = "paid"                               # T2med-Rechnungen: oder "received"
app_invoices = true                         # auch in der App erzeugte Rechnungen ablegen
```

- `app_invoices = true`: Jede in der App erzeugte „Privatärztliche Rechnung“ bzw. jeder
  „Leistungsbeleg“ wird nach erfolgreicher Zahlung als PDF abgelegt – dieselbe Variante wie
  bei PDF-Download, E-Mail und Direktdruck. Dateiname mit der SumUp-Transaktions-ID als
  Rechnungsnummer, z. B. `2026-11-19_TX8K2M4P_42,50EUR_Karte.pdf`. Das funktioniert auch ohne
  Rechnungseingang. Zahlungen ohne lokalen Beleg (keine Praxisdaten) werden nicht abgelegt.

- `when = "paid"`: nur bezahlte Rechnungen (Karte oder bar), Dateiname
  `2026-03-15_RE2026-0042_42,50EUR_Karte.pdf` bzw. `…_Bar.pdf` bei Barzahlung. Abgelegt wird das PDF der
  Rechnung (bei T2med-Rechnungen das Original), nicht die Quittung. Die Ablage startet automatisch nach der
  erfolgreichen Zahlung und wird spätestens bei **Dokumentation in der Akte** nachgeholt.
  Die Zahlungsseite zeigt „Rechnungs-PDF im Netzwerkordner abgelegt: …“; bei einem Fehler
  erscheint **Rechnungs-PDF erneut ablegen**. Ein Fehler blockiert weder Zahlung noch Akteneintrag.
- `when = "received"`: jede auf „SumUp-Rechnung“ gedruckte Rechnung sofort beim Eingang,
  Dateiname ohne `_Karte`/`_Bar`. Das Ergebnis steht in der Meldung des Druckauftrags.
- Unterordner werden bei Bedarf angelegt. Jede Rechnung wird höchstens einmal abgelegt.
- Fehlgeschlagene Ablagen (z. B. NAS aus) holt der Timer `kienzle-sumup-export.timer`
  stündlich nach – auch wenn das Zahlungsfenster längst geschlossen ist. Manuell:
  `sudo runuser -u kienzle-sumup -- php /opt/kienzle-sumup/scripts/export-invoices.php`.
- Im Testmodus (`mock`) beginnen alle Dateinamen mit `TEST_`.
  Der Dateiname enthält keine Patientendaten; die PDF selbst ist die vollständige Rechnung.
- Die PDF wird nur bei aktivierter Ablage auf dem Anwendungsserver zwischengespeichert und
  nach erfolgreicher Ablage gelöscht. Ohne Ablage werden nur Nummer, Datum, Betrag und Patient gespeichert.
- Das Passwort steht wie die SMTP-Zugangsdaten nur in der geschützten TOML-Datei.

### Einrichtung

**Anwendungsserver:** Unter **Einstellungen → T2med-Rechnungen übernehmen** das Häkchen setzen
und speichern. Der Schlüssel für den Druckserver wird dabei automatisch erzeugt. Die Ablage im
Netzwerkordner (auch nur für App-Rechnungen) steht unter **Einstellungen → Ablage im
Netzwerkordner**; „Schreibzugriff prüfen“ legt dort kurz eine Testdatei an und löscht sie wieder.
Der stündliche Timer für fehlgeschlagene Ablagen ist immer eingerichtet.

**Druckserver:** Läuft T2med-Druck über diesen Server, ist nichts weiter zu tun: Beim Speichern von
„T2med-Rechnungen übernehmen“ legt die Einstellungsseite den Drucker **„SumUp-Rechnung“** selbst an
(Ausschalten entfernt ihn wieder) – ohne Terminal und ohne `sudo`. Der Server-Installer hat dafür
einmalig das CUPS-Backend und die PPD abgelegt; Adresse, Schlüssel und Zertifikat schreibt die
Anwendung nach `/etc/kienzle-sumup-printer/web/`. Die Karte „Drucker „SumUp-Rechnung““ im Reiter
„Einrichtung“ zeigt den Zustand und die Adresse für Windows.

Nur für einen **anderen** Druckserver (z. B. kienzlebox) auf der Einstellungsseite
**„Einrichtungsdatei herunterladen“** wählen, die Datei `kienzle-sumup-drucker.json` geschützt dorthin
kopieren und dort ausführen:

```bash
sudo bash scripts/install-invoice-printer.sh /pfad/zu/kienzle-sumup-drucker.json
```

Die Datei enthält den Eingangsschlüssel; nach der Einrichtung löschen. Ältere Installationen
mit dem Ordner `/root/kienzle-sumup-printer` funktionieren weiter.

Der Installer prüft zuerst Verbindung, Zertifikat und Schlüssel und legt dann die freigegebene
CUPS-Warteschlange `SumUp-Rechnung` mit dem Backend `kienzle-sumup:/` an. Das Backend liest
Konfiguration und Spooldatei als root und arbeitet danach als Benutzer `lp`; PostScript wird mit
Ghostscript in PDF umgewandelt. Erneute Prüfung jederzeit mit
`sudo /usr/lib/cups/backend/kienzle-sumup --check`, Entfernen mit
`sudo bash scripts/install-invoice-printer.sh --uninstall`.

**Arbeitsplätze:**

- **Windows:** IPP-Drucker `http://kienzlebox:631/printers/SumUp-Rechnung` mit dem Treiber
  „Microsoft IPP Class Driver“ hinzufügen (wie beim Terminzettel). Papierformat A4 (der PowerShell-Befehl der
  Einstellungsseite stellt es mit `Set-PrintConfiguration -PaperSize A4` gleich mit ein; sonst rechnet Windows auf
  Letter um und Letterxpress lehnt das PDF ab).
- **Mac:** „SumUp-Rechnung @ kienzlebox“ hinzufügen und als Treiber
  [`macos/SumUp-Rechnung-PDF.ppd`](macos/SumUp-Rechnung-PDF.ppd) wählen. Er reicht die PDF
  mit Text unverändert weiter.

Fehler eines Druckauftrags (z. B. „Rechnung … ist bereits per Karte bezahlt“) stehen in der
CUPS-Auftragsliste bzw. in `/var/log/cups/error_log`; sie enthalten keine Patientendaten.

## GOÄ-Angaben, Kombinationen und eigener Beleg

In **Leistungen verwalten** bleibt der Preis frei festlegbar. Unter **GOÄ-Angaben ergänzen**
werden Ziffer, Steigerungsfaktor und Gebührenrahmen gespeichert. Der Faktor wird auf den
Beleg geschrieben und **nicht nochmals mit dem Preis multipliziert**. Für Leistungen
auf Verlangen gibt es eine entsprechende Kennzeichnung. Preise, Ziffern und Bezeichnungen
werden von der Praxis gepflegt; ein vollständiger GOÄ-Katalog ist nicht enthalten.

**Kombinationen verwalten** fasst vorhandene Einzelleistungen zusammen. Beispiel:
„Laborprofil Eins“ enthält Laborwert Eins und Blutentnahme, „Laborprofil Zwei“ enthält
Laborwert Zwei und dieselbe Blutentnahme. Bei gemeinsamer Auswahl erscheinen drei
Positionen. Auch eine zusätzlich einzeln gewählte Blutentnahme zählt nur einmal.
Entscheidend ist dieselbe gespeicherte Einzelleistung, nicht nur ein gleicher Name oder
eine gleiche GOÄ-Ziffer. Die Vorschau **Ausgewählte Positionen** zeigt die tatsächliche Summe.
Eine in Kombinationen verwendete Leistung muss vor dem Löschen aus diesen entfernt werden.

Die Gebührenrahmen orientieren sich an [§ 5 GOÄ](https://www.gesetze-im-internet.de/go__1982/__5.html):
ärztlich 2,3 / maximal 3,5, technisch (A, E, O) 1,8 / maximal 2,5 und Labor (M / Nr. 437)
1,15 / maximal 1,3. Oberhalb der jeweiligen Schwelle ist vor dem Kassieren eine
fallbezogene Begründung einzutragen. Sie erscheint auf dem Beleg und im Akteneintrag. Bei der Leistung lässt sich unter
„GOÄ-Angaben“ eine feste Begründung hinterlegen: Sie wird an der Kasse vorausgefüllt, ist dort pro Abrechnung änderbar und
wird sonst automatisch verwendet.
Das Leistungsdatum ist gesondert wählbar. Die Praxis prüft die fachliche Zuordnung und
den eingegebenen Preis; die Anwendung prüft keine GOÄ-Ausschlüsse oder Analogbewertungen.
Hinweise zu Rechnungsangaben: [§ 12 GOÄ](https://www.gesetze-im-internet.de/go__1982/__12.html).

Praxisname und Anschrift unter **Einstellungen → Praxisdaten** eintragen. Alternativ in
`/etc/kienzle-sumup/kienzle-sumup.toml` ergänzen:

```toml
[practice]
name = "Praxis Dr. Muster"
street = "Musterstraße 12"
city = "12345 Musterstadt"
contact = "Telefon 01234 56789"
append_sumup_receipt = false
```

Sobald Name, Straße und Ort ausgefüllt sind, erhalten neue Zahlungen einen lokalen Beleg.
Ohne diese Angaben bleibt für Festpreise der bisherige SumUp-Beleg verfügbar; GOÄ-Zahlungen
fordern zunächst die Praxisdaten an. `contact` ist optional. Patient und aktuelle Anschrift
werden aus t2med übernommen, soweit dort vorhanden. Alle Belegdaten werden beim Start
der Zahlung unveränderlich gespeichert. Spätere Änderungen am Katalog, Patienten oder an
den Praxisdaten ändern frühere Belege nicht.

**Rechnungsnummer ist die SumUp-Transaktions-ID der erfolgreichen Zahlung.** Sie wird
auch in der Akte dokumentiert. Vor erfolgreicher Zahlung gibt es keinen bezahlten Beleg
und noch keine Rechnungsnummer. Abgelehnte oder ungeklärte Versuche bleiben unbezahlt.
Bestehen alle Positionen aus GOÄ-Leistungen, lautet die Überschrift „Privatärztliche Rechnung“;
bei freien oder gemischten Positionen „Leistungsbeleg“.

Auf dem 58-mm-Bon stehen Praxis, Patient, Rechnungsnummer, Rechnungs- und Leistungsdatum,
GOÄ-Ziffern, Bezeichnungen, Faktoren, Einzelpreise, Begründungen und Summe. Darunter folgt
die lokale Zahlungsbestätigung mit Betrag, Zahlungszeitpunkt, Status, neutraler Referenz
und SumUp-Transaktions-ID. **Diese medizinischen Inhalte werden nicht an SumUp übertragen.**

Optional unter **Einstellungen → Praxisdaten** **Originalen SumUp-Beleg statt lokaler
Zahlungsbestätigung anhängen** ankreuzen oder im vorhandenen Abschnitt
`[practice]` die Zeile `append_sumup_receipt = true` setzen. Dann folgt nach der Summe
**„Vielen Dank.“** und direkt darunter der originale SumUp-Beleg. Zwischen beiden Teilen
wird **nicht geschnitten**. `[printing].cut = true` schneidet erst am Ende des gesamten
Bons; mit `false` entfällt auch dieser Schnitt. Der Originalbeleg wird proportional auf
die Bonbreite skaliert und als Bild eingebettet; die lokalen Rechnungsangaben bleiben
in der PDF Text.

Die Option ist standardmäßig aus. Sie gilt auch beim erneuten Abruf bereits gespeicherter
lokaler Belege, ohne deren Rechnungsdaten zu ändern. Falls SumUp den Originalbeleg noch
nicht liefert, kann der Abruf erneut versucht werden; es wird kein unvollständiger Bon
gedruckt oder versandt. Zahlungen ohne lokale Rechnung behalten ihren bisherigen Beleg.

PDF, E-Mail-Anhang und Direktdruck verwenden dieselbe gewählte Belegvariante. Beim
E-Mail-Versand enthält die PDF damit auch Patienten- und Leistungsdaten; die Oberfläche
weist darauf hin.

**Beleglink / E-Mail-Adresse ändern** zeigt den von SumUp gelieferten Originalbeleg-Link sowie
**PDF herunterladen**. Der SumUp-Link führt weiterhin nur zum Zahlungsnachweis;
der lokale PDF-Beleg enthält zusätzlich die abgerechneten Leistungen. Bei ausgeschalteter
Option `append_sumup_receipt` benötigt ein lokaler Beleg keinen SumUp-Beleglink. Mit der
Option sowie für ältere Zahlungen ohne lokalen Beleg wird die SVG bzw. PNG vom geprüften
Originalbeleg-Link geladen und lokal aufbereitet.

Für **PDF per E-Mail senden** im Server-Installer den E-Mail-Versand aktivieren und
SMTP-Server, Port, Verschlüsselung (`starttls`, meist 587, oder `smtps`, meist 465),
Benutzername, Passwort und Absender eintragen. Das Passwort wird verdeckt abgefragt;
die Daten bleiben unter `[mail]` in der geschützten TOML-Datei. Bestehende Einstellungen
bleiben bei Updates erhalten. Ohne SMTP bleibt der PDF-Download nutzbar.

Die bevorzugte aktuelle E-Mail-Adresse wird nach erfolgreicher Zahlung automatisch
aus t2med geladen und auf dem großen Button **Beleg mailen an „emailadresse“**
angezeigt. Ein Klick darauf sendet die PDF über den konfigurierten Mailserver.
Unter **Beleglink / E-Mail-Adresse ändern** kann die Adresse vorher geändert werden.
Fehlt eine Adresse, öffnet der große Button die Eingabe. Es läuft
kein Hintergrundversand. Die Bestätigung bedeutet, dass der Mailserver die Nachricht
angenommen hat; die Zustellung an das Postfach kann später scheitern. Bei einer
verlorenen Antwort wird derselbe Versandversuch geprüft; ein bewusster erneuter
Versand ist möglich. Nach Abschluss der Aktendokumentation ist der t2med-Zugriff
beendet; nach einem Neuladen kann die Adresse dann nur noch manuell eingetragen werden.

Belegabruf, Drucken und E-Mail-Versand verändern weder Zahlung noch Akteneintrag.
Der Browser kann nur auf Belege seiner erfolgreich bestätigten Zahlungen zugreifen.
Die Zusammenstellung der Belege erfolgt vollständig auf dem Praxisserver. SumUp-Originalbelege
werden ohne API-Key vom geprüften Beleglink geladen. Die PDF-Umwandlung erfolgt lokal
mit `rsvg-convert`. Der Konverter kommt als Distributionspaket (`librsvg2-bin`) über den Installer.
PHPMailer wird bevorzugt aus dem Systempaket `libphp-phpmailer` geladen (Sicherheitsupdates über `apt`);
fehlt es, nutzt die Anwendung die mitgelieferte Kopie in `src/vendor/phpmailer` (PHPMailer 6.9.3, LGPL 2.1).
Der Installer richtet außerdem automatische Sicherheitsupdates (`unattended-upgrades`) ein, falls sie fehlen;
Kernel-Updates und Neustarts bleiben Sache der Praxis (`sudo apt update && sudo apt full-upgrade`, danach Neustart).
Im Testmodus wird ein markierter Testbeleg erzeugt und kein SMTP-Versand ausgeführt.

## Optionaler Direktdruck auf den Epson TM-m10

**Beleg drucken** übergibt den lokalen Leistungsbeleg mit Zahlungsbestätigung oder
angehängtem SumUp-Originalbeleg direkt an den TM-m10 mit 58-mm-Rolle; ältere Zahlungen
behalten ihren SumUp-Originalbeleg.
Der Button erscheint nur nach erfolgreicher Zahlung und bei aktiviertem Direktdruck.
Ein Klick druckt, anschließend ist ein bewusster Nachdruck möglich. PDF-Anzeige,
E-Mail und Aktendokumentation bleiben unabhängig davon bedienbar.

Unter **Einstellungen → Bondrucker** den Direktdruck einschalten und Freigabe, Druckbreite
und Schnitt eintragen; bei einer neuen Installation ist der Direktdruck ausgeschaltet. Unter
`/etc/kienzle-sumup/kienzle-sumup.toml` lassen sich dieselben Werte einstellen:

```toml
[printing]
enabled = true
share = "//kienzlebox/TMm10"
width_dots = 420
cut = true
```

- `enabled = false` deaktiviert den Direktdruck und blendet den Button aus.
- `share` bezeichnet eine **RAW-Druckerfreigabe mit Gastzugriff ohne Passwort**.
  In der TOML vorwärts gerichtete Schrägstriche verwenden; der Installer akzeptiert
  auch `\\kienzlebox\TMm10` und wandelt die Schreibweise um.
- `width_dots = 420` entspricht 52,5 mm bedruckbarer Breite auf der 58-mm-Rolle.
  Kleinere Werte bis 128 sind möglich; die Belegproportionen bleiben erhalten.
- `cut = true` erzeugt genau einen Schnitt am Auftragsende; `false` gibt nur Vorschub aus.

Bei der ersten Aktivierung den Installer ausführen: Er ergänzt `php-gd` und
`smbclient`. Danach genügt für Änderungen an `[printing]` das Speichern der TOML und
Neuladen der Webseite. Ohne `[printing]` bleibt eine ältere Konfiguration weiterhin
nutzbar und der Direktdruck aus. Der PDF-Konverter muss installiert und die
Druckerfreigabe vom Anwendungsserver erreichbar sein.

Der Server wandelt den Beleg in Epson-ESC/POS-Grafikdaten um und sendet sie
an Samba. Es wird kein weiterer CUPS-Dienst auf dem Anwendungsserver eingerichtet.
**Druckauftrag übergeben** bestätigt die Annahme durch die Freigabe, nicht die
tatsächliche Papierausgabe. Bei einer verlorenen Antwort prüft ein weiterer Klick
denselben Auftrag – auch nach Neuladen. Bei unklarer Übergabe zuerst Drucker und
Warteschlange prüfen, bevor ein neuer Nachdruck gestartet wird. Im Testmodus wird
die Aufbereitung ausgeführt, aber nichts an den Drucker gesendet.

Die bestehende Einrichtung der kienzlebox ist in
[Drucker einrichten: Bluetooth, CUPS RAW und Samba](epson-tm-m10-bluetooth-linux-samba-macos.md)
beschrieben. Die dauerhaft offene RFCOMM-Verbindung und die RAW-Warteschlange werden
von kienzle-sumup nicht verändert. Kein zusätzliches AutoCut-Backend verwenden:
Der Mac-Treiber beziehungsweise unser Direktdruck erzeugt den Schnitt bereits im
jeweiligen Druckauftrag. Beim ersten Ausdruck Lesbarkeit, Länge und Schnitt prüfen.

**Auf dem Druckserver (kienzlebox):** In `/etc/samba/smb.conf` die folgenden Werte
in den vorhandenen Abschnitten ergänzen oder korrigieren. Andere Freigaben erhalten;
keinen zweiten `[global]`- oder `[TMm10]`-Abschnitt anlegen.

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

Die Anwendung meldet sich als `guest` ohne Passwort an. **`disable spoolss = no`
ist für diesen Druckweg erforderlich.** Bei `yes` kann der Zugriff auf die Freigabe
bereits funktionieren, während das Anlegen des Druckauftrags mit
`NT_STATUS_OBJECT_NAME_NOT_FOUND` scheitert. Im Samba-Log steht dann
`Could not connect to spoolss pipe`. Dieser Fehler wurde an der kienzlebox durch
Umstellen auf `no` behoben; der Anwender hat den funktionierenden Direktdruck bestätigt.
[Samba: Gastzugriff](https://www.samba.org/samba/docs/current/man-html/smb.conf.5.html#MAPTOGUEST),
[Samba: spoolss](https://www.samba.org/samba/docs/current/man-html/smb.conf.5.html#DISABLESPOOLSS)

Spool-Verzeichnis auf der **kienzlebox** vorbereiten:

```bash
sudo mkdir -p /var/spool/samba
sudo chmod 1777 /var/spool/samba
```

Nach Speichern der Samba-Konfiguration auf der **kienzlebox** prüfen und Samba neu starten:

```bash
sudo testparm -s && sudo systemctl restart smbd
```

Die oben gezeigte `[printing]`-TOML gehört dagegen auf den **Anwendungsserver
(t2medtest)**. Dessen Installer richtet den Samba-Client ein; die Freigabe auf der
kienzlebox wird dort wie beschrieben separat konfiguriert.

Bei fehlgeschlagener Druckübergabe zeigt die Anwendung den Samba-Statuscode bzw.
Exit-Code an und protokolliert ihn in `/var/log/kienzle-sumup/php.log`.
Ein erfolgreicher Verbindungstest mit `smbclient ... -c 'quit'` bestätigt nur den
Zugriff auf die Freigabe. Spool-Verzeichnis und CUPS-Warteschlange werden auf dem
**Druckserver (kienzlebox)** geprüft, nicht auf dem Anwendungsserver (t2medtest):

```bash
sudo testparm -s --section-name=TMm10
ls -ld /var/spool/samba
lpstat -p TMm10 -v TMm10
```

Kein Hintergrundjob und kein Webhook. Bei geschlossenem Browser findet keine weitere
Statusabfrage oder automatische Dokumentation statt. Erneut aus demselben t2med-Kontext
öffnen setzt einen unvollständigen Vorgang fort. Ein neuer Behandlungskontext kann eine
alte Zahlung nicht automatisch übernehmen.

## Erstinstallation Schritt für Schritt

Vorbereitung: Ubuntu-Server-ISO (**24.04 LTS**) von ubuntu.com, eine VM wie oben und eine feste Adresse im Praxisnetz
(am besten eine DHCP-Reservierung im Router für die VM). Der Server braucht Internet, zugänglich ist er nur im Praxisnetz.

**A. Ubuntu Server installieren**

1. VM mit mindestens 2 Kernen, 4 GB RAM und 100-GB-Festplatte anlegen, die ISO einlegen und starten.
2. Im Installer **Deutsch** und die deutsche Tastatur wählen, „Ubuntu Server“ (nicht „minimized“) installieren.
3. Netzwerk: die automatisch erkannte Verbindung (DHCP) übernehmen. Eine feste IP kann später im Router reserviert werden.
4. Speicher: „Use an entire disk“ mit den Standardwerten übernehmen.
5. Profil: Name, **Rechnername** (z. B. `praxisserver`), Benutzername und ein starkes Passwort festlegen. Der Rechnername
   erscheint später in der Adresse `https://praxisserver:7868`.
6. **„Install OpenSSH server“ ankreuzen**, damit sich der Server von einem Arbeitsplatz aus verwalten lässt. Zusätzliche „Snaps“ nicht auswählen.
7. Installation abwarten, mit „Reboot Now“ neu starten, das Installationsmedium entfernen und mit dem eben angelegten Benutzer anmelden.

**B. System aktualisieren**

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y git
sudo timedatectl set-timezone Europe/Berlin
```

Bei der Frage nach einem Neustart (Kernel-Update) einmal `sudo reboot` ausführen.

**C. Kienzle-Suite installieren**

```bash
git clone https://github.com/thomaskien/t2med-sumup.git
cd t2med-sumup
sudo bash scripts/install-server.sh
```

Wer die Beta-Version nutzen will: statt der ersten Zeile
`git clone -b rechnungseingang https://github.com/hau-si/kienzle-suite-beta.git` und danach `cd kienzle-suite-beta`.

Der Installer stellt keine Fragen, richtet Webserver, PHP, Drucksystem, Terminzettel-Dienst und automatische Sicherheitsupdates ein
und braucht einige Minuten. Am Ende gibt er einen einmaligen **Einrichtungslink** aus.

**D. Ersteinrichtung im Browser**

1. Den Link `https://praxisserver:7868/settings.php#setup=…` auf einem Arbeitsplatz öffnen. Die Zertifikatswarnung erscheint,
   weil das Zertifikat selbst erstellt ist; ausnahmsweise bestätigen (die Starter auf den Arbeitsplätzen prüfen es genau).
   Ist der Rechnername im Netz nicht auflösbar, die IP-Adresse des Servers statt des Namens eintragen.
2. Ein **Admin-Passwort** mit mindestens 10 Zeichen festlegen.
3. Im Reiter **Einrichtung** die Punkte des Einrichtungs-Überblicks von oben nach unten abarbeiten: Praxisname und Anschrift, SumUp
   (zuerst Testmodus, dann Zugangsdaten und Live), Bondrucker, Praxisdrucker (A4), Terminzettel. Optional: Rechnung per Post (Letterxpress) und
   Netzwerkordner für die Steuerberatung. Zum Schluss die Firewall auf das Praxisnetz beschränken.
4. Unter „Drucker „SumUp-Rechnung“ anlegen“ die Übernahme von T2med-Rechnungen einschalten.
5. Auf jedem Kassen-PC das **Starter-Paket** installieren (Karte „Arbeitsplätze einrichten“) und den Drucker **„SumUp-Rechnung“** (und am
   Arbeitsplatz für den Zettel **„Terminzettel“**) als IPP-Drucker hinzufügen. Die Adressen zeigt der Überblick.
6. In T2med Drittanbieter-Zugriff, Funktionsleiste und Druckerzuordnung einrichten:
   [Anleitung „T2med einrichten“](docs/T2MED-EINRICHTUNG.md).
7. Mit einer Testrechnung im Testmodus alles prüfen, dann SumUp auf Live stellen.

**Hinweise für Ubuntu 26.04 und Parallelbetrieb** (aus der Praxis, unter Vorbehalt):

- **AppArmor:** Auf Ubuntu 26.04 schränkt AppArmor `gs` und `qpdf` ein. Der Installer trägt dafür lokale Regeln für den
  Datenordner dieser Anwendung ein (`/etc/apparmor.d/local/gs` und `…/qpdf`). Zeigt sich trotzdem ein Fehler beim Lesen oder Versenden
  von Rechnungen (`Permission denied`, `undefinedfilename`), mit
  `sudo journalctl -k | grep 'apparmor="DENIED"'` nachsehen, welcher Pfad gesperrt ist, und ihn dort ergänzen:

  ```bash
  echo '/PFAD/** rwk,' | sudo tee -a /etc/apparmor.d/local/gs
  sudo apparmor_parser -r /etc/apparmor.d/gs
  ```

- **Parallel zu anderen Diensten** (z. B. nginx auf Port 80): Die Suite bringt ihre eigene Apache-Instanz auf Port 7868 mit.
  Der Installer schaltet den Standard-Apache des Pakets ab, wenn Port 80 belegt ist oder nginx installiert ist, damit er nicht mit dem anderen Webserver kollidiert.
  Das Drucksystem CUPS ist ein gemeinsamer Dienst: Wird es auch aus einem VPN genutzt, dort das Netz in `cupsd.conf` mit `Allow <Netz>` freigeben.

Später genügt zum Aktualisieren der Knopf im Adminpanel (Glocke oben); siehe [Aktualisieren im Webinterface](#aktualisieren-im-webinterface).

## Server installieren

Unterstütztes Ziel: Debian 12+, Ubuntu 22.04+ und Raspberry Pi OS Bookworm+ mit systemd,
PHP 8.1 oder neuer; x86_64, ARM64 und ARMv6/7. Die FHIR-Schnittstelle von T2med muss
vom Server erreichbar sein. Internetzugang wird für SumUp und Paketinstallation benötigt.

```bash
git clone https://github.com/thomaskien/t2med-sumup.git
cd t2med-sumup
sudo bash scripts/install-server.sh
```

Der Installer stellt **keine Fragen**. Er richtet den Server im Testmodus ein (Port
**7868 (SUMU)**, Adresse `https://<Rechnername>:7868`) und gibt am Ende einen einmaligen
**Einrichtungslink** aus:

```text
Ersteinrichtung im Browser (Link einmalig gültig, nicht weitergeben):
  https://praxisserver:7868/settings.php#setup=…
```

Den Link auf einem Arbeitsplatz öffnen, ein **Admin-Passwort** (mindestens 10 Zeichen)
festlegen und dann alles Weitere im Browser eintragen – siehe
[Einstellungen im Browser](#einstellungen-im-browser). Wer lieber wie früher im Terminal
antwortet: `sudo bash scripts/install-server.sh --fragen`. Ein anderer Port bzw. eine andere
Serveradresse wird nur so oder in der TOML-Datei festgelegt, weil die Starter darauf
eingerichtet werden.

### Einstellungen im Browser

Erreichbar über den Link **Einstellungen** unten auf der Kartenzahlungsseite oder direkt unter
`https://<server>:7868/settings.php`. Die Seite ist durch das Admin-Passwort geschützt
(Sitzung 30 Minuten, nach 5 Fehlversuchen 5 Minuten Sperre); die Arbeitsplätze kassieren
weiterhin ohne Passwort.

Die Seite hat drei Reiter; eine Glocke rechts in der Reiterleiste zeigt auf jedem Reiter, wenn eine neue Version bereitsteht (Klick öffnet Einrichtung → Aktualisierung):

| Reiter | Inhalt |
| --- | --- |
| **Abrechnung** (laufend) | Rechnungseingang, T2med-Rechnungen als bezahlt abhaken, Export für die Steuerberatung |
| **Leistungen** | Leistungen, GOÄ-Angaben und Kombinationen für die Kasse |
| **Einrichtung** (einmalig) | Überblick mit offenen Schritten und einklappbare Blöcke: Praxis & Rechnungen (Praxisdaten, Umsatzsteuer, E-Mail, Ablage), Zahlung & T2med (SumUp, T2med-Anbindung, Rechnungseingang), Drucker (Drucker einrichten, Bondrucker, Praxisdrucker), **Terminzettel**, Arbeitsplätze & Netzwerk, Sicherheit (Protokoll, Admin-Passwort); ganz oben der Block Aktualisierung |

Solange Pflichtschritte der Einrichtung offen sind (Praxisdaten, SumUp live, T2med live), öffnet die Seite den
Reiter „Einrichtung“, danach „Abrechnung“. Zahlen an den Reitern zeigen offene Punkte bzw. noch nicht
abgehakte T2med-Rechnungen. Adressen wie `/settings.php#s-printers` öffnen den passenden Reiter und Block direkt.

| Bereich | Inhalt |
| --- | --- |
| Betrieb und SumUp | Testmodus/Live, API-Key, Affiliate-Key, App-ID, Händlercode, Reader-ID; „Verbindung zu SumUp prüfen“ |
| Zahlungen | Höchstbetrag pro Zahlung |
| T2med-Anbindung | Testmodus/Live, FHIR-Adresse, API-Key (leer: Demo-Key), Aktenkürzel, optional Zertifikat |
| Praxisdaten | Rechnungsaussteller und Anschrift |
| E-Mail-Versand | SMTP-Server und Absender |
| Bondrucker / Praxisdrucker (A4) | Modell, Verbindung, Warteschlange; Testdruck |
| Umsatzsteuer | MwSt.-Satz, Kleinunternehmerregelung (§ 19 UStG), Steuernummer/USt-IdNr., Hinweistexte |
| T2med abhaken | bezahlte T2med-Rechnungen nach Nummer, in T2med erledigt markieren, Excel-Liste |
| Export Steuerberatung | Excel-Tabelle aller Rechnungen aus dem Rechnungstool, je Jahr oder Monat |
| Terminzettel | Kopf (erste Zeile, Adressbereich), Überschrift, Termine, Endtext mit eigener Schriftgröße; Kalender-QR (Position, Größe); Live-Vorschau; Testdruck |
| Drucker einrichten | TM-m10, TM-m30III und A4-Netzwerkdrucker suchen, anlegen, übernehmen, entfernen |
| Arbeitsplätze einrichten | Installationspaket für Windows, macOS und Linux herunterladen |
| T2med-Rechnungen übernehmen | virtueller Drucker „SumUp-Rechnung“, Einrichtungsdatei für den Druckserver |
| Ablage im Netzwerkordner | Freigabe, Unterordner, Benutzer, Passwort; „Schreibzugriff prüfen“ |

Jedes Feld hat ein Info-Symbol (ⓘ) mit Erklärung, Beispielwert und – bei Auswahllisten –
der Bedeutung jeder Möglichkeit; Beispielwerte stehen zusätzlich grau im leeren Feld.

Jeder Bereich wird einzeln gespeichert und gilt sofort für alle Arbeitsplätze. Ungültige
Angaben werden mit Klartext abgelehnt, nichts wird halb gespeichert. Gespeicherte
Schlüssel und Passwörter werden nie wieder angezeigt; ein leeres Feld behält den Wert.
Die Werte liegen verschlüsselt (mit dem Verschlüsselungsschlüssel aus der TOML-Datei) in der
Datenbank und haben Vorrang vor der TOML-Datei; diese bleibt die Grundlage.

#### Barzahlung oder Kartenzahlung

Über dem Kassieren-Knopf stehen **„Karte (SumUp)“** (vorausgewählt) und **„Bar“**. Ein Klick auf „Bar“
macht daraus „… bar erhalten – Rechnung erstellen“: Die Rechnung entsteht sofort, ohne Terminal, mit
eigener fortlaufender Nummer je Jahr (`BAR-2026-0001`, im Testmodus `TEST-BAR-…`). Beleg und Akteneintrag
lauten „Bar bezahlt“ bzw. „Barzahlung“; Drucken, E-Mail, Ablage und Dokumentation funktionieren wie bei
Karte. Ein belegtes Terminal blockiert Barzahlungen nicht. Nach jedem Vorgang steht die Auswahl wieder
auf Karte. Für Barzahlungen müssen Praxisdaten eingetragen sein (es wird immer eine Rechnung erstellt).

Im Export für die Steuerberatung steht in der Spalte „Zahlungsart“ **„Bar“** oder **„Kartenzahlung“**,
die Monatssummen zeigen zusätzlich „davon bar“ und „davon Kartenzahlung“.

Hinweis: Wer Bareinnahmen elektronisch erfasst, unterliegt den Regeln zur Kassenführung (u. a.
Kassenbuch, ggf. § 146a AO zu elektronischen Aufzeichnungssystemen und TSE). Ob und wie diese Erfassung
dafür genügt, bitte mit der Steuerberatung klären.

#### GOÄ-Rechner

Beim Anlegen einer Leistung unter „GOÄ-Angaben ergänzen“ den **Einfachsatz (1,0-fach)** und den
**gewünschten Endbetrag** eintragen. Der Rechner wählt den Faktor so, dass die Gebühr (Einfachsatz × Faktor,
auf Cent gerundet) **genau** dem Endbetrag entspricht, und trägt Faktor und Preis ein. Er nimmt so wenige
Nachkommastellen wie nötig: zuerst zwei, reicht das nicht, drei, und bei Bedarf vier. Beispiele: Einfachsatz
8,74 €, Endbetrag 25,00 € → Faktor 2,86 → 25,00 €; 10,72 € und 25,00 € → Faktor **2,332** → 25,00 €. Bis zu
einem Einfachsatz von 100 € ist damit jeder Endbetrag möglich; nur bei noch höheren Einfachsätzen zeigt er den
nächstmöglichen. Der Faktor wird mit allen Stellen gespeichert und auf Belegen und in der Akte so ausgegeben.
Wer in der Rechnungsverwaltung nur zwei Nachkommastellen zulässt, sollte einen passenden Endbetrag wählen. Oberhalb des
Schwellenwerts weist er auf die nötige Begründung hin; über dem Höchstsatz des gewählten
Gebührenrahmens nennt er den größtmöglichen Betrag und übernimmt nichts. Umgekehrt berechnet ein
geänderter Faktor sofort den Preis. Mit Einfachsatz prüft auch der Server, dass der Preis
Einfachsatz × Faktor entspricht; ohne Einfachsatz bleibt alles wie bisher.

#### Bezahlte T2med-Rechnungen in T2med abhaken

Unter **Einstellungen → T2med-Rechnungen als bezahlt abhaken** stehen alle über den Drucker
„SumUp-Rechnung“ übernommenen und per Karte bezahlten T2med-Rechnungen, sortiert nach
Rechnungsnummer: Rechnungsdatum, Patient, Geburtsdatum, Betrag, Zahlungszeitpunkt. Diese Rechnungen in
der T2med-Rechnungsverwaltung als bezahlt markieren, hier ankreuzen und „Markierte als in T2med
abgehakt“ wählen – beim nächsten Mal erscheinen nur die neuen. „Alle“, Jahr und Monat zeigen auch
bereits abgehakte; „Markierte wieder öffnen“ nimmt ein Abhaken zurück. „Liste als Excel herunterladen“
liefert dieselbe Auswahl als `T2med-Rechnungen-bezahlt-offen.xlsx` (mit Summenzeile). Testzahlungen
erscheinen nur im Testmodus und nur auf Wunsch.

#### Mehrwertsteuer und Export für die Steuerberatung

**Je Leistung:** Beim Anlegen oder Bearbeiten einer Leistung (Kassenseite → Leistungen verwalten)
„mit 19 % MwSt. (im Preis enthalten)“ ankreuzen. Der eingetragene Preis bleibt der Endpreis, den der
Patient zahlt; die Rechnung weist „inkl. 19 % MwSt.“ je Position sowie unter der Summe Netto und MwSt.
je Satz aus, dazu „Ohne MwSt.“ für die übrigen Positionen. Leistungen ohne Häkchen (z. B. ärztliche
Heilbehandlung) bleiben ohne MwSt.

**Einstellungen → Umsatzsteuer:**

- **MwSt.-Satz** (Standard 19 %). Er gilt für alle Leistungen „mit MwSt.“; bezahlte Rechnungen behalten
  den Satz, der bei der Zahlung galt.
- **Kleinunternehmerregelung (§ 19 UStG):** Dann wird auf keiner Rechnung MwSt. ausgewiesen, und unter
  der Summe steht „Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.“ (Text anpassbar).
- **Steuernummer oder USt-IdNr.** für den Rechnungskopf, ob „Sonstiger Betrag“ MwSt. enthält, und
  optional ein Hinweis für Positionen ohne MwSt. (z. B. zur Steuerbefreiung nach § 4 Nr. 14 UStG).

Den genauen Umgang (welche Leistungen steuerpflichtig sind, Hinweistexte) bitte mit der
Steuerberatung abstimmen; die Anwendung rechnet nur nach den hinterlegten Angaben.

**Einstellungen → Export für die Steuerberatung:** Jahr und Zeitraum (ganzes Jahr oder ein Monat) wählen
und „Excel-Tabelle herunterladen“. Die Datei `Rechnungen-2026.xlsx` bzw. `Rechnungen-2026-03.xlsx` hat
drei Blätter:

| Blatt | Inhalt |
| --- | --- |
| Rechnungen | je Rechnung Datum, Rechnungsnummer (SumUp), Leistungsdatum, Art, Brutto, Netto und MwSt. je Satz, ohne MwSt., Zahlungsreferenz; Summenzeile |
| Positionen | jede Leistung mit GOÄ-Ziffer, Faktor, Brutto, MwSt.-Satz, MwSt., Netto |
| Monatssummen | Anzahl und Beträge je Monat (bei Monatsexport: „Summe“) |

Enthalten sind alle bezahlten Rechnungen und Belege aus dem Rechnungstool, nach Zahlungsdatum; die Spalte
„Zahlungsart“ zeigt „Bar“ oder „Kartenzahlung“, die Monatssummen weisen „davon bar“ und „davon Kartenzahlung“ aus.
T2med-Rechnungen über den Drucker „SumUp-Rechnung“ stehen in T2med und sind nicht enthalten.
Patientennamen kommen nur hinein, wenn „Patientennamen aufnehmen“ angekreuzt ist; Testzahlungen nur im
Testmodus auf Wunsch. Die Tabelle öffnet sich in Excel, LibreOffice und Numbers mit Euro- und
Datumsformat, Filter in der Kopfzeile und Summenformeln.

#### T2med-Terminzettel

Der [T2med-Terminzettel](terminzettel/README.md) (übernommen aus
[thomaskien/t2med-terminzettel](https://github.com/thomaskien/t2med-terminzettel)) gehört jetzt dazu: In T2med
den Drucker **„Terminzettel“** wählen, dann kommt das Terminblatt als Bon aus dem Bondrucker, auf Wunsch
mit einem Kalender-QR je Termin. Der Server-Installer richtet ihn **ohne Rückfragen** mit ein (überspringen:
`--ohne-terminzettel`).

Unter **Einstellungen → Terminzettel**:

- Kopf (erste Zeile und Adressbereich), Überschrift, Termine und Endtext jeweils mit eigener **Schriftgröße**
  (Normal 1×1 bis Riesig 4×4; Kopf zusätzlich fett/normal).
- **Kalender-QR:** an/aus, **Position** rechts, links oder unter dem Termin, Größe klein/mittel/groß,
  Kalender-Titel.
- **Vorschau** rechts neben dem Formular: Sie zeigt mit Beispieldaten genau die Druckdaten, die auch an den
  Bondrucker gehen, und aktualisiert sich bei jeder Änderung, schon vor dem Speichern.
  „Beispiel-Terminzettel drucken“ gibt denselben Bon aus.
- Bondrucker und Papierbreite übernimmt der Terminzettel automatisch aus „Drucker einrichten“ bzw.
  „Bondrucker“ (TM-m10 58 mm, TM-m30III 80 mm). Ohne gespeicherte Angaben gilt eine vorhandene
  `/etc/terminzettel/config.toml` unverändert.

Arbeitsplätze: Windows über den IPP-Drucker `http://<server>:631/printers/Terminzettel` mit dem Treiber
„Microsoft IPP Class Driver“ (Papier A6), Mac mit dem PDF-Treiber, den die Einstellungsseite zum
Herunterladen anbietet. Der Installer gibt dafür CUPS-Drucker im Netz frei (`cupsctl --share-printers`;
sichtbar sind nur als freigegeben markierte Drucker). Außerdem legt der Terminzettel-Installer eine
Samba-Druckerfreigabe „Terminzettel“ an und stellt die Zwischendaten von CUPS und Samba auf RAM um
(Details in [terminzettel/README.md](terminzettel/README.md)).

#### Drucker im Browser einrichten

Unter **Einstellungen → Drucker einrichten** lassen sich Drucker an diesem Server ohne
Terminal anlegen:

1. **„Drucker im Netzwerk und per USB suchen“** – gefundene Drucker mit „Auswählen“ übernehmen,
   oder Art und IP-Adresse von Hand eintragen.
2. **Art wählen:** Bondrucker Epson TM-m10, Bondrucker Epson TM-m30III oder A4-Drucker
   (IPP/AirPrint, z. B. Brother, HP, Canon, Kyocera).
3. **„Drucker einrichten“** – die CUPS-Warteschlange entsteht (Bondrucker als RAW-Warteschlange,
   A4-Drucker treiberlos über IPP Everywhere, nicht im Netz freigegeben) und wird automatisch
   als Bon- bzw. Praxisdrucker eingetragen. Danach „Testdruck“.

Vorher prüft die Seite, ob der Drucker unter der Adresse antwortet (Port 9100 bzw. 631), und
meldet Fehler im Klartext. Vorhandene Warteschlangen (z. B. von Hand angelegt) können mit
„Verwenden“ übernommen werden; „Entfernen“ gibt es nur für hier angelegte. Beim TM-m30III im
Webmenü des Druckers „Sicherer Druck“ ausschalten. Ein Bondrucker per Bluetooth an einem
anderen Rechner bleibt eine Samba-Freigabe (Einstellungen → Bondrucker → Verbindung).

Damit sind alle Drucker – Bondrucker, A4-Drucker, „SumUp-Rechnung“ und der Terminzettel – ohne `sudo`
einzurichten. Dafür installiert der Server-Installer einmalig den Druckdienst `cups` und nimmt den Dienstbenutzer
`kienzle-sumup` in die Gruppe `lpadmin` auf (Verwaltung des lokalen Druckdienstes).

Admin-Passwort vergessen: `sudo bash scripts/install-server.sh --admin-reset` gibt einen neuen
Einrichtungslink aus. Die gespeicherten Einstellungen bleiben dabei erhalten.

Es entstehen eine eigene Apache-Instanz und ein eigener PHP-FPM-Dienst. Die Anwendung
verwendet die Distributionspakete, ihre eigene Konfiguration und eigene Laufzeitdateien.
Der Installer bearbeitet keine vorhandenen Apache-VirtualHosts. Die Paketverwaltung
kann beim erstmaligen Installieren von Apache/PHP deren Standarddienste aktivieren.

| Inhalt | Ort |
| --- | --- |
| Zentrale TOML-Konfiguration | `/etc/kienzle-sumup/kienzle-sumup.toml` |
| Anwendung | `/opt/kienzle-sumup` |
| Datenbank und Sperrdateien | `/var/lib/kienzle-sumup` |
| Serverzertifikat und Schlüssel | `/etc/kienzle-sumup/server.crt`, `server.key` |
| Apache-/PHP-Konfiguration | `/etc/kienzle-sumup/apache.conf`, `php-fpm.conf` |
| Fehlerprotokolle | `/var/log/kienzle-sumup` |
| Client-Einrichtungsordner | `/root/kienzle-sumup-clients` |

`php.log` enthält Anwendungsfehler, `fpm.log` die PHP-FPM-Dienstmeldungen und
`apache-error.log` die Apache-Fehler. Vorhandene Logs bleiben bei Updates erhalten.

```bash
sudo systemctl status kienzle-sumup-apache kienzle-sumup-php
```

Der Installer erzeugt ein selbstsigniertes RSA-Zertifikat mit 50 Jahren Laufzeit und
Servernamen bzw. IP im Subject Alternative Name. Vorhandene Zertifikate werden bei Updates
beibehalten. Das Zertifikat muss auf den Clients vertraut werden; eine lange Laufzeit
allein erzeugt kein Browservertrauen. TLS bleibt geprüft. Für t2med kann ein eigenes
öffentliches PEM-Zertifikat/CA hinterlegt werden. Keine privaten t2med-Schlüssel kopieren.

Für ein t2med-Zertifikat ohne passenden Hostnamen kann die Verbindung gezielt an dessen
öffentlichen Schlüssel gebunden werden. Dazu das **öffentliche Serverzertifikat** direkt
vom t2med-Server oder seiner lokalen Schnittstelle beziehen und geschützt als `ca_file`
hinterlegen. Hierfür genau ein Serverzertifikat verwenden; kein CA-Bundle und keine
ungeprüfte Übernahme aus einem fremden Netzwerk. Unter dem vorhandenen Abschnitt `[fhir]`:

```toml
ca_file = "/etc/kienzle-sumup/t2med-ca.pem"
pin_certificate = true
```

Nur bei dieser ausdrücklich aktivierten Option ersetzt die Schlüsselbindung den
Hostnamenvergleich für FHIR. Zertifikatsvertrauen und Gültigkeit bleiben geprüft;
ein anderer öffentlicher Schlüssel wird vor der Übertragung von Zugangsdaten abgelehnt.
Bei einem beabsichtigten Schlüsselwechsel die Zertifikatsdatei erneut sicher übertragen.
Standard ist `false` mit normaler Hostnamenprüfung. Der Installer erhält diese Einstellung.

Die Firewall für TCP 7868 nur im Praxisnetz öffnen. Der Installer ändert keine Firewall.

## Starter auf den Arbeitsplätzen

Am einfachsten: auf dem Arbeitsplatz die Einstellungsseite öffnen, anmelden und unter
**Arbeitsplätze einrichten** das Paket für Windows, macOS oder Linux laden. Die ZIP-Datei
enthält `client.json` (Serveradresse, Starter-Schlüssel, Zertifikat-Fingerabdruck), das
öffentliche Serverzertifikat, das Installationsskript, eine `LIESMICH.txt` und – sofern der
Server-Installer sie laden konnte – die geprüften Starterprogramme, sodass der Arbeitsplatz
nichts aus dem Internet laden muss. **Das Paket enthält den Starter-Schlüssel: nicht per
E-Mail verschicken, nach der Einrichtung löschen.**

Alternativ liegt derselbe Inhalt im Ordner `/root/kienzle-sumup-clients` auf dem Server
(geschützt kopieren, nicht ins Repository oder unter den Webroot legen).

Fehlen die Starterprogramme im Paket, laden die Installer den passenden Starter aus GitHub
Release `v1.4.1` und prüfen seine SHA-256-Prüfsumme. Python, Go und PHP werden auf dem
Arbeitsplatz nicht benötigt.

- **Windows 10/11:** ZIP mit Rechtsklick → „Alle extrahieren …“ entpacken, dann
  `install-windows.cmd` doppelklicken (SmartScreen: „Weitere Informationen“ → „Trotzdem
  ausführen“). Die Zertifikatsfrage mit „Ja“ bestätigen – dieses Fenster liegt manchmal hinter
  anderen Fenstern; scheint die Einrichtung zu hängen, in der Taskleiste nachsehen. Der Starter wird pro Benutzer unter
  `%LOCALAPPDATA%\Kienzle-SumUp` installiert. Das Zertifikat kommt in den Zertifikatsspeicher
  des aktuellen Benutzers.
- **macOS:** ZIP doppelklicken, `install-macos.command` mit Rechtsklick → „Öffnen“ starten.
  Eintrag in `~/Applications/Kienzle-SumUp.app`, Starter und Konfiguration in
  `~/Library/Application Support/Kienzle-SumUp`. Der Installer fügt das Zertifikat dem
  Anmeldeschlüsselbund hinzu; macOS kann eine Bestätigung verlangen.
- **Linux-Desktop:** `bash install-linux.sh` ausführen. Benötigt `curl` und `xdg-utils`.
  Installation unter `~/.local/share/kienzle-sumup` und Registrierung per `.desktop`-Datei.
  `server.crt` anschließend im verwendeten Browser/Vertrauensspeicher importieren.

Browser mit eigenem Zertifikatsspeicher können auch unter Windows/macOS einen eigenen
Import verlangen. Der Starter selbst prüft das exakte, bei der Einrichtung übertragene
Zertifikat einschließlich Name und Laufzeit. Er überträgt den Kontext per HTTPS an PHP,
öffnet einen einmal verwendbaren Browserlink und beendet sich. Es läuft kein lokaler Dienst.

## T2med-Button einrichten

Schritt für Schritt mit Bildern: [Anleitung „T2med einrichten“](docs/T2MED-EINRICHTUNG.md).

Die Platzhalter folgen dem bereits verwendeten Kienzledoku-Aufruf. Unter
**Drittanbieter-Zugriffe verwalten** eine zusätzliche URL mit dem Namen **Kienzle-SumUp**
anlegen und einem eigenen Menüleisten-Button zuweisen:

```text
kienzle-sumup://?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}
```

Der Starter registriert ausschließlich `kienzle-sumup://`. Vorhandene Registrierungen von
`kienzledoku://`, `whisperdoku://` und `T2demo://` bleiben erhalten. Für live werden die
zur Drittanbieterdefinition passenden t2med-FHIR-Zugangsdaten benötigt.

`fhir.launch_urls` enthält die exakten erlaubten Aufrufadressen. `fhir.base_url` ist das
vom PHP-Server erreichbare feste Ziel. Dadurch lässt sich eine im t2med-Aufruf enthaltene
Client-Loopback-Adresse explizit auf den tatsächlich erreichbaren Praxisserver abbilden.
Der Client kann keinen beliebigen FHIR-Server vorgeben.

Zur Fehlerhilfe: `app.base_url` ist der Hostname der Zahlungsseite, `fhir.base_url` die
feste FHIR-Zieladresse und `fhir.launch_urls` die Liste erlaubter t2med-Aufrufadressen.
Die Fehlermeldung zeigt die tatsächlichen Werte; an diesen sollte sich die Korrektur
orientieren. In der laufenden Installation nur `launch_urls` in
`/etc/kienzle-sumup/kienzle-sumup.toml` um die verifizierte, reine t2med-Adresse ergänzen;
dafür ist kein Dienstneustart nötig. Danach erneut aus t2med öffnen. Keine Schlüssel oder
vollständigen Token-Links teilen.

## SumUp Solo einrichten

Unter **Einstellungen → Betrieb und SumUp** werden fünf Werte aus deinem **SumUp-Händlerkonto** eingetragen:

| Feld | Bedeutung |
| --- | --- |
| SumUp API-Key | Geheimer Schlüssel für die API-Aufrufe deines Kontos. |
| SumUp Affiliate-Key | Kennzeichnet die Anbindung bei Kartenzahlungen. |
| SumUp App-ID | Zum Affiliate-Key hinterlegte Kennung; Vorgabe: `de.kienzle.sumup`. |
| SumUp Händlercode | Eindeutige Kennung der Praxis bei SumUp, beispielsweise `MK01A8C2`. |
| SumUp Reader-ID | API-ID des mit diesem Händlerkonto gekoppelten Solo. |

### API-Key und Affiliate-Key anlegen

1. Bei [SumUp](https://me.sumup.com) anmelden und über das Profil **Einstellungen →
   Für Entwickler / For Developers → Toolkit → API Keys** öffnen.
2. **Create / Erstellen** wählen, beispielsweise `kienzle-sumup` als Namen vergeben
   und den geheimen Key für die Eingabe im Installer aufbewahren. Der dort ebenfalls
   angezeigte **Public Key** ist hierfür ungeeignet.
3. Unter **Affiliate Keys** einen Schlüssel für die App-ID `de.kienzle.sumup` anlegen.
   Affiliate-Key und genau diese App-ID im Installer eintragen. Ein Affiliate-Key
   ersetzt den API-Key nicht.

Anleitungen von SumUp: [API-Key erstellen](https://developer.sumup.com/tools/authorization/api-keys),
[Affiliate-Key und App-ID](https://developer.sumup.com/tools/authorization/affiliate-keys).
Die eigenen geheimen Schlüssel ausschließlich im Server-Installer eingeben.
Der t2med-Demo-Key gilt nur für t2med und kann keinen SumUp-Key ersetzen.

### Händlercode ermitteln

Der Händlercode (`merchant_code`) bezeichnet dein Unternehmen bei SumUp und sieht
beispielsweise wie `MK01A8C2` aus. Er gehört zu dem Händlerkonto, für das der API-Key
erstellt wurde. [SumUp: Händler und Händlercode](https://developer.sumup.com/tools/glossary/merchant)

Mit deinem API-Key lässt sich das zugehörige Profil auf dem Linux-Server in Bash
abfragen. In der JSON-Antwort nach `merchant_code` suchen:

```bash
read -r -s -p 'SumUp API-Key: ' SUMUP_API_KEY
printf '\n'
curl --fail --silent --show-error https://api.sumup.com/v0.1/me \
  -H "Authorization: Bearer $SUMUP_API_KEY"
```

Diese [Profilabfrage](https://developer.sumup.com/tools/authorization/api-keys#authorize-requests-with-an-api-key)
verwendet denselben API-Key wie der Installer.

### Solo koppeln und Reader-ID übernehmen

1. Solo mit WLAN verbinden und bei einem angemeldeten Händlerkonto zunächst am
   Gerät abmelden: **Einstellungen → Über / About → Abmelden**.
2. Im Gerätemenü **Verbindungen / Connections → API → Verbinden / Connect** öffnen.
   Der angezeigte Pairing-Code ist fünf Minuten gültig.
3. Den Reader einmal über die API mit dem Händlerkonto verbinden. In derselben
   Bash-Sitzung den Händlercode eingeben und `PAIRINGCODE` im Befehl ersetzen:

```bash
read -r -p 'SumUp Händlercode: ' SUMUP_MERCHANT_CODE
curl --fail --silent --show-error \
  "https://api.sumup.com/v0.1/merchants/$SUMUP_MERCHANT_CODE/readers" \
  -H "Authorization: Bearer $SUMUP_API_KEY" \
  -H 'Content-Type: application/json' \
  --data '{"pairing_code":"PAIRINGCODE","name":"Praxis Solo"}'
```

4. **`id` aus der Antwort** als Reader-ID in den Installer übernehmen. Pairing-Code,
   Seriennummer und Händlercode sind andere Werte.

Bereits gekoppelte Reader lassen sich ohne erneute Kopplung abfragen:

```bash
curl --fail --silent --show-error \
  "https://api.sumup.com/v0.1/merchants/$SUMUP_MERCHANT_CODE/readers" \
  -H "Authorization: Bearer $SUMUP_API_KEY"
unset SUMUP_API_KEY SUMUP_MERCHANT_CODE
```

Bei mehreren Geräten die `id` des gewünschten Readers anhand seines Namens auswählen.
Details: [Solo-Kopplung](https://developer.sumup.com/terminal-payments/cloud-api#pairing-solo-reader-via-cloud-api),
[Reader anlegen und auflisten](https://developer.sumup.com/api/readers).
Der Installer übernimmt die Reader-ID; er führt die Kopplung nicht selbst durch.

### Betrieb und Belege

Das Solo muss online sein; SumUp empfiehlt für die Cloud API eine dauerhafte
Stromversorgung. WLAN und eine für die Cloud API geeignete Firmware/Kontofreigabe
sind nötig. [SumUp Cloud API](https://developer.sumup.com/terminal-payments/cloud-api)

Die Anwendung bietet PDF-Druck und optionalen Direktdruck auf den TM-m10. Einen automatischen
Druckdialog am Solo oder dessen Druckstation löst sie nicht aus. E-Mail-Belege werden
auf Klick über den im Installer eingerichteten SMTP-Server verschickt.

Die Anwendung sendet ausschließlich Betrag, EUR, neutrale Referenz und erforderliche
technische Affiliate-Metadaten an SumUp. Patient und Leistungsbezeichnungen werden nie
an SumUp gesendet. Keine Kartendaten werden lokal gespeichert.

## Fehler und erneute Versuche

- Startanfragen mit derselben lokalen ID geben den bestehenden Zahlungsversuch zurück.
  Eine neue ID startet ebenfalls keine zweite Zahlung für denselben offenen Vorgang.
- Solange ein Terminalvorgang ungeklärt ist, startet die Anwendung dort keine neue Zahlung.
- Ist das Terminal mit einem anderen Vorgang belegt, zeigt die Leistungsauswahl dessen
  Betrag und neutrale Referenz. **Anderen Vorgang abbrechen** fordert den Abbruch an;
  **Status prüfen** gleicht den Stand erneut ab. Die offene Seite prüft auch automatisch.
  Sobald der alte Vorgang geklärt ist, lässt sich die ausgewählte neue Zahlung starten.
  Ein veralteter Klick bricht keinen inzwischen neu gestarteten Vorgang ab.
- Vor jedem Abbruch wird der Zahlungsstatus frisch abgefragt. Eine inzwischen erfolgreiche
  Zahlung bleibt erhalten und muss im zugehörigen Vorgang dokumentiert werden.
  SumUp verarbeitet den [Terminalabbruch zeitversetzt](https://developer.sumup.com/api/readers);
  das Terminal muss online sein und noch auf eine Kartenaktion warten. Bei fehlender
  Bestätigung kann der Abbruch erneut angefragt werden. Es gibt keinen automatischen Neustart.
- Ein Timeout ist kein Zahlungsfehlschlag. Die Seite gleicht über die gespeicherte
  SumUp-ID oder die vor dem Start gespeicherte neutrale Referenz ab.
- Erfolg wird über den Transaktionsstatus mit passendem Händler, Referenz, Betrag und EUR
  bestätigt. Ein angenommener Abbruchauftrag allein gilt noch nicht als Abbruch.
- FHIR-Schreibzugriffe erfolgen ausschließlich durch den Dokumentationsbutton; Polling schreibt nie.
- Bei eindeutiger Ablehnung bleibt die Zahlung bezahlt und der Button erlaubt einen neuen
  Dokumentationsversuch. Nach bestätigtem Schreiben wird kein zweiter Eintrag erzeugt.
- Wenn die FHIR-Antwort verloren geht, prüft ein erneuter Klick zuerst den vorhandenen
  Eintrag anhand der Zahlungsreferenz. Unterstützt t2med laut CapabilityStatement
  bedingtes Anlegen, kann ein Wiederholungsaufruf damit dedupliziert werden. Andernfalls
  bleibt ein ungeklärter Schreibversuch offen, bis der passende Eintrag nachweisbar ist;
  er wird nicht blind erneut geschrieben. Ein einfacher erfolgreicher POST funktioniert
  auch ohne Unterstützung für bedingtes Anlegen.
- Ein nicht auflösbarer SumUp-Vorgang oder eine unklare t2med-Übertragung muss anhand der
  tatsächlichen Transaktion/Akte geprüft werden. Es gibt bewusst keinen Button, der eine
  Zahlung ohne Bestätigung als bezahlt markiert oder die Schutzsperre pauschal zurücksetzt.

Patientenkontext und Token sind einer Sitzung zugeordnet. Tokens werden verschlüsselt
in SQLite gespeichert und beim Abschluss gelöscht; abgelaufene Tokens werden beim nächsten
Start entfernt. Browser erhalten nur eine Sitzung, keine SumUp-/FHIR-Schlüssel. Einmalige
Starttickets laufen nach 90 Sekunden ab und werden aus der Adresszeile entfernt.
Die App ist für eine Praxis mit einem Terminal gedacht; keine komplexe Benutzerverwaltung.
Alle berechtigten Starter-Benutzer können Leistungen verwalten.

## Sicherheit

Das Programm ist für den Betrieb im Praxisnetz gedacht und nicht für das offene Internet. Der Server-Installer
sichert es trotzdem mehrfach ab:

- **Anmeldung:** Das Admin-Passwort (mind. 10 Zeichen, offensichtliche wie „passwort123“ werden abgelehnt) ist mit
  Argon2id gespeichert. Nach 5 falschen Versuchen wird nur das **absendende Gerät** gesperrt (5 Minuten, bei
  Wiederholung bis zu 1 Stunde); die Praxis kann sich weiter anmelden. Eine Gesamtbremse pausiert Anmeldungen kurz,
  falls ein Angriff aus vielen Adressen kommt. Sitzungen laufen nach 30 Minuten Leerlauf und spätestens nach 12 Stunden
  ab. Gegen Seitenangriffe schützen `SameSite=Strict`, ein CSRF-Schlüssel je Sitzung und die Prüfung, dass Anfragen
  von dieser Seite selbst kommen (`Sec-Fetch-Site`, `Origin`).
- **Schlüssel:** Falsche Starter- oder Rechnungseingangs-Schlüssel werden je Absender gedrosselt (10 Fehler → 10 Minuten).
- **Protokoll:** Unter Einstellungen → Einrichtung → Sicherheit → Sicherheitsprotokoll stehen Anmeldungen, Fehlversuche und
  Änderungen mit Zeit und Geräteadresse – nie mit Passwörtern oder Patientendaten (Datei
  `/var/lib/kienzle-sumup/admin-audit.log`, auf 256 KB begrenzt).
- **Webserver:** nur TLS 1.2/1.3 mit starken Verfahren, Schutz vor langsamen Verbindungen und überlangen Anfragen,
  nur GET/POST/HEAD, keine versteckten Dateien, Sicherungen oder Konfigurationen, Header gegen Einbettung in fremde Seiten
  (`X-Frame-Options`, CSP `frame-ancestors`), Browser-Funktionen wie Kamera und Mikrofon gesperrt.
  Bewusst **kein HSTS**: Das Zertifikat ist selbst ausgestellt, HSTS würde die Seite bei einem Zertifikatswechsel sperren.
- **Dienste:** Webserver und PHP laufen als eigener Benutzer ohne Rechte, in einer Systemd-Sandbox
  (keine neuen Rechte, kein Zugriff auf Home, Kernel und Geräte, nur Netzwerk- und Unix-Sockets). PHP darf keine
  Shell-Befehle absetzen (nur der Druckaufruf ohne Shell), keine URLs per `fopen` laden.
- **Daten:** Kassendaten, Schlüssel und Einstellungen liegen verschlüsselt bzw. nur für den Dienstbenutzer lesbar;
  es gibt kein Zugriffsprotokoll des Webservers.

Empfehlungen für die Praxis: Den Server nicht aus dem Internet erreichbar machen (kein Port 443 am Router weiterleiten),
den Zugang nur im Praxisnetz erlauben (z. B. `sudo ufw allow from 192.168.0.0/24 to any port 443`, Adresse anpassen),
Passwort nur im Passwortmanager aufbewahren, das Arbeitsplatz-Paket nach der Einrichtung löschen und Aktualisierungen
einspielen. Ein Sicherheitsproblem bitte nicht öffentlich melden, sondern per E-Mail an die Praxis des Betreibers.

## Netzwerk und Firewall im Webinterface

Unter **Einstellungen → Einrichtung → Arbeitsplätze & Netzwerk → Netzwerk & Firewall** fragt der Server sein eigenes Netz ab (`ip`, `ss`):
Anschlüsse mit Adresse und Netz, Router, zuletzt gesehene Geräte und von außen erreichbare Ports. Das Netz, aus dem du
gerade zugreifst, ist vorgewählt. Du kreuzt an, aus welchen Netzen zugegriffen werden darf (weitere Netze, z. B. ein
VPN, trägst du als `192.168.2.0/24` ein), und klickst **Firewall anwenden**.

- Erlaubt sind nur **private Netze** (10.x, 172.16–31.x, 192.168.x, 100.64–127.x, `fc00::/7`); `0.0.0.0/0` oder öffentliche
  Adressen werden abgelehnt.
- **Schutz vor Aussperren:** Das Gerät, mit dem du gerade arbeitest, muss in einem freigegebenen Netz liegen. Nach dem
  Laden hast du **2 Minuten**, mit „Ja, alles funktioniert“ zu bestätigen. Ohne Bestätigung (z. B. weil du ausgesperrt
  wärst) stellt der Server die vorherigen Regeln selbst wieder her. Erst bestätigte Regeln werden dauerhaft gespeichert
  und beim Start geladen.
- Geschützt werden die Weboberfläche, optional **SSH** (Port wird erkannt) und die **Druckfreigabe 631** (Terminzettel-Drucker
  für T2med). Andere Dienste des Servers bleiben unverändert, außer du wählst „Alles andere von außen sperren“.
- **Weitere Dienste:** Die Karte kennt die Ports der Kienzle-Projekte (Ports laut deren Dokumentation) und wählt vor, was auf
  diesem Server schon lauscht:

  | Dienst | Port | Zugriff |
  | --- | --- | --- |
  | Kienzlefon-Middleware (`kienzlefon-t2med`) | 8766/tcp | Praxisnetz |
  | Telefonanlage: Manager-Schnittstelle (AMI) | 5038/tcp | Praxisnetz |
  | Telefonanlage: SIP für Telefone | 5060/tcp und udp | Praxisnetz |
  | Telefonanlage: Sprachdaten (RTP) | 10000–20000/udp (Asterisk-Standard, im Projekt nicht festgelegt) | Praxisnetz |
  | Kienzlefon-Weboberfläche | 8443/tcp (TLS), 8088/tcp | Praxisnetz |
  | WireGuard-VPN (KI-Anbindung) | 51820/udp | von überall |
  | Kienzlefon-App, Quellserver (nginx) | 80 und 443/tcp | von überall |

  Angekreuzte Dienste sind nur aus den freigegebenen Netzen erreichbar, WireGuard und die App-Anbindung aus dem Internet
  (sie kommen nicht aus dem Praxisnetz). Weitere Ports lassen sich unter „Eigene Ports“ eintragen (`tcp 8080`, `udp 5000-5100`).
  Bei „Alles andere von außen sperren“ nennt die Seite vor dem Anwenden alle lauschenden Ports, die dadurch gesperrt würden.
  Läuft die Telefonanlage auf einem anderen Rechner, gilt deren Firewall; `kienzlefon` pflegt dafür bei aktivem UFW eine eigene Regel.
- Arbeitsplätze, Drucker, Druckserver und der T2med-Rechner müssen in den freigegebenen Netzen liegen. Die Liste der
  zuletzt gesehenen Geräte hilft beim Prüfen.
- Technik: Die Anwendung legt nur eine Anfragedatei ab. Der root-Dienst `kienzle-sumup-firewall`
  (`scripts/firewall-apply.py`) prüft sie streng und erzeugt die Regeln aus festen Vorlagen in einer eigenen
  nftables-Tabelle `kienzle_sumup`; andere Regeln des Servers bleiben unberührt. Ausschalten: Knopf „Firewall ausschalten“
  oder `sudo nft delete table inet kienzle_sumup` und `sudo rm /etc/kienzle-sumup/firewall.nft`. Läuft zusätzlich `ufw`,
  gelten dessen Regeln weiter; eine Sperre dort kann diese Freigabe nicht aufheben.

## Aktualisieren im Webinterface

Unter **Einstellungen → Einrichtung → Aktualisierung** steht die installierte Version, die Quelle und die
neueste Version auf GitHub (geprüft alle sechs Stunden oder mit „Jetzt auf neue Version prüfen“). Ist eine
neuere Version da, erscheint eine Glocke in der Reiterleiste; die Karte listet die Neuerungen aus
`docs/RELEASE.md`. **„Jetzt aktualisieren“** startet die Aktualisierung – ohne Terminal:

1. Die Einstellungsseite legt nur eine Anfrage ab (`/var/lib/kienzle-sumup/update-request`).
2. Der root-Dienst `kienzle-sumup-update` (ausgelöst über `kienzle-sumup-update.path`) lädt den Stand
   des eingestellten Zweigs von GitHub, prüft die Versionsangabe, sichert Programm und Datenbank und
   führt den Server-Installer ohne Fragen aus.
3. Schlägt der Installer fehl, werden Programm und Datenbank automatisch wiederhergestellt und die
   Dienste gestartet. Fortschritt und Protokoll zeigt die Karte; danach lädt die Seite neu.

Während einer laufenden Kartenzahlung lässt sich nicht aktualisieren. Kartenzahlung und Einstellungen sind
während der Installation einige Minuten nicht erreichbar – am besten außerhalb der Sprechzeit.

Die Quelle steht in `/etc/kienzle-sumup/update.conf` (nur root kann sie ändern; die Anwendung kann
weder Quelle noch Befehle bestimmen). Der Installer übernimmt sie beim ersten Lauf aus dem Git-Ordner,
aus dem installiert wird, z. B.:

```text
repo=hau-si/kienzle-suite-beta
branch=rechnungseingang
```

Für einen anderen Zweig oder das Originalprojekt (`repo=thomaskien/t2med-sumup`, `branch=main`) die
Datei mit `sudo nano /etc/kienzle-sumup/update.conf` anpassen. Protokoll der letzten Aktualisierung:
`/var/lib/kienzle-sumup-update/update.log`. Wer mit `--ohne-terminzettel` installiert hat, behält das auch
bei Aktualisierungen (`--mit-terminzettel` schaltet ihn wieder ein).

## TOML und Updates

Die Konfiguration verwendet einen kleinen, strikt geprüften TOML-Teilumfang: einfache
Tabellen, einzeilige Strings, boolesche Werte, positive Dezimal-Ganzzahlen und einzeilige
Arrays aus doppelt zitierten Strings. Beispiel: `config/kienzle-sumup.example.toml`.
Kein INI-Parser, keine automatische Typumwandlung bei Geldbeträgen.

Bei der ersten Einrichtung der t2med-Anbindung übernimmt ein leeres Feld beim
FHIR API-Key den hinterlegten Demo-Key. Ein eingegebener eigener Schlüssel hat Vorrang.
Die Einstellungen aus dem Browser überlagern die TOML-Datei; Updates verändern sie nicht.

Nach manuellen Änderungen den Installer erneut ausführen, damit Apache-Port und
Clientkonfiguration zur TOML passen. Bestehende API-Schlüssel, Verschlüsselungsschlüssel,
Datenbank und Serverzertifikat bleiben bei gewöhnlichen Updates erhalten. Nach Änderung
von Serveradresse, Starter-Schlüssel oder Zertifikat die Clients erneut einrichten.

Vor Updates offene Zahlungen abschließen. Anwendung aktualisieren und Installer ausführen:

```bash
git pull --ff-only
sudo bash scripts/install-server.sh
```

Für Sicherungen die SQLite-Backup-Funktion verwenden (oder die eigenen Dienste kurz stoppen)
und `/var/lib/kienzle-sumup` sowie `/etc/kienzle-sumup` geschützt sichern. Der
Verschlüsselungsschlüssel wird zum Lesen offener Sitzungstokens benötigt.

## Lokaler Test ohne Terminal und t2med

PHP 8.1+ mit `curl`, `pdo_sqlite`, `mbstring` und `sodium`; für PDFs außerdem
`rsvg-convert` (Linux: `librsvg2-bin`, macOS: Homebrew `librsvg`):

```bash
php scripts/dev.php
```

`http://127.0.0.1:7868` öffnen, **Testpatient öffnen** wählen und Leistungen anlegen.
Nach dem Kassierklick über **Zahlung erfolgreich** oder **Zahlung abgelehnt** simulieren.
Ein t2med-Fehler lässt sich direkt auf der Seite ein-/ausschalten. Testeinträge werden nur
lokal in `mock_records` gespeichert. Entwicklungsmodus erlaubt keine echten Schnittstellen.

```bash
php tests/critical.php
php tests/invoice.php
php tests/t2med-invoice.php
php tests/letterxpress.php
cd client && go test ./...
```

Die wenigen Tests decken Betrag/Snapshot, Zugriffsbindung, Doppelaufrufe, verlorene
Zahlungsantworten und wiederholbare manuelle Dokumentation ab. Starter aus Quellen bauen:

```bash
bash scripts/build-client.sh
```

GitHub Actions prüft diese Abläufe und baut die Starter für Windows, macOS und Linux.
Ein Tag `v1.4.1` veröffentlicht die Pakete und Prüfsummen automatisch als GitHub Release.

## Stand der Integration

Die t2med-Profile und Header wurden aus der vorhandenen Kienzledoku-FHIR-Anbindung
übernommen; SumUp-Endpunkte aus der offiziellen Referenz (September 2026). Automatisierte
Prüfungen ersetzen keinen Test mit dem konkreten Solo und der t2med-Installation.
Insbesondere die Suche nach Zahlungskennungen und bedingtes Anlegen sind serverabhängig;
ein CapabilityStatement kann fehlen. Vor Nutzung mit echten Patienten den gesamten Ablauf
mit einem vorgesehenen Testkontext prüfen. Details der tatsächlichen Ausführung stehen in
`docs/VALIDATION.md`.

## Abmeldung und Mitarbeiter-Zugang

In den Einstellungen unter „Einrichtung → Sicherheit → Abmeldung und Mitarbeiter-Zugang“ lassen sich die Abmeldung bei
Inaktivität (Standard 30 Minuten) und die Höchstdauer einer Anmeldung (Standard 12 Stunden) einstellen, jeweils bis zu
6 Monaten (Minuten, Stunden oder Tage). Außerdem gibt es
einen **Mitarbeiter-Zugang** mit eigenem Passwort (gleiche Anmeldeseite, anderes Passwort). Er sieht nur die angekreuzten
Bereiche: Bezahlt-Liste abhaken, Rechnungseingang ansehen/löschen, Rechnungen per Post senden, Leistungen bearbeiten. Er
hat keinen Zugriff auf Schlüssel, Zugangsdaten, Drucker-, Netzwerk- und Update-Einstellungen. Rechte gelten sofort.

## Update-Kanal (Stabil / Beta)

In den Einstellungen unter „Aktualisierung“ steht das Kästchen „Beta-Versionen abonnieren“. Ohne Häkchen gilt **Stabil**, mit Häkchen **Beta**. **Stabil** holt Updates aus dem Hauptzweig des Projekts
(`thomaskien/t2med-sumup`, Zweig `main`) und ist der Standard bei einer Neuinstallation. **Beta** holt sie aus diesem Fork
(`hau-si/kienzle-suite-beta`, Zweig `rechnungseingang`) und bringt neue Funktionen früher. Ein Wechsel von Beta zu Stabil installiert
nie eine ältere Version; es wird erst wieder aktualisiert, wenn der Hauptzweig eine neuere Version hat.

## Gerät merken

Beim Anmelden gibt es das Häkchen „Dieses Gerät merken“. Das Gerät bleibt dann bis zu 6 Monate angemeldet (nach 30 Tagen
ohne Nutzung endet es), aber nur im selben Netz (IPv4 /24, IPv6 /64) wie bei der Anmeldung. Das Cookie ist HttpOnly,
Secure und SameSite=Strict. Auf einem gemerkten Gerät verlangen heikle Änderungen (Einstellungen speichern, Update,
Firewall, Zugang, Druckeinrichtung) das Passwort erneut; Pakete mit Schlüsseln lassen sich nur nach einer Anmeldung ohne
Häkchen laden. Unter „Abmeldung und Mitarbeiter-Zugang“ stehen alle gemerkten Geräte; einzeln oder alle lassen sie sich
abmelden. Ein geändertes Admin-Passwort meldet alle Geräte ab.

## Lizenz

[MIT-Lizenz](LICENSE). Diese Beta-Fassung ist ein Fork von [thomaskien/t2med-sumup](https://github.com/thomaskien/t2med-sumup);
dort sind das Hauptrepository und die ursprüngliche Fassung zu finden.
