<?php
declare(strict_types=1);
// Überträgt Zielbondrucker und gespeichertes Terminzettel-Layout an /etc/terminzettel/web/layout.json.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Settings, Terminzettel};
$base = ks_base_config();
if (!Terminzettel::sync(Settings::effective($base), Settings::read($base)['terminzettel'] ?? [])) {
    fwrite(STDERR, "Ordner /etc/terminzettel/web fehlt oder ist nicht beschreibbar.\n");
    exit(1);
}
