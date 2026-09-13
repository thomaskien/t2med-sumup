#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
try { ks_app()->db->migrate(); echo "Konfiguration geprüft, Datenbank bereit.\n"; }
catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
