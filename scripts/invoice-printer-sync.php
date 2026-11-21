<?php
declare(strict_types=1);
// Drucker „SumUp-Rechnung“ und seine Verbindungsdaten an die gespeicherten Einstellungen angleichen.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{InvoicePrinter, PrinterAdmin, Settings};
$config = Settings::effective(ks_base_config());
InvoicePrinter::sync($config);
$state = InvoicePrinter::apply(new PrinterAdmin(), (bool)$config->get('invoice_inbox', 'enabled', false));
if ($state === true) echo "Drucker „SumUp-Rechnung“ eingerichtet.\n";
