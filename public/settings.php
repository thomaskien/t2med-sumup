<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$version = htmlspecialchars(trim(file_get_contents(dirname(__DIR__) . '/VERSION')), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kienzle-SumUp · Einstellungen</title><link rel="stylesheet" href="/assets/style.css?v=<?= $version ?>"><script src="/assets/goae-calc.js?v=<?= $version ?>" defer></script><script src="/assets/settings.js?v=<?= $version ?>" defer></script></head>
<body><main class="settings-main">
<header class="top"><div class="brand"><span class="mark" aria-hidden="true">K</span><div><strong>kienzle-sumup</strong><span>Einstellungen</span></div></div><span id="mode" class="badge" hidden></span></header>
<p id="message" class="notice" role="status" hidden></p>

<section id="setup" class="card" hidden><h1>Ersteinrichtung</h1>
<p>Lege das Admin-Passwort für diese Einstellungsseite fest. Arbeitsplätze kassieren weiterhin ohne Passwort.</p>
<form id="setup-form"><label>Admin-Passwort (mind. 10 Zeichen)<input id="setup-password" type="password" autocomplete="new-password" minlength="10" required></label>
<label>Passwort wiederholen<input id="setup-password2" type="password" autocomplete="new-password" minlength="10" required></label>
<button class="primary wide" type="submit">Passwort festlegen</button></form>
<p id="setup-missing" class="inline-error" hidden>Dieser Link ist unvollständig. Bitte den Einrichtungslink aus dem Installer verwenden oder einen neuen erzeugen: <code>sudo bash scripts/install-server.sh --admin-reset</code></p></section>

<dialog id="confirm-dialog" class="card"><form method="dialog" id="confirm-form"><h2>Passwort bestätigen</h2><p class="muted small">Dieses Gerät ist gemerkt. Für diese Änderung bitte das Admin-Passwort eingeben.</p><label>Admin-Passwort<input id="confirm-password" type="password" autocomplete="current-password" required></label><div class="row-buttons"><button class="primary" value="ok" type="submit">Bestätigen</button><button class="secondary" value="cancel" type="button" id="confirm-cancel">Abbrechen</button></div></form></dialog>
<section id="login" class="card" hidden><h1>Anmelden</h1>
<form id="login-form"><label>Admin-Passwort<input id="login-password" type="password" autocomplete="current-password" required></label>
<label class="check"><input id="login-remember" type="checkbox"> Dieses Gerät merken (bis zu 6 Monate, nur in diesem Netz)</label>
<button class="primary wide" type="submit">Anmelden</button></form>
<p class="muted small">Passwort vergessen? Auf dem Server <code>sudo bash scripts/install-server.sh --admin-reset</code> ausführen.</p></section>

<div id="settings" hidden>
<nav id="tabs" class="tab-bar" role="tablist" aria-label="Bereiche"><a id="update-bell" class="bell" href="#s-update" title="Aktualisierung" aria-label="Aktualisierung"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 3a6 6 0 0 0-6 6v3.6L4.5 16v1h15v-1L18 12.6V9a6 6 0 0 0-6-6zm-2 15a2 2 0 0 0 4 0z" fill="currentColor"/></svg><span class="bell-dot"></span></a></nav>
<p id="warning" class="inline-error" hidden></p>
<div id="tab-panels"></div>
<div id="sections" hidden></div>
<section class="card settings-card" id="s-checklist"><h2>Einrichtung – Überblick</h2><p class="muted">Diese Schritte sind nur einmal nötig, geordnet nach dem Ort, an dem sie erledigt werden. Ein Klick auf einen Punkt öffnet die passende Einstellung.</p><div id="checklist"></div></section>
<section class="card settings-card" id="s-printers"><h2>Drucker einrichten</h2>
<p class="muted">Bondrucker (Epson TM-m10, TM-m30III) und A4-Drucker im Netzwerk oder per USB an diesem Server einrichten – ohne Terminal.</p>
<div id="printers-unavailable" class="inline-error" hidden>Der Druckdienst fehlt auf diesem Server. Bitte einmal <code>sudo bash scripts/install-server.sh</code> ausführen.</div>
<div id="printers-body">
<h3>Eingerichtete Drucker</h3><div id="printer-list"></div>
<h3>Neuen Drucker hinzufügen</h3>
<div class="row-buttons"><button id="printer-search" class="secondary" type="button">Drucker im Netzwerk und per USB suchen</button></div>
<div id="printer-found"></div>
<form id="printer-form" class="settings-form"></form>
</div></section>
<section class="card settings-card" id="s-t2med-paid"><h2>T2med-Rechnungen als bezahlt abhaken</h2>
<p>Alle über den Drucker „SumUp-Rechnung“ übernommenen und per Karte bezahlten T2med-Rechnungen, nach Rechnungsnummer sortiert. In T2med unter Rechnungen die Nummern als bezahlt markieren und hier anschließend abhaken – dann erscheinen beim nächsten Mal nur die neuen. Mit „Korrigieren“ lässt sich eine Zahlung nachträglich stornieren oder der Betrag für Statistik und Excel ändern (keine Erstattung bei SumUp).</p>
<div id="t2med-paid" class="settings-form"></div></section>
<section class="card settings-card" id="s-export"><h2>Export für die Steuerberatung</h2>
<p>Alle hier im Rechnungstool erstellten und bezahlten Rechnungen (bar und Karte, in der Spalte „Zahlungsart“ gekennzeichnet) als Excel-Tabelle – für ein ganzes Jahr oder einen Monat. T2med-Rechnungen sind nicht enthalten; sie stehen in T2med.</p>
<form id="export-form" class="settings-form export-form"></form>
<p class="muted small">Die Datei hat drei Blätter: <strong>Rechnungen</strong> (je Rechnung Brutto, Netto und MwSt. je Satz), <strong>Positionen</strong> (jede Leistung einzeln) und <strong>Monatssummen</strong>. Patientennamen nur, wenn angekreuzt.</p></section>
<section class="card settings-card" id="s-clients"><h2>Arbeitsplätze einrichten</h2>
<p>Auf dem Arbeitsplatz diese Seite öffnen, anmelden und das passende Paket laden. Es enthält den Starter, mit dem T2med die Kartenzahlung öffnet.</p>
<div id="client-downloads" class="row-buttons"></div>
<div id="client-steps" class="steps"></div>
<p class="muted small">Die Pakete enthalten den Starter-Schlüssel der Praxis: nicht per E-Mail verschicken, nach der Einrichtung löschen.</p></section>
<section class="card settings-card" id="invoice-printer-help" hidden><h2>Drucker „SumUp-Rechnung“</h2>
<div id="invoice-printer-status"></div>
<details class="tz-how"><summary>Drucker auf einem anderen Druckserver (z. B. kienzlebox)</summary>
<p class="muted small">Nur nötig, wenn T2med auf einem anderen Rechner druckt als diesem: Einrichtungsdatei herunterladen, geschützt dorthin kopieren und dort im Projektordner <code>sudo bash scripts/install-invoice-printer.sh /pfad/kienzle-sumup-drucker.json</code> ausführen.</p>
<button id="printer-setup" class="secondary" type="button">Einrichtungsdatei herunterladen</button></details></section>
<section class="card settings-card" id="s-inbox-list"><h2>Rechnungseingang</h2>
<p class="muted">Von T2med auf „SumUp-Rechnung“ gedruckte Rechnungen. Mit Name und Geburtsdatum des Patienten (nur nach Anmeldung sichtbar). Nicht bezahlte Rechnungen lassen sich löschen. „Offen“ heißt: Wird beim passenden Patienten zum Kassieren angeboten.</p>
<div class="row-buttons"><button id="inbox-load" class="secondary" type="button">Aktualisieren</button></div>
<div id="inbox-box"><p class="muted">Wird geladen …</p></div></section>
<section class="card settings-card" id="s-catalog"><h2>Leistungen und GOÄ-Positionen</h2>
<p class="muted">Die an der Kasse auswählbaren Leistungen. Mit GOÄ-Ziffer, Faktor und Gebührenrahmen für den Beleg; der GOÄ-Rechner stellt den Faktor so ein, dass der gewünschte Endbetrag genau erreicht wird. Gelöschte Leistungen verschwinden aus der Auswahl, frühere Zahlungen behalten ihre Angaben.</p>
<div id="catalog-box"><p class="muted">Wird geladen …</p></div></section>
<section class="card settings-card" id="s-update"><h2>Aktualisierung</h2><div id="update-box"><p class="muted">Wird geprüft …</p></div></section>
<section class="card settings-card" id="s-firewall"><h2>Netzwerk &amp; Firewall</h2>
<p class="muted">Der Server erkennt sein Netz selbst. Wähle, aus welchen Netzen Arbeitsplätze, Drucker und der T2med-Rechner zugreifen dürfen; alle anderen werden ausgesperrt. Nach dem Anwenden bestätigst du binnen 2 Minuten, dass diese Seite noch funktioniert – sonst stellt der Server die alten Regeln selbst wieder her.</p>
<div id="fw-box"><p class="muted">Netzwerk wird abgefragt …</p></div></section>
<section class="card settings-card" id="s-audit"><h2>Sicherheitsprotokoll</h2>
<p class="muted">Anmeldungen, Fehlversuche und Änderungen an dieser Einstellungsseite mit Zeit und Adresse des Geräts – nie mit Passwörtern. Viele „fehlgeschlagen“-Zeilen von unbekannten Adressen deuten auf einen Angriffsversuch hin; der betroffene Absender wird dann automatisch gesperrt.</p>
<div class="row-buttons"><button id="audit-load" class="secondary" type="button">Letzte Vorgänge anzeigen</button></div>
<pre id="audit-list" class="audit-list" hidden></pre></section>
<section class="card settings-card" id="s-access"><h2>Abmeldung und Mitarbeiter-Zugang</h2>
<form id="access-form" class="settings-form"></form></section>
<section class="card settings-card" id="s-password"><h2 id="password-title">Admin-Passwort ändern</h2>
<form id="password-form"><label>Bisheriges Passwort<input id="pw-current" type="password" autocomplete="current-password" required></label>
<label>Neues Passwort (mind. 10 Zeichen)<input id="pw-new" type="password" autocomplete="new-password" minlength="10" required></label>
<button class="secondary" type="submit">Passwort ändern</button></form></section>
<p class="settings-foot"><a href="/">Zur Kartenzahlung</a> · <button id="logout" class="text-button" type="button">Abmelden</button></p>
</div>
<footer>kienzle-sumup v<?= $version ?></footer>
</main></body></html>
