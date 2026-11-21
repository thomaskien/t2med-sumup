<?php
declare(strict_types=1);
// Rechnungseingang für den virtuellen Drucker „SumUp-Rechnung“ auf dem Druckserver.
// Nimmt ausschließlich PDF mit dem Rechnungseingangs-Schlüssel an; keine Browser-Sitzung.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Problem, T2medInvoice};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Problem('POST erforderlich.', 405);
    $app = ks_app();
    if (!$app->config->get('invoice_inbox', 'enabled', false)) throw new Problem('Rechnungseingang ist deaktiviert.', 404);
    $throttle = new KienzleSumup\Throttle($app->db);
    $throttle->check('inbox');
    if (!hash_equals('Bearer ' . $app->config->get('invoice_inbox', 'key'), $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
        $throttle->fail('inbox', 10, 600);
        usleep(500000);
        throw new Problem('Druckserver nicht autorisiert.', 401);
    }
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/pdf') throw new Problem('PDF erforderlich.', 415);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > T2medInvoice::MAX_PDF) throw new Problem('Die Rechnung ist zu groß.', 413);
    $pdf = file_get_contents('php://input', false, null, 0, T2medInvoice::MAX_PDF + 1);
    if ($pdf === false || strlen($pdf) > T2medInvoice::MAX_PDF) throw new Problem('Die Rechnung ist zu groß.', 413);
    echo json_encode($app->inboxReceive($pdf), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Problem $e) {
    // Problem-Texte sind feste Programmtexte und enthalten keine Patientendaten.
    http_response_code($e->http); echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('kienzle-sumup invoice-inbox: ' . get_class($e));
    http_response_code(500); echo json_encode(['error' => 'Die Rechnung konnte nicht verarbeitet werden.']);
}
