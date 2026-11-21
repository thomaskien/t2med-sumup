<?php
declare(strict_types=1);
// Einstellungen im Browser: verschlüsselte Überlagerung der Konfigurationsdatei, Admin-Zugang.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, Config, Database, Problem, Settings, SettingsPage};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}
function base(): Config {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = sys_get_temp_dir() . '/ks-settings-' . bin2hex(random_bytes(6));
    mkdir($v['app']['state_dir'], 0700);
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    return new Config($v);
}

$dirs = [];
try {
    $base = base(); $dirs[] = $state = $base->get('app', 'state_dir');
    $db = new Database($state); $db->migrate();

    // Ohne gespeicherte Werte gilt die Datei unverändert.
    check(Settings::effective($base) === $base, 'Ohne Überlagerung nicht die Basiskonfiguration');

    // Praxisdaten speichern: wirksam, verschlüsselt abgelegt, Basis unberührt.
    Settings::save($db, $base, 'practice', ['name' => 'Beispielpraxis', 'street' => 'Musterweg 1', 'city' => '12345 Musterstadt', 'contact' => '', 'append_sumup_receipt' => false]);
    $eff = Settings::effective($base);
    check($eff->get('practice', 'name') === 'Beispielpraxis' && $eff->practiceReady(), 'Praxisdaten nicht wirksam');
    check($base->get('practice', 'name', '') !== 'Beispielpraxis', 'Basiskonfiguration verändert');
    $raw = (string)(new PDO('sqlite:' . $state . '/payments.sqlite'))->query("SELECT value FROM settings WHERE key='config_overrides'")->fetchColumn();
    check($raw !== '' && !str_contains($raw, 'Beispielpraxis'), 'Einstellungen unverschlüsselt gespeichert');

    // SumUp live: Geheimnis wird nie angezeigt, leeres Feld behält den Wert.
    $sumup = ['mode' => 'live', 'api_key' => 'sup-geheim', 'affiliate_key' => 'aff-geheim', 'app_id' => 'test.app', 'merchant_code' => 'MTEST', 'reader_id' => 'rdr'];
    Settings::save($db, $base, 'app', ['max_amount_cents' => '250']);
    problem(fn() => Settings::save($db, $base, 'sumup', ['mode' => 'mock'] + $sumup) && Settings::save($db, $base, 'fhir', ['mode' => 'live', 'base_url' => 'https://t2med.test/aps/fhir/api/r4', 'launch_urls' => '', 'api_key' => '', 'entry_code' => 'ZAHLUNG']), 'SumUp-Testmodus mit T2med live akzeptiert', 422);
    Settings::save($db, $base, 'sumup', $sumup);
    $eff = Settings::effective($base);
    check($eff->get('app', 'max_amount_cents') === 25000, 'Höchstbetrag nicht in Cent umgerechnet');
    $json = json_encode(Settings::view($eff));
    check(!str_contains($json, 'sup-geheim') && !str_contains($json, 'aff-geheim'), 'Geheimnis in der Ansicht');
    Settings::save($db, $base, 'sumup', ['api_key' => ''] + $sumup);
    check(Settings::effective($base)->get('sumup', 'api_key') === 'sup-geheim', 'Leeres Geheimnisfeld hat den Schlüssel gelöscht');

    // T2med live ohne Key: Demo-Key und erlaubte Adresse automatisch.
    Settings::save($db, $base, 'fhir', ['mode' => 'live', 'base_url' => 'https://t2med.test/aps/fhir/api/r4/', 'launch_urls' => '', 'api_key' => '', 'entry_code' => 'ZAHLUNG']);
    $eff = Settings::effective($base);
    check($eff->get('fhir', 'api_key') === Settings::T2MED_DEMO_API_KEY, 'Demo-Key nicht gesetzt');
    check($eff->get('fhir', 'launch_urls') === ['https://t2med.test/aps/fhir/api/r4'], 'Launch-Adresse nicht abgeleitet');

    // Ungültige Eingaben: Klartext-Fehler, nichts gespeichert.
    problem(fn() => Settings::save($db, $base, 'app', ['max_amount_cents' => 'viel']), 'Ungültiger Betrag akzeptiert', 422);
    problem(fn() => Settings::save($db, $base, 'printing', ['enabled' => true, 'model' => 'gibtsnicht']), 'Unbekanntes Druckermodell akzeptiert', 422);
    problem(fn() => Settings::save($db, $base, 'unbekannt', []), 'Unbekannter Bereich akzeptiert', 404);
    check(Settings::effective($base)->get('app', 'max_amount_cents') === 25000, 'Fehlerhafte Eingabe teilweise gespeichert');

    // Bondrucker TM-m30III: Breite nach Modell; Rechnungseingang erzeugt den Schlüssel selbst.
    Settings::save($db, $base, 'printing', ['enabled' => true, 'model' => 'tm-m30iii', 'transport' => 'cups', 'queue' => 'TM-m30III']);
    check(Settings::effective($base)->get('printing', 'width_dots') === Config::PRINTER_MODELS['tm-m30iii']['width_dots'], 'Breite nicht nach Modell');
    Settings::save($db, $base, 'invoice_inbox', ['enabled' => true]);
    check((bool)preg_match('/^[a-f0-9]{64}$/D', (string)Settings::effective($base)->get('invoice_inbox', 'key')), 'Eingangsschlüssel nicht erzeugt');

    // Admin: Einrichtungslink, Anmeldung, CSRF, Sperre, Zurücksetzen.
    $admin = new Admin($db, $state);
    $token = $admin->createSetupToken();
    check(is_string($token) && (fileperms($admin->tokenPath()) & 0777) === 0600, 'Einrichtungslink nicht geschützt gespeichert');
    check(!str_contains((string)file_get_contents($admin->tokenPath()), $token), 'Einrichtungslink im Klartext gespeichert');
    $page = new SettingsPage($base);
    check($page->handle('status', [], null, null)['setup_required'] === true, 'Einrichtung nicht verlangt');
    problem(fn() => $page->handle('get', [], null, null), 'Einstellungen ohne Anmeldung lesbar', 401);
    problem(fn() => $page->handle('setup', ['token' => 'falsch', 'password' => 'ein-langes-passwort'], null, null), 'Falscher Einrichtungslink akzeptiert', 403);
    problem(fn() => $page->handle('setup', ['token' => $token, 'password' => 'kurz'], null, null), 'Zu kurzes Passwort akzeptiert', 422);
    $login = $page->handle('setup', ['token' => $token, 'password' => 'ein-langes-passwort'], null, null);
    check(preg_match('/^ks_admin=([A-Za-z0-9_-]+);.*Secure; HttpOnly; SameSite=Strict/', (string)$page->cookie(), $m) === 1, 'Sitzungscookie unsicher');
    $session = $m[1];
    check(!is_file($admin->tokenPath()), 'Einrichtungslink nach Gebrauch noch gültig');
    problem(fn() => $page->handle('setup', ['token' => $token, 'password' => 'noch-ein-passwort'], null, null), 'Zweite Einrichtung möglich', 409);
    $view = $page->handle('get', [], $session, null);
    check(str_contains(json_encode($view), 'Beispielpraxis'), 'Ansicht ohne gespeicherte Werte');
    problem(fn() => $page->handle('save', ['section' => 'practice', 'values' => ['name' => 'X']], $session, 'falsch'), 'Speichern ohne CSRF-Token', 403);
    $page->handle('save', ['section' => 'office_printing', 'values' => ['enabled' => true, 'queue' => 'Praxisdrucker', 'cups_server' => '', 'media' => 'A4', 'duplex' => false]], $session, $login['csrf']);
    check(Settings::effective($base)->get('office_printing', 'queue') === 'Praxisdrucker', 'Speichern über die Seite wirkungslos');
    $setup = $page->handle('printer_setup', [], $session, null);
    check($setup['inbox_key'] === Settings::effective($base)->get('invoice_inbox', 'key'), 'Druckereinrichtung mit falschem Schlüssel');

    // Server-IP-Adressen für Druckeradressen aus anderen Netzen (VPN).
    $page->network = new KienzleSumup\Network(fn(array $c) => ($c[2] ?? '') === 'addr' ? json_encode([
        ['ifname' => 'lo', 'addr_info' => [['family' => 'inet', 'local' => '127.0.0.1', 'prefixlen' => 8, 'scope' => 'host']]],
        ['ifname' => 'eth0', 'operstate' => 'UP', 'addr_info' => [['family' => 'inet', 'local' => '192.168.111.115', 'prefixlen' => 24, 'scope' => 'global'], ['family' => 'inet', 'local' => '8.8.8.8', 'prefixlen' => 32, 'scope' => 'global']]]]) : null);
    $withIps = $page->handle('get', [], $session, null);
    check($withIps['terminzettel']['ips'] === [['name' => 'eth0', 'ip' => '192.168.111.115']] && $withIps['invoice_printer']['ips'] === [['name' => 'eth0', 'ip' => '192.168.111.115']], 'Server-IP für Druckeradressen fehlt oder enthält öffentliche Adresse');
    $page->network = null;

    // Leistungen und GOÄ-Positionen im Adminpanel verwalten.
    $c = $page->handle('catalog', [], $session, null);
    check($c['services'] === [] && $c['groups'] === [] && isset($c['fee_types']['standard']), 'Leerer Katalog falsch');
    problem(fn() => $page->handle('catalog_service_save', ['label' => 'X', 'price' => '10,00'], $session, 'falsch'), 'Leistung ohne CSRF-Token gespeichert', 403);
    $c = $page->handle('catalog_service_save', ['id' => '', 'label' => 'Ausführliche Beratung', 'price' => '25,00', 'goae_code' => '3', 'fee_type' => 'standard', 'factor' => '2,86', 'base' => '8,74', 'on_request' => false, 'vat' => false], $session, $login['csrf']);
    check(count($c['services']) === 1 && $c['services'][0]['factor'] === '2.86' && $c['services'][0]['price_cents'] === 2500 && $c['services'][0]['base_cents'] === 874, 'Leistung falsch gespeichert');
    problem(fn() => $page->handle('catalog_service_save', ['label' => 'Falsch', 'price' => '99,00', 'goae_code' => '3', 'fee_type' => 'standard', 'factor' => '2,86', 'base' => '8,74'], $session, $login['csrf']), 'Preis passt nicht zu Einfachsatz × Faktor akzeptiert', 400);
    $sid = $c['services'][0]['id'];
    $c = $page->handle('catalog_service_save', ['id' => $sid, 'label' => 'Beratung ausführlich', 'price' => '25,00', 'goae_code' => '3', 'fee_type' => 'standard', 'factor' => '2,86', 'base' => '8,74'], $session, $login['csrf']);
    check($c['services'][0]['label'] === 'Beratung ausführlich' && count($c['services']) === 1, 'Leistung nicht geändert');
    $c = $page->handle('catalog_group_save', ['id' => '', 'label' => 'Kombi', 'services' => [$sid]], $session, $login['csrf']);
    check(count($c['groups']) === 1 && $c['groups'][0]['services'] === [$sid], 'Kombination falsch gespeichert');
    problem(fn() => $page->handle('catalog_service_delete', ['id' => $sid], $session, $login['csrf']), 'Leistung in Kombination gelöscht', 409);
    $c = $page->handle('catalog_group_delete', ['id' => $c['groups'][0]['id']], $session, $login['csrf']);
    check($c['groups'] === [], 'Kombination nicht gelöscht');
    $c = $page->handle('catalog_service_delete', ['id' => $sid], $session, $login['csrf']);
    check($c['services'] === [] && str_contains($c['message'], 'gelöscht'), 'Leistung nicht gelöscht');

    // Textschnipsel für die Rechnungserkennung und Eingangsübersicht.
    $page->handle('save', ['section' => 'invoice_inbox', 'values' => ['enabled' => true, 'max_age_days' => 14, 'label_number' => "Beleg-Nr.\nBeleg Nummer", 'label_total' => 'Endsumme']], $session, $login['csrf']);
    check(Settings::effective($base)->get('invoice_inbox', 'label_number') === ['Beleg-Nr.', 'Beleg Nummer'], 'Textschnipsel nicht gespeichert');
    problem(fn() => $page->handle('save', ['section' => 'invoice_inbox', 'values' => ['label_total' => str_repeat('x', 61)]], $session, $login['csrf']), 'Zu langer Textschnipsel akzeptiert', 422);
    $overview = $page->handle('inbox_overview', [], $session, null);
    check($overview['enabled'] === true && $overview['items'] === [] && $overview['rejected'] === [], 'Eingangsübersicht falsch');
    problem(fn() => $page->handle('inbox_overview', [], null, null), 'Eingangsübersicht ohne Anmeldung', 401);
    problem(fn() => $page->handle('inbox_delete', ['id' => str_repeat('a', 32)], $session, 'falsch'), 'Löschen ohne CSRF-Token', 403);
    problem(fn() => $page->handle('inbox_delete', ['id' => 'x'], $session, $login['csrf']), 'Ungültige ID beim Löschen', 422);
    problem(fn() => $page->handle('inbox_delete', ['id' => str_repeat('a', 32)], $session, $login['csrf']), 'Unbekannte Rechnung gelöscht', 404);
    problem(fn() => $page->handle('inbox_delete_many', ['ids' => [str_repeat('a', 32)]], $session, 'falsch'), 'Sammellöschen ohne CSRF-Token', 403);
    problem(fn() => $page->handle('inbox_delete_many', ['ids' => []], $session, $login['csrf']), 'Sammellöschen ohne Auswahl', 422);
    problem(fn() => $page->handle('inbox_letter_many', ['ids' => [str_repeat('a', 32)]], $session, 'falsch'), 'Sammelversand ohne CSRF-Token', 403);
    problem(fn() => $page->handle('inbox_letter_many', ['ids' => array_map(fn($i) => sprintf('%032d', $i), range(1, 51))], $session, $login['csrf']), 'Mehr als 50 Rechnungen', 422);
    $many = $page->handle('inbox_delete_many', ['ids' => [str_repeat('a', 32)]], $session, $login['csrf']);
    check(str_contains($many['message'], '0 Rechnungen gelöscht') && str_contains($many['message'], 'Nicht ausgeführt: 1'), 'Sammellöschen meldet Fehler nicht');
    problem(fn() => $page->handle('inbox_letter', ['id' => str_repeat('a', 32)], $session, 'falsch'), 'Postversand ohne CSRF-Token', 403);
    problem(fn() => $page->handle('inbox_letter', ['id' => str_repeat('a', 32)], $session, $login['csrf']), 'Postversand bei ausgeschaltetem Letterxpress', 409);
    check(in_array('letterxpress', array_column($page->handle('get', [], $session, $login['csrf'])['sections'], 'id'), true), 'Letterxpress-Abschnitt fehlt in den Einstellungen');
    problem(fn() => $page->handle('t2med_correct', ['id' => str_repeat('a', 32), 'change' => 'void'], $session, 'falsch'), 'Korrektur ohne CSRF-Token', 403);
    problem(fn() => $page->handle('t2med_correct', ['id' => str_repeat('a', 32), 'change' => 'amount', 'amount' => 'abc'], $session, $login['csrf']), 'Ungültiger Betrag bei Korrektur', 400);
    problem(fn() => $page->handle('t2med_correct', ['id' => str_repeat('a', 32), 'change' => 'void'], $session, $login['csrf']), 'Unbekannte Zahlung storniert', 404);

    // Abmeldezeiten und Mitarbeiter-Zugang.
    $acc = $page->handle('get', [], $session, $login['csrf'])['access'];
    check($acc['idle_minutes'] === 30 && $acc['max_hours'] === 12 && $acc['staff'] === false, 'Standard-Abmeldezeiten falsch');
    problem(fn() => $page->handle('access_save', ['idle_minutes' => 3, 'max_hours' => 12, 'rights' => []], $session, $login['csrf']), 'Zu kurze Abmeldezeit akzeptiert', 422);
    problem(fn() => $page->handle('access_save', ['idle_minutes' => 263521, 'max_hours' => 4392, 'rights' => []], $session, $login['csrf']), 'Mehr als 6 Monate Inaktivität akzeptiert', 422);
    problem(fn() => $page->handle('access_save', ['idle_minutes' => 120, 'max_hours' => 1, 'rights' => []], $session, $login['csrf']), 'Inaktivität länger als Höchstdauer', 422);
    problem(fn() => $page->handle('access_save', ['idle_minutes' => 60, 'max_hours' => 12, 'rights' => ['paid']], $session, $login['csrf']), 'Rechte ohne Passwort', 422);
    problem(fn() => $page->handle('access_save', ['idle_minutes' => 60, 'max_hours' => 12, 'rights' => ['paid'], 'staff_password' => 'ein-langes-passwort'], $session, $login['csrf']), 'Mitarbeiter-Passwort gleich Admin-Passwort', 422);
    problem(fn() => $page->handle('access_save', ['idle_minutes' => 60, 'max_hours' => 12, 'rights' => ['paid']], $session, null), 'Zugang ohne CSRF', 403);
    $acc = $page->handle('access_save', ['idle_minutes' => 60, 'max_hours' => 10, 'rights' => ['paid', 'inbox', 'unsinn'], 'staff_password' => 'Mitarbeiter-Kaffee-2026'], $session, $login['csrf']);
    check($acc['idle_minutes'] === 60 && $acc['max_hours'] === 10 && $acc['staff'] === true && $acc['staff_rights'] === ['paid', 'inbox'], 'Zugang nicht gespeichert');
    $staffPage = new SettingsPage($base);
    $st = $staffPage->handle('login', ['password' => 'Mitarbeiter-Kaffee-2026'], null, null);
    check($st['role'] === 'staff' && str_contains((string)$staffPage->cookie(), 'Max-Age=36000'), 'Mitarbeiter-Anmeldung falsch');
    preg_match('/^ks_admin=([A-Za-z0-9_-]+);/', (string)$staffPage->cookie(), $sm); $ssession = $sm[1];
    $sv = $staffPage->handle('get', [], $ssession, $st['csrf']);
    check($sv['role'] === 'staff' && !isset($sv['sections']) && !isset($sv['access']) && !str_contains(json_encode($sv), 'api_key'), 'Mitarbeiter-Ansicht enthält Einstellungen');
    $staffPage->handle('t2med_paid', [], $ssession, $st['csrf']); $staffPage->handle('inbox_overview', [], $ssession, $st['csrf']);
    check($staffPage->handle('inbox_overview', [], $ssession, $st['csrf'])['letter_enabled'] === false, 'Postversand ohne Recht sichtbar');
    foreach (['save' => ['section' => 'sumup', 'values' => []], 'catalog' => [], 'inbox_letter' => ['id' => 'x'], 'access_save' => ['idle_minutes' => 30, 'max_hours' => 12, 'rights' => ['paid','inbox','letter','catalog']],
              'printer_setup' => [], 'audit' => [], 'update_start' => [], 'firewall_apply' => [], 'catalog_service_delete' => ['id' => 'x']] as $act => $in)
        problem(fn() => $staffPage->handle($act, $in, $ssession, $st['csrf']), "Mitarbeiter darf $act", 403);
    problem(fn() => $staffPage->handle('t2med_done', ['ids' => []], $ssession, null), 'Mitarbeiter ohne CSRF', 403);
    $tmp = []; problem(function () use ($staffPage, $ssession) { $staffPage->clientDownload($ssession, 'windows', fn($h) => null, fn($d) => null); }, 'Mitarbeiter lädt Arbeitsplatz-Paket', 401);
    problem(function () use ($staffPage, $ssession) { $staffPage->taxExport($ssession, ['kind' => 'steuer', 'year' => '2026'], fn($h) => null, fn($d) => null); }, 'Mitarbeiter lädt Steuer-Export', 403);
    // Rechte ändern gilt sofort; Passwortwechsel des Mitarbeiters trennt dessen Sitzungen.
    $page->handle('access_save', ['idle_minutes' => 60, 'max_hours' => 10, 'rights' => ['inbox', 'letter']], $session, $login['csrf']);
    problem(fn() => $staffPage->handle('t2med_paid', [], $ssession, $st['csrf']), 'Entzogenes Recht gilt noch', 403);
    check($staffPage->handle('inbox_overview', [], $ssession, $st['csrf']) !== [], 'Freigegebenes Recht fehlt');
    $page->handle('access_save', ['idle_minutes' => 60, 'max_hours' => 10, 'rights' => ['inbox'], 'remove_staff' => true], $session, $login['csrf']);
    problem(fn() => $staffPage->handle('get', [], $ssession, $st['csrf']), 'Mitarbeiter-Sitzung nach Entfernen gültig', 401);
    problem(fn() => (new SettingsPage($base))->handle('login', ['password' => 'Mitarbeiter-Kaffee-2026'], null, null), 'Entfernter Mitarbeiter meldet sich an', 401);
    $page->handle('access_save', ['idle_minutes' => 30, 'max_hours' => 12, 'rights' => []], $session, $login['csrf']);

    // Gerät merken: langes Cookie, nur aus demselben Netz, heikle Aktionen nur mit Passwort, widerrufbar.
    $rp = new SettingsPage($base); $rp->client = '192.168.1.20';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36';
    $rl = $rp->handle('login', ['password' => 'ein-langes-passwort', 'remember' => true], null, null);
    check($rl['remember'] === true && str_contains((string)$rp->cookie(), 'Max-Age=15811200'), 'Gerät merken: Cookie nicht langlebig');
    preg_match('/^ks_admin=([A-Za-z0-9_-]+);/', (string)$rp->cookie(), $rm); $rsession = $rm[1];
    $dev = $rp->handle('devices', [], $rsession, $rl['csrf'])['devices'];
    check(count($dev) === 1 && $dev[0]['label'] === 'Chrome auf Windows' && $dev[0]['current'] === true && $dev[0]['net'] === '192.168.1.0/24', 'Geräteliste falsch: ' . json_encode($dev));
    $other = new SettingsPage($base); $other->client = '10.9.8.7';
    problem(fn() => $other->handle('get', [], $rsession, $rl['csrf']), 'Gemerktes Gerät aus fremdem Netz akzeptiert', 401);
    $near = new SettingsPage($base); $near->client = '192.168.1.99';
    check(isset($near->handle('get', [], $rsession, $rl['csrf'])['sections']), 'Gemerktes Gerät im selben Netz abgelehnt');
    problem(fn() => $rp->handle('save', ['section' => 'practice', 'values' => ['name' => 'X']], $rsession, $rl['csrf']), 'Speichern auf gemerktem Gerät ohne Passwort', 403);
    check(!empty($rp->handle('save', ['section' => 'terminzettel', 'values' => ['header_text' => 'Praxis Beispiel']], $rsession, $rl['csrf'])['message'] ?? ''), 'Terminzettel speichern verlangt auf gemerktem Gerät ein Passwort');
    problem(fn() => $rp->handle('update_channel', ['channel' => 'beta'], $rsession, $rl['csrf']), 'Heikle Aktion ohne Passwort', 403);
    problem(fn() => $rp->handle('update_channel', ['channel' => 'beta', 'confirm' => 'falsches-passwort'], $rsession, $rl['csrf']), 'Heikle Aktion mit falschem Passwort', 403);
    check(($rp->handle('update_channel', ['channel' => 'beta', 'confirm' => 'ein-langes-passwort'], $rsession, $rl['csrf'])['message'] ?? '') === 'Kanal gespeichert.', 'Heikle Aktion mit Passwort nicht möglich');
    check(!empty($rp->handle('save', ['section' => 'practice', 'values' => ['name' => 'Beispielpraxis']], $rsession, $rl['csrf'])['message'] ?? ''), 'Nach einmaliger Bestätigung erneut nach dem Passwort gefragt (24 Stunden)');
    $rp->handle('t2med_paid', [], $rsession, $rl['csrf']);   // laufende Arbeit braucht kein Passwort
    problem(function () use ($rp, $rsession) { $rp->clientDownload($rsession, 'windows', fn($h) => null, fn($d) => null); }, 'Paket mit Schlüsseln von gemerktem Gerät', 403);
    $db->query('UPDATE admin_sessions SET created_at=? WHERE remember=1', [time() - 15811200 - 10]);
    problem(fn() => $rp->handle('get', [], $rsession, $rl['csrf']), 'Gemerktes Gerät nach 6 Monaten gültig', 401);
    $rl = $rp->handle('login', ['password' => 'ein-langes-passwort', 'remember' => true], null, null);
    preg_match('/^ks_admin=([A-Za-z0-9_-]+);/', (string)$rp->cookie(), $rm); $rsession = $rm[1];
    $rp->handle('device_revoke', ['id' => $rp->handle('devices', [], $rsession, $rl['csrf'])['devices'][0]['id']], $rsession, $rl['csrf']);
    problem(fn() => $rp->handle('get', [], $rsession, $rl['csrf']), 'Abgemeldetes Gerät noch gültig', 401);
    $nr = $rp->handle('login', ['password' => 'ein-langes-passwort'], null, null);
    check($nr['remember'] === false && str_contains((string)$rp->cookie(), 'Max-Age=43200'), 'Normale Anmeldung wurde gemerkt');

    for ($i = 0; $i < 5; $i++) { try { $admin->login('falsch'); } catch (Problem) {} }
    problem(fn() => $admin->login('ein-langes-passwort'), 'Keine Sperre nach Fehlversuchen', 429);
    $page->handle('logout', [], $session, $login['csrf']);
    check($admin->session($session) === null, 'Abmelden wirkungslos');

    check($admin->createSetupToken() === null, 'Einrichtungslink trotz Passwort erzeugt');
    $again = $admin->createSetupToken(true);
    check(is_string($again) && !$admin->configured(), '--admin-reset löscht das Passwort nicht');
    check(Settings::effective($base)->get('practice', 'name') === 'Beispielpraxis', '--admin-reset hat Einstellungen gelöscht');

    // Kaputte Überlagerung (z. B. andere Version): Datei gilt weiter.
    $db->query("UPDATE settings SET value='kaputt' WHERE key='config_overrides'");
    check(Settings::effective($base) === $base, 'Unlesbare Einstellungen nicht ignoriert');
    echo "Einstellungen und Admin-Zugang ok\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
}
