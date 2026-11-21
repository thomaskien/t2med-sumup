<?php
declare(strict_types=1);
// Terminzettel im Webinterface: Layout-Datei für den Druckdienst, Vorschau und Testdruck-Daten.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, Config, Database, Problem, Settings, SettingsPage, Terminzettel};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}

$dirs = [];
try {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = $state = sys_get_temp_dir() . '/ks-tz-' . bin2hex(random_bytes(6)); $dirs[] = $state; mkdir($state, 0700);
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $base = new Config($v);
    $db = new Database($state); $db->migrate();
    Terminzettel::$dir = $state . '/web'; mkdir(Terminzettel::$dir, 0750);
    Terminzettel::$toml = $state . '/config.toml';
    // Vorhandene Terminzettel-Konfiguration (Format des Terminzettel-Installers) liefert die Vorgaben.
    file_put_contents(Terminzettel::$toml, "[header]\nenabled = true\ntext = \"Praxis Beispiel\\nMusterweg 1\"\n[calendar_qr]\nenabled = false\nsummary = \"Praxis Beispiel\"\n");
    $view = array_column(array_column(Settings::view($base), null, 'id')['terminzettel']['fields'], 'value', 'key');
    check($view['header_text'] === "Praxis Beispiel\nMusterweg 1" && $view['qr_enabled'] === false, 'Vorgaben aus config.toml nicht übernommen');
    check($view['appointment_size'] === '2x2' && $view['qr_position'] === 'right', 'Standardwerte falsch');

    // Ohne Bondrucker an diesem Server: Datei ohne Ziel, Layout der config.toml bleibt unangetastet.
    Settings::save($db, $base, 'practice', ['name' => 'Beispielpraxis']);
    $layout = json_decode((string)file_get_contents(Terminzettel::$dir . '/layout.json'), true);
    check($layout === ['enabled' => true, 'queue' => null, 'model' => 'tm-m10'], 'Unerwartete Layout-Datei: ' . json_encode($layout));
    check((fileperms(Terminzettel::$dir . '/layout.json') & 0777) === 0640, 'Layout-Datei nicht 0640');

    // Bondrucker TM-m30III per CUPS: Ziel und Modell folgen automatisch.
    Settings::save($db, $base, 'printing', ['enabled' => true, 'model' => 'tm-m30iii', 'transport' => 'cups', 'queue' => 'TM-m30III', 'cups_server' => '', 'width_dots' => '']);
    $layout = json_decode((string)file_get_contents(Terminzettel::$dir . '/layout.json'), true);
    check($layout['queue'] === 'TM-m30III' && $layout['model'] === 'tm-m30iii', 'Zielbondrucker nicht übernommen');

    // Layout speichern: Größen je Block, QR-Position und -Größe.
    Settings::save($db, $base, 'terminzettel', ['enabled' => true, 'header_text' => "Praxis Beispiel\r\nTel. 01234 567890", 'header_size' => '1x1', 'header_bold' => false,
        'heading' => 'IHRE TERMINE', 'heading_size' => '2x2', 'show_patient' => true, 'appointment_size' => '1x2', 'footer_text' => '', 'footer_size' => '1x1',
        'qr_enabled' => true, 'qr_position' => 'below', 'qr_size' => 'large', 'qr_summary' => 'Praxis Beispiel', 'cut' => true]);
    $layout = json_decode((string)file_get_contents(Terminzettel::$dir . '/layout.json'), true);
    check($layout['header'] === ['text' => "Praxis Beispiel\nTel. 01234 567890", 'size' => '1x1', 'bold' => false, 'enabled' => true], 'Starttext falsch: ' . json_encode($layout['header']));
    // Erste Zeile des Starttexts abweichend gestalten.
    Settings::save($db, $base, 'terminzettel', ['header_text' => "Praxis Beispiel\nTel. 01234 567890", 'header_size' => '1x1', 'header_bold' => false, 'header_first_size' => '2x2', 'header_first_bold' => 'yes']);
    $layout = json_decode((string)file_get_contents(Terminzettel::$dir . '/layout.json'), true);
    check(($layout['header']['first_size'] ?? '') === '2x2' && ($layout['header']['first_bold'] ?? null) === true, 'Erste Zeile nicht übertragen: ' . json_encode($layout['header']));
    $sample = Terminzettel::sample(Terminzettel::overlay($base, ['header_text' => "Praxis\nTel. 01234", 'header_size' => '1x1', 'header_bold' => false, 'header_first_size' => '2x2', 'header_first_bold' => 'yes']), 'escpos')['data'];
    check(str_contains($sample, "\x1d!\x11") && str_contains($sample, "\x1d!\x00"), 'Erste Zeile im Bon nicht größer als die übrigen');
    Settings::save($db, $base, 'terminzettel', ['header_first_size' => 'same', 'header_first_bold' => 'same']);
    $layout = json_decode((string)file_get_contents(Terminzettel::$dir . '/layout.json'), true);
    check(!isset($layout['header']['first_size']) && !isset($layout['header']['first_bold']), 'Erste Zeile bleibt nach „wie übrige“ gesetzt');
    Settings::save($db, $base, 'terminzettel', ['header_text' => "Praxis Beispiel\nTel. 01234 567890", 'header_size' => '1x1', 'header_bold' => false]);
    check($layout['appointments']['size'] === '1x2' && $layout['footer']['enabled'] === false && $layout['qr']['position'] === 'below' && $layout['qr']['width_dots'] === 320, 'Layout falsch übertragen');
    problem(fn() => Settings::save($db, $base, 'terminzettel', ['appointment_size' => '9x9']), 'Ungültige Schriftgröße akzeptiert', 422);
    problem(fn() => Settings::save($db, $base, 'terminzettel', ['footer_text' => "Text\x1b@"]), 'Steuerzeichen im Endtext akzeptiert', 422);
    problem(fn() => Settings::save($db, $base, 'terminzettel', ['footer_text' => str_repeat('x', 1001)]), 'Zu langer Endtext akzeptiert', 422);

    // Vorschau und Testdruck über den echten Python-Renderer (nur Beispieldaten).
    $page = new SettingsPage($base);
    $admin = new Admin($db, $state);
    $login = $page->handle('setup', ['token' => $admin->createSetupToken(), 'password' => 'beispiel-passwort'], null, null);
    preg_match('/^ks_admin=([^;]+)/', (string)$page->cookie(), $m); $session = $m[1];
    problem(fn() => $page->handle('terminzettel_preview', ['values' => []], null, null), 'Vorschau ohne Anmeldung', 401);
    $preview = $page->handle('terminzettel_preview', ['values' => ['appointment_size' => '3x3', 'qr_position' => 'left']], $session, $login['csrf']);
    $png = base64_decode(substr($preview['image'], strlen('data:image/png;base64,')));
    check(str_starts_with($png, "\x89PNG"), 'Vorschau ist kein PNG');
    $size = getimagesizefromstring($png);
    check($size[0] === 576 + 36, 'Vorschau nicht in TM-m30III-Breite: ' . $size[0]);
    check(json_decode((string)file_get_contents(Terminzettel::$dir . '/layout.json'), true)['appointments']['size'] === '1x2', 'Vorschau hat Einstellungen gespeichert');
    problem(fn() => $page->handle('terminzettel_preview', ['values' => ['heading_size' => '7x7']], $session, $login['csrf']), 'Ungültige Vorschau-Größe akzeptiert', 422);
    $escpos = Terminzettel::sample(Terminzettel::overlay(Settings::effective($base), Settings::read($base)['terminzettel']), 'escpos')['data'];
    check(str_contains($escpos, "\x1d!\x00") && str_contains($escpos, "\x1dV\x01") && str_contains($escpos, 'Musterfrau'), 'Testdruck-Daten unvollständig');
    check(str_contains($escpos, "\x1d!\x01"), 'Termine nicht in Größe 1x2');
    echo "Terminzettel-Einstellungen und Vorschau ok\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
}
