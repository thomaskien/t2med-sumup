<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\App;
use KienzleSumup\Problem;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Problem('POST erforderlich.', 405);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) throw new Problem('Anfrage zu groß.', 413);
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new Problem('JSON erforderlich.', 415);
    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if (strlen($raw) > 32768) throw new Problem('Anfrage zu groß.', 413);
    try { $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new Problem('Ungültige JSON-Anfrage.'); }
    if (!is_array($input)) throw new Problem('Ungültige Anfrage.');
    $app = ks_app();
    $action = App::string($input, 'action', 32);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== rtrim($app->config->get('app', 'base_url'), '/')) throw new Problem('Unzulässiger Ursprung.', 403);
    if ($action === 'launch') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!hash_equals('Bearer ' . $app->config->get('app', 'launcher_key'), $auth)) throw new Problem('Starter nicht autorisiert.', 401);
        $result = $app->launch($input);
    } elseif ($action === 'demo') {
        if (!$app->config->get('app', 'development', false)) throw new Problem('Demo-Start deaktiviert.', 403);
        $result = $app->launch(['context_id' => 'demo', 'oauth_token' => '', 'fhir_base_url' => '']);
    } elseif ($action === 'exchange') {
        $result = $app->exchange(App::string($input, 'ticket', 64), $_COOKIE['ks_browser'] ?? null);
        setcookie('ks_browser', $result['cookie'], ['expires' => time() + $app->config->get('app', 'session_hours') * 3600,
            'path' => '/', 'secure' => !$app->config->get('app', 'development', false), 'httponly' => true, 'samesite' => 'Strict']);
        unset($result['cookie']);
    } else {
        $browser = $app->browser($_COOKIE['ks_browser'] ?? null);
        $visit = $app->visit($browser, App::string($input, 'visit_id', 32));
        if ($action !== 'state' && !hash_equals($browser['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) throw new Problem('Sitzungsprüfung fehlgeschlagen. Bitte Seite neu laden.', 403);
        $result = match ($action) {
            'state' => $app->state($visit, $browser),
            'start' => $app->start($visit, $input),
            'status' => $app->poll($visit, App::string($input, 'payment_id', 32)),
            'cancel' => $app->cancel($visit, App::string($input, 'payment_id', 32)),
            'document' => $app->document($visit, App::string($input, 'payment_id', 32)),
            'receipt_share' => $app->receiptShare($visit, App::string($input, 'payment_id', 32)),
            'receipt_recipient' => $app->receiptRecipient($visit, App::string($input, 'payment_id', 32)),
            'receipt_email' => $app->receiptEmail($visit, $input),
            'service_save' => (function () use ($app, $visit, $input) { $app->saveService($visit, $input); return ['ok' => true]; })(),
            'service_delete' => (function () use ($app, $visit, $input) { $app->deleteService($visit, App::string($input, 'id', 32)); return ['ok' => true]; })(),
            'mock' => (function () use ($app, $visit, $input) { $app->mock($visit, $input); return ['ok' => true]; })(),
            default => throw new Problem('Unbekannte Aktion.', 404),
        };
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Problem $e) {
    http_response_code($e->http); echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    // Keine Rohantworten, URLs, Tokens oder Gesundheitsdaten in Logs.
    error_log('kienzle-sumup: ' . get_class($e));
    http_response_code(500); echo json_encode(['error' => 'Der Vorgang konnte nicht verarbeitet werden. Bitte erneut laden; ein angelegter Zahlungsversuch bleibt erhalten.']);
}
