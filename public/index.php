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
<title>Kienzle-SumUp · Kartenzahlung</title><link rel="stylesheet" href="/assets/style.css"><script src="/assets/app.js" defer></script></head>
<body><main>
<header class="top"><div class="brand"><span class="mark" aria-hidden="true">K</span><div><strong>kienzle-sumup</strong><span>Kartenzahlung · T2med</span></div></div><span id="mode" class="badge" hidden>TESTMODUS</span></header>
<section id="welcome" class="card"><h1>Kartenzahlung</h1><p>Bitte diese Seite aus der Patientenakte in T2med öffnen.</p><button id="demo" class="secondary" hidden>Testpatient öffnen</button></section>
<div id="workspace" hidden>
<section class="patient"><span class="eyebrow">AKTUELLER PATIENT</span><h1 id="patient-name"></h1><p id="patient-birth"></p></section>
<section class="card" id="selection"><div class="section-heading"><h2>Leistungen</h2><button type="button" id="manage-toggle" class="text-button" aria-expanded="false">Leistungen verwalten</button></div>
<div id="services"></div><p id="empty" class="muted" hidden>Noch keine Leistungen angelegt. Über „Leistungen verwalten“ kannst du beginnen.</p>
<div class="manual"><label for="manual">Sonstiger Betrag <span class="muted">optional</span></label><div class="amount-input"><input id="manual" type="text" inputmode="decimal" placeholder="0,00" autocomplete="off" maxlength="12"><span>€</span></div></div>
<div class="total"><span>Gesamt</span><strong id="total">0,00 €</strong></div><button id="pay" class="primary wide" disabled>Mit Karte kassieren</button></section>
<section id="manager" class="card" hidden><div class="section-heading"><h2>Leistungen verwalten</h2><button id="manage-close" class="text-button">Fertig</button></div><div id="manage-list"></div>
<form id="service-form"><input id="service-id" type="hidden"><label for="service-label">Bezeichnung</label><input id="service-label" required maxlength="160" placeholder="z. B. Attest"><label for="service-price">Preis in Euro</label><input id="service-price" required inputmode="decimal" placeholder="10,00" maxlength="12"><div class="actions"><button class="primary" type="submit" id="service-save">Leistung hinzufügen</button><button id="edit-reset" type="button" class="secondary" hidden>Abbrechen</button></div></form></section>
<section id="payment" class="card payment-card" hidden aria-live="polite"><span id="status-symbol" class="status-symbol" aria-hidden="true">…</span><h2 id="payment-title"></h2><p id="payment-description"></p><strong id="payment-amount" class="payment-amount"></strong><p id="payment-reference" class="muted reference"></p><p id="payment-error" class="inline-error" hidden></p>
<button id="receipt" class="secondary wide" hidden>Zahlungsbeleg / PDF</button>
<button id="receipt-mail" class="secondary wide" hidden>Beleg mailen</button>
<p id="receipt-mail-status" class="muted" role="status" hidden></p>
<button id="receipt-print" class="secondary wide" hidden>Beleg drucken</button>
<p id="receipt-print-status" class="muted" role="status" hidden></p>
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
<footer><a href="https://github.com/thomaskien/t2med-sumup" target="_blank" rel="noopener noreferrer">kienzle-sumup für T2med v<?= $version ?> von Dr. Thomas Kienzle</a></footer>
</main></body></html>
