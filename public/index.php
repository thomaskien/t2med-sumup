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
<title>Kienzle-SumUp · Kartenzahlung</title><link rel="stylesheet" href="/assets/style.css?v=<?= $version ?>"><script src="/assets/goae-calc.js?v=<?= $version ?>" defer></script><script src="/assets/app.js?v=<?= $version ?>" defer></script></head>
<body><main>
<header class="top"><div class="brand"><span class="mark" aria-hidden="true">K</span><div><strong>kienzle-sumup</strong><span>Kartenzahlung · T2med</span></div></div><span id="mode" class="badge" hidden>TESTMODUS</span></header>
<section id="welcome" class="card"><h1>Kartenzahlung</h1><p>Bitte diese Seite aus der Patientenakte in T2med öffnen.</p><button id="demo" class="secondary" hidden>Testpatient öffnen</button></section>
<div id="workspace" hidden>
<section class="patient"><span class="eyebrow">AKTUELLER PATIENT</span><h1 id="patient-name"></h1><p id="patient-birth"></p></section>
<section class="card" id="selection"><div id="print-box" class="print-box" hidden>
<div class="print-row"><label class="check"><input type="checkbox" id="auto-print"> Rechnung nach der Zahlung drucken</label><button type="button" id="print-settings-toggle" class="text-button" aria-expanded="false">Druckeinstellungen</button></div>
<p id="print-targets" class="muted small"></p>
<div id="print-settings" class="print-settings" hidden>
<label class="check"><input type="checkbox" id="print-default"> Häkchen standardmäßig setzen (gilt für alle Arbeitsplätze)</label>
<fieldset id="print-target-set"><legend>In der App erzeugte Rechnungen drucken auf</legend>
<label class="check"><input type="radio" name="print-target" value="office" id="target-office"> <span id="target-office-label"></span></label>
<label class="check"><input type="radio" name="print-target" value="receipt" id="target-receipt"> <span id="target-receipt-label"></span></label></fieldset>
<p class="muted small">T2med-Rechnungen werden immer auf dem Praxisdrucker gedruckt.</p>
<button type="button" id="print-settings-save" class="secondary">Speichern</button></div></div>
<div id="invoices-section" class="invoices" hidden><h2>Offene T2med-Rechnung</h2><div id="invoices"></div><p class="muted">Rechnungsnummer und Betrag stammen aus der in T2med auf „SumUp-Rechnung“ gedruckten Rechnung. Nur Betrag und Rechnungsnummer gehen an SumUp.</p></div>
<div class="section-heading"><h2>Leistungen</h2><button type="button" id="manage-toggle" class="text-button" aria-expanded="false">Leistungen verwalten</button></div>
<p id="practice-hint" class="muted" hidden>Für eigene Leistungsbelege und GOÄ-Rechnungen bitte Praxisname und Anschrift im Server-Installer eintragen.</p>
<div id="groups-section" hidden><h3>Kombinationen</h3><div id="groups"></div><p class="muted">Gemeinsame Einzelleistungen werden nur einmal berechnet.</p><h3>Einzelleistungen</h3></div>
<div id="services"></div><p id="empty" class="muted" hidden>Noch keine Leistungen angelegt. Über „Leistungen verwalten“ kannst du beginnen.</p>
<div id="basket" class="basket" hidden><h3>Ausgewählte Positionen</h3><div id="basket-lines"></div></div>
<label class="date-label" for="service-date">Leistungsdatum</label><input id="service-date" type="date" required>
<div class="manual"><label for="manual">Sonstiger Betrag <span class="muted">optional</span></label><div class="amount-input"><input id="manual" type="text" inputmode="decimal" placeholder="0,00" autocomplete="off" maxlength="12"><span>€</span></div></div>
<div class="total"><span>Gesamt</span><strong id="total">0,00 €</strong></div>
<div id="reader-busy" class="reader-busy" hidden>
<h2 id="reader-title">Terminal belegt</h2><p id="reader-description" role="status"></p>
<p id="reader-reference" class="reference muted"></p>
<button id="reader-cancel" class="secondary wide">Anderen Vorgang abbrechen</button>
<button id="reader-status" class="text-button">Status prüfen</button>
</div>
<div id="pay-method" class="pay-method" role="radiogroup" aria-label="Zahlungsart"><label><input type="radio" name="pay-method" value="card" checked><span>💳 Karte (SumUp)</span></label><label><input type="radio" name="pay-method" value="cash"><span>💶 Bar</span></label></div><p id="pay-method-note" class="muted small" hidden></p>
<button id="pay" class="primary wide" disabled>Mit Karte kassieren</button></section>
<section id="manager" class="card" hidden><div class="section-heading"><h2>Leistungen verwalten</h2><button id="manage-close" class="text-button">Fertig</button></div><div id="manage-list"></div>
<form id="service-form"><input id="service-id" type="hidden"><label for="service-label">Bezeichnung</label><input id="service-label" required maxlength="160" placeholder="z. B. Attest"><label for="service-price">Preis in Euro</label><input id="service-price" required inputmode="decimal" placeholder="10,00" maxlength="12">
<label class="check-label" id="service-vat-row"><input id="service-vat" type="checkbox"> <span id="service-vat-label">mit MwSt. (im Preis enthalten)</span></label><p id="service-vat-note" class="muted small" hidden></p>
<details id="goae-fields" class="catalog-details"><summary>GOÄ-Angaben ergänzen (optional)</summary>
<label for="service-goae">GOÄ-Ziffer</label><input id="service-goae" maxlength="20" placeholder="z. B. 250">
<div class="goae-calc"><div><label for="service-base">Einfachsatz (1,0-fach) in Euro</label><input id="service-base" inputmode="decimal" maxlength="12" placeholder="z. B. 10,72"></div><div><label for="service-target">Gewünschter Endbetrag in Euro</label><input id="service-target" inputmode="decimal" maxlength="12" placeholder="z. B. 25,00"></div></div><p id="goae-result" class="goae-result" role="status" hidden></p>
<label for="service-factor">Steigerungsfaktor</label><input id="service-factor" inputmode="decimal" value="2,3" maxlength="8">
<label for="service-fee-type">Gebührenrahmen</label><select id="service-fee-type"><option value="standard">Ärztlich · Schwelle 2,3 / max. 3,5</option><option value="technical">Technisch (A, E, O) · Schwelle 1,8 / max. 2,5</option><option value="lab">Labor (M / Nr. 437) · Schwelle 1,15 / max. 1,3</option></select>
<p class="muted">Rechner: Einfachsatz und gewünschten Endbetrag eintragen – Faktor und Preis werden automatisch berechnet (Gebühr = Einfachsatz × Faktor, auf Cent gerundet). Ohne Einfachsatz ergänzen GOÄ und Faktor nur den Beleg, der Preis bleibt wie eingetragen. Eine Begründung oberhalb der Schwelle wird bei der Abrechnung erfasst.</p>
<label for="service-reason">Feste Begründung bei Faktor über dem Schwellenwert (optional)</label><textarea id="service-reason" maxlength="800" rows="2" placeholder="z. B. überdurchschnittlicher Zeitaufwand wegen …"></textarea><p class="muted small">Wird an der Kasse vorausgefüllt und lässt sich dort pro Abrechnung ändern.</p>
</details>
<div class="tip-row"><label class="check-label"><input id="service-on-request" type="checkbox"> Leistung auf Verlangen</label><button type="button" class="tip" aria-label="Hilfe zu Leistung auf Verlangen">i<span class="tip-box" role="tooltip"><span class="tip-text">Für Leistungen, die der Patient ausdrücklich wünscht und die medizinisch nicht notwendig sind. Nach § 1 Abs. 2 GOÄ dürfen solche Leistungen nur auf Verlangen berechnet werden und müssen in der Rechnung entsprechend gekennzeichnet sein. Die App vermerkt „Auf Verlangen“ bei der Position im Beleg und im Akteneintrag in T2med; Preis, Faktor und Steuer ändern sich dadurch nicht. Bei medizinisch notwendigen Leistungen nicht ankreuzen.</span></span></button></div>
<div class="actions"><button class="primary" type="submit" id="service-save">Leistung hinzufügen</button><button id="edit-reset" type="button" class="secondary" hidden>Abbrechen</button></div></form>
<details id="group-editor" class="catalog-details"><summary>Kombinationen verwalten</summary><div id="group-list"></div>
<form id="group-form"><input id="group-id" type="hidden"><label for="group-label">Name der Kombination</label><input id="group-label" required maxlength="160" placeholder="z. B. Laborprofil mit Blutentnahme">
<p class="muted">Vorhandene Einzelleistungen auswählen. Dieselbe Blutentnahme in allen passenden Kombinationen verwenden.</p><div id="group-members"></div>
<div class="actions"><button id="group-save" class="primary" type="submit">Kombination hinzufügen</button><button id="group-reset" class="secondary" type="button" hidden>Abbrechen</button></div></form></details>
</section>
<section id="payment" class="card payment-card" hidden aria-live="polite"><span id="status-symbol" class="status-symbol" aria-hidden="true">…</span><h2 id="payment-title"></h2><p id="payment-description"></p><strong id="payment-amount" class="payment-amount"></strong><p id="payment-reference" class="muted reference"></p><p id="payment-error" class="inline-error" hidden></p>
<button id="receipt" class="secondary wide" hidden>Zahlungsbeleg / PDF</button>
<p id="receipt-data-note" class="muted" hidden>Dieser Beleg enthält Patientendaten und die abgerechneten Leistungen. Beim Mailversand wird dieses PDF angehängt.</p>
<button id="receipt-mail" class="secondary wide" hidden>Beleg mailen</button>
<p id="receipt-mail-status" class="muted" role="status" hidden></p>
<button id="receipt-print" class="secondary wide" hidden>Beleg drucken</button>
<p id="receipt-print-status" class="muted" role="status" hidden></p>
<p id="invoice-print-status" class="muted" role="status" hidden></p><button id="invoice-print" class="text-button" hidden>Rechnung erneut drucken</button>
<p id="invoice-export-status" class="muted" role="status" hidden></p><button id="invoice-export" class="text-button" hidden>Rechnungs-PDF erneut ablegen</button>
<button id="document" class="primary wide" hidden>Dokumentation in der Akte</button><button id="retry" class="primary wide" hidden>Erneut versuchen</button><button id="cancel" class="text-button" hidden>Zahlung abbrechen</button>
<button id="receipt-share" class="text-button" hidden>Beleglink / E-Mail-Adresse ändern</button>
<div id="receipt-share-panel" class="receipt-share" hidden>
<p id="receipt-share-message" class="muted" role="status"></p>
<div id="receipt-share-details" hidden><a id="receipt-pdf-download">PDF herunterladen</a><div id="receipt-original-link"><label for="receipt-link">SumUp-Beleglink</label><input id="receipt-link" type="text" readonly>
<div class="actions"><a id="receipt-open" target="_blank" rel="noopener noreferrer">Originalbeleg öffnen</a><button id="receipt-copy" type="button" class="text-button">Link kopieren</button></div></div>
<form id="receipt-email-form"><label for="receipt-email">E-Mail-Adresse</label><input id="receipt-email" type="email" autocomplete="off" maxlength="254" required placeholder="name@beispiel.de">
<p class="muted">Adresse prüfen. Der Zahlungsbeleg wird als PDF-Anhang direkt an diese Adresse gesendet.</p><button id="receipt-email-send" type="submit" class="secondary">PDF per E-Mail senden</button></form>
<p id="receipt-mail-unconfigured" class="muted" hidden>E-Mail-Versand ist noch nicht im Server-Installer eingerichtet.</p></div></div>
<div id="mock-controls" class="mock-controls" hidden><span class="eyebrow">TERMINAL SIMULIEREN</span><div class="actions"><button id="mock-success" class="secondary">Zahlung erfolgreich</button><button id="mock-fail" class="secondary">Zahlung abgelehnt</button></div></div>
<label id="mock-fhir-label" class="mock-option" hidden><input type="checkbox" id="mock-fhir-fail"> t2med-Fehler simulieren</label>
</section></div>
<p id="message" class="message" role="alert" hidden></p>
<footer><a href="https://github.com/thomaskien/t2med-sumup" target="_blank" rel="noopener noreferrer">kienzle-sumup für T2med v<?= $version ?> von Dr. Thomas Kienzle · Fork mit T2med-Rechnungseingang</a> · <a href="/settings.php">Einstellungen</a></footer>
</main></body></html>
