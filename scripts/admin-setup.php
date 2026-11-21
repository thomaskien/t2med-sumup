<?php
declare(strict_types=1);
// Gibt einen einmaligen Einrichtungslink für die Einstellungsseite aus, solange kein
// Admin-Passwort festgelegt ist. --reset löscht das Passwort und alle Admin-Sitzungen.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, Database};
$base = ks_base_config();
$db = new Database($base->get('app', 'state_dir'));
$db->migrate();
$token = (new Admin($db, $base->get('app', 'state_dir')))->createSetupToken(in_array('--reset', $argv, true));
if ($token !== null) echo rtrim($base->get('app', 'base_url'), '/') . '/settings.php#setup=' . $token . "\n";
