# T2med einrichten: Button „Kartenzahlung“ in der Funktionsleiste

Einmalige Einrichtung in T2med. Die Bilder sind nachgezeichnet, mit Beispieldaten und Platzhalter-Adresse.
Die Aufruf-Adresse steht im Adminpanel unter *Einrichtung → T2med-Anbindung* (zum Kopieren).

## 1. Drittanbieter-Zugriff anlegen

1. In T2med die Suche öffnen und **„Drittanbieter-Zugriffe verwalten“** eingeben, Ergebnis anklicken.
   Fehlt die Funktion, muss ein Administrator sie für den Benutzer erst freischalten.

   ![Suche](screenshots/t2med-1-suche.png)

2. In der Liste den Eintrag über den **Stift** bearbeiten.

   ![Liste](screenshots/t2med-2-drittanbieter-liste.png)

3. Unter „Neue URL hinzufügen“ die **Adresse** (genau so, mit den Platzhaltern)

   ```text
   kienzle-sumup://?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}
   ```

   und eine **Bezeichnung** (z. B. „Kartenzahlung“) eintragen, auf **Plus** klicken, dann oben rechts mit dem **Häkchen** speichern.
   Danach in der Liste davor die **Aktualisieren-Pfeile** anklicken.

   ![URL](screenshots/t2med-3-url.png)

## 2. Schaltfläche in der Funktionsleiste anlegen

4. **Funktionsleisten verwalten** öffnen, eine Konfiguration anlegen oder bearbeiten.

   ![Funktionsleisten](screenshots/t2med-4-funktionsleisten.png)

5. Name, Benutzer, Benutzergruppe und Station festlegen, dann **Aktion hinzufügen**.

   ![Konfiguration](screenshots/t2med-5-konfiguration.png)

6. Die Aktion über die vergebene **Bezeichnung** suchen, Farbe und Kürzel (oder Icon) wählen, bestätigen.

   ![Aktion](screenshots/t2med-6-aktion.png)

Jetzt erscheint die Schaltfläche beim Patienten. Ein Klick schickt die Patientendaten per FHIR an die Suite.
Der Starter-Aufruf funktioniert erst, wenn auf dem Arbeitsplatz das Starter-Paket installiert ist (Adminpanel, Karte „Arbeitsplätze einrichten"). Die FHIR-Adresse, die T2med mitgibt, muss unter „Erlaubte fhirBasisUrl aus T2med“ stehen; bei einer Fehlermeldung zeigt die Seite die tatsächlichen Werte.

## 3. Drucker „SumUp-Rechnung“

7. In den T2med-Einstellungen unter **Privatabrechnung → Drucker und Schacht** den Drucker **„SumUp-Rechnung“** eintragen
   (Papier A4; der Drucker muss am Arbeitsplatz installiert sein, siehe Adminpanel).

   ![Privatrechnung-Drucker](screenshots/t2med-7-privatrechnung-drucker.png)

8. In **Rechnungen verwalten** die Rechnung öffnen und oben rechts auf das **Drucker-Symbol** klicken. Die Rechnung wird nicht
   gedruckt, sondern geht an den Server und erscheint im Rechnungseingang und beim Patienten zum Kassieren.

   ![Rechnung drucken](screenshots/t2med-8-rechnung-drucken.png)

## 4. Terminzettel

- Für den Terminzettel in T2med unter *Einstellungen → Kalender → Terminliste (DIN A6)* den Drucker **„Terminzettel“** wählen.

Die Druckeradressen zeigt das Adminpanel unter *Einrichtung → Überblick*.
