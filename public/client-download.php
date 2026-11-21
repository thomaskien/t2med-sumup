<?php
declare(strict_types=1);
// Download des Arbeitsplatz-Einrichtungspakets (ZIP) aus den Einstellungen. Nur mit Admin-Sitzung.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, Problem, SettingsPage};

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Problem('GET erforderlich.', 405);
    // Nur direkt aus der Einstellungsseite (oder per eingegebener Adresse), nicht von fremden Seiten.
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site !== '' && $site !== 'same-origin' && $site !== 'none') throw new Problem('Unzulässiger Ursprung.', 403);
    $page = new SettingsPage(ks_base_config());
    $page->clientDownload($_COOKIE[Admin::COOKIE] ?? null, (string)($_GET['os'] ?? ''), 'header', function (string $bytes): void { echo $bytes; });
} catch (Problem $e) {
    http_response_code($e->http);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage(), "\n";
} catch (\Throwable $e) {
    error_log('kienzle-sumup client-download: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500); header('Content-Type: text/plain; charset=utf-8');
    echo "Interner Fehler. Details im Protokoll /var/log/kienzle-sumup/php.log.\n";
}
