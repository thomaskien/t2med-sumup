<?php
declare(strict_types=1);
// Für install-invoice-printer.sh auf demselben Rechner: Adresse und Schlüssel des Rechnungseingangs (JSON).
require dirname(__DIR__) . '/src/bootstrap.php';
$config = KienzleSumup\Settings::effective(ks_base_config());
if (!$config->get('invoice_inbox', 'enabled', false)) { fwrite(STDERR, "Rechnungseingang ist nicht aktiviert (Einstellungen → T2med-Rechnungen übernehmen).\n"); exit(1); }
echo json_encode(['server_url' => $config->get('app', 'base_url'), 'inbox_key' => $config->get('invoice_inbox', 'key')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
