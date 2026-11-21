#!/usr/bin/env php
<?php
declare(strict_types=1);
// Holt die Ablage aller bezahlten, noch nicht abgelegten Rechnungen im Netzwerkordner nach.
// Aufruf: sudo runuser -u kienzle-sumup -- php /opt/kienzle-sumup/scripts/export-invoices.php [--quiet]
// Der Timer kienzle-sumup-export.timer ruft das stündlich auf.
require dirname(__DIR__) . '/src/bootstrap.php';
$quiet = in_array('--quiet', $argv, true);
try {
    $app = ks_app();
    if (!$app->config->get('invoice_export', 'enabled', false)) { if (!$quiet) echo "Ablage im Netzwerkordner ist deaktiviert.\n"; exit(0); }
    $app->db->migrate();
    $result = $app->exportPending();
    if (!$quiet || $result['done'] || $result['failed']) echo "Abgelegt: {$result['done']}, fehlgeschlagen: {$result['failed']}\n";
    foreach ($result['errors'] as $error) fwrite(STDERR, $error . "\n");
    exit($result['failed'] ? 1 : 0);
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
