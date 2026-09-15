<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Money, Problem};

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$receipt = null; $error = '';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Problem('GET erforderlich.', 405);
    $app = ks_app();
    $browser = $app->browser($_COOKIE['ks_browser'] ?? null);
    $visit = $app->visit($browser, App::string($_GET, 'v', 32));
    $receipt = $app->receipt($visit, App::string($_GET, 'p', 32));
} catch (Problem $e) {
    http_response_code($e->http); $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('kienzle-sumup receipt: ' . get_class($e));
    http_response_code(500); $error = 'Der Beleg konnte nicht geladen werden. Bitte erneut versuchen.';
}
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zahlungsbeleg · Kienzle-SumUp</title><link rel="stylesheet" href="/assets/receipt.css"><script src="/assets/receipt.js" defer></script></head>
<body data-receipt-ready="<?= $receipt ? 'true' : 'false' ?>"><main class="receipt-page">
<div class="receipt-toolbar">
<?php if ($receipt): ?><button id="receipt-print" type="button">Drucken</button>
<?php else: ?><button id="receipt-retry" type="button">Erneut versuchen</button><?php endif ?>
<button id="receipt-close" type="button">Fenster schließen</button></div>
<?php if ($receipt): ?>
<p class="receipt-hint">Im Druckdialog den Praxisdrucker wählen oder den Beleg als PDF speichern.</p>
<article class="receipt"><p class="receipt-brand">kienzle-sumup</p>
<?php if ($receipt['mock']): ?><p class="receipt-test">TESTBELEG – keine echte Zahlung</p><?php endif ?>
<h1>Zahlungsbeleg</h1><p class="receipt-merchant"><?= escape($receipt['merchant_name']) ?></p>
<p class="receipt-address"><?= escape($receipt['merchant_address']) ?></p>
<p class="receipt-amount"><?= escape(Money::format($receipt['amount_cents'])) ?></p>
<p class="receipt-status"><?= $receipt['mock'] ? 'Simulierte Kartenzahlung' : 'Kartenzahlung erfolgreich · SumUp' ?></p>
<dl>
<?php foreach (['Datum' => $receipt['date'], 'Händlercode' => $receipt['merchant_code'],
    'Transaktionscode' => $receipt['transaction_code'], 'Transaktions-ID' => $receipt['transaction_id'],
    'Belegnummer' => $receipt['receipt_no'], 'Kartenart' => $receipt['card_type'],
    'Kartennummer' => $receipt['card_last4'] !== '' ? '•••• ' . $receipt['card_last4'] : '',
    'Referenz' => $receipt['reference']] as $label => $value): if ($value === '') continue; ?>
<dt><?= escape($label) ?></dt><dd><?= escape($value) ?></dd>
<?php endforeach ?></dl>
<p class="receipt-note">Dieser Beleg bestätigt die Kartenzahlung und ersetzt keine Rechnung.</p></article>
<?php else: ?><section class="receipt-error" role="alert"><h1>Beleg nicht verfügbar</h1><p><?= escape($error) ?></p></section><?php endif ?>
</main></body></html>
