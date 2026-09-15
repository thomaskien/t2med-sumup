<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Problem};

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
    $id = App::string($_GET, 'p', 32);
    $receipt = $app->receipt($visit, $id);
    header('Content-Type: application/pdf');
    $disposition = ($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="Zahlungsbeleg-' . preg_replace('/[^a-f0-9]/', '', $id) . '.pdf"');
    header('Content-Length: ' . strlen($receipt));
    echo $receipt;
    exit;
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
<body><main class="receipt-page"><div class="receipt-toolbar"><button id="receipt-retry" type="button">Erneut versuchen</button><button id="receipt-close" type="button">Fenster schließen</button></div>
<section class="receipt-error" role="alert"><h1>Beleg nicht verfügbar</h1><p><?= escape($error) ?></p></section>
</main></body></html>
