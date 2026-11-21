# Versionierung

Bei jeder Änderung die Versionsnummer erhöhen, auch bei Fehlerbehebungen und
Dokumentationsänderungen. Zusammengehörige Änderungen eines Auftrags erhalten
gemeinsam eine neue Version; keine Änderungen unter einer bereits veröffentlichten
Versionsnummer ausliefern.

`VERSION`, die aktuellen Versionsangaben in `README.md` und `docs/RELEASE.md`, die
Starter-Ausgabe in `client/main.go` und die Release-Download-URLs in
`client/install-linux.sh`, `client/install-macos.command` und
`client/install-windows.ps1` gemeinsam aktualisieren. Historische Versionsangaben
in Prüfberichten sowie Versionsnummern fremder APIs beibehalten.
Bei einer Veröffentlichung einen neuen passenden Git-Tag `v<Version>` verwenden;
vorhandene Release-Tags niemals verschieben. Der Release-Workflow baut die Starter
und veröffentlicht sie nach bestandenen Prüfungen. Vorher sicherstellen, dass
Starter, Download-URLs und `VERSION` dieselbe Version nennen.

# Fork mit T2med-Rechnungseingang

Versionsnummern dieses Forks haben die Form `1.4.1-rechnung.N`. Die Starter (`client/`)
sind unverändert; `client/main.go` und die Release-Download-URLs der Client-Installer
bleiben auf `v1.4.1` des Originalprojekts, solange der Starter nicht geändert wird.
In diesem Fork keine Tags `v*` setzen, sonst baut und veröffentlicht der Workflow
eigene Starter-Releases.
