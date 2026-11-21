<?php
declare(strict_types=1);
// Aktualisierung im Webinterface: Versionsvergleich, Änderungsliste, Anforderung, Sperren.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, App, Config, Database, Problem, SettingsPage, Updater};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}

$dirs = [];
try {
    foreach ([['1.4.1-rechnung.13', '1.4.1-rechnung.12', true], ['1.4.1-rechnung.12', '1.4.1-rechnung.12', false], ['1.4.1-rechnung.9', '1.4.1-rechnung.12', false],
              ['1.4.2', '1.4.1-rechnung.12', true], ['1.4.1', '1.4.1-rechnung.12', false], ['1.10.0', '1.9.9', true]] as [$remote, $local, $expected]) {
        check(Updater::newer($remote, $local) === $expected, "Versionsvergleich $remote > $local falsch");
    }
    $release = "Version 1.4.1-rechnung.14 der Seite.\n\n- Neu in rechnung.14: Drittes\n  mit Fortsetzung.\n- Neu in rechnung.13: Zweites.\n- Neu in rechnung.12: Schon installiert.\n- Rechnungseingang mit Schlüssel.\n";
    check(Updater::notes($release, '1.4.1-rechnung.12') === ['Neu in rechnung.14: Drittes mit Fortsetzung.', 'Neu in rechnung.13: Zweites.'], 'Änderungsliste falsch: ' . json_encode(Updater::notes($release, '1.4.1-rechnung.12')));

    $root = sys_get_temp_dir() . '/ks-upd-' . bin2hex(random_bytes(6)); $dirs[] = $root;
    mkdir($root . '/app', 0700, true); mkdir($root . '/state'); mkdir($root . '/data', 0700);
    file_put_contents($root . '/app/VERSION', "1.4.1-rechnung.12\n");
    Updater::$conf = $root . '/update.conf'; Updater::$state = $root . '/state'; Updater::$service = $root . '/service';
    $db = new Database($root . '/data'); $db->migrate();
    $calls = []; $remote = "1.4.1-rechnung.14\n"; $mainRemote = "1.4.1\n";
    $fetch = function (string $url) use (&$calls, &$remote, &$mainRemote, $release): ?string {
        $calls[] = $url;
        if (str_contains($url, 'thomaskien/t2med-sumup/main/VERSION')) return $mainRemote;
        return str_ends_with($url, '/VERSION') ? $remote : (str_ends_with($url, '/docs/RELEASE.md') ? $release : null);
    };
    $updater = new Updater($db, $root . '/app', $fetch);
    check($updater->check()['configured'] === false, 'Ohne update.conf als eingerichtet gemeldet');
    file_put_contents(Updater::$conf, "# Quelle\nrepo=hau-si/kienzle-suite-beta\nbranch=rechnungseingang\n");
    $info = $updater->check();
    check($info['available'] && $info['remote'] === '1.4.1-rechnung.14' && count($info['notes']) === 2, 'Neue Version nicht erkannt: ' . json_encode($info));
    check($calls[0] === 'https://raw.githubusercontent.com/hau-si/kienzle-suite-beta/rechnungseingang/VERSION', 'Falsche Abfrageadresse: ' . $calls[0]);
    check($info['main_available'] === false, 'Hauptzweig ohne neuere Version als Update gemeldet');
    $n = count($calls); $updater->check();
    check(count($calls) === $n, 'Zwischenspeicher nicht genutzt');
    $remote = null; $info = $updater->check(true);
    check(!$info['available'] && $info['error'] !== null, 'Fehlende Verbindung nicht gemeldet');
    $remote = "1.4.1-rechnung.14\n";

    // Kanäle: Bisherige Quelle (Fork) = Beta; „Stabil“ liest den Hauptzweig des Projekts.
    check($updater->channel() === 'beta' && $updater->source() === Updater::BETA, 'Fork-Quelle nicht als Beta erkannt');
    $updater->setChannel('stable'); $calls = [];
    $info = $updater->check();
    check($info['channel'] === 'stable' && $info['source'] === ['repo' => 'thomaskien/t2med-sumup', 'branch' => 'main'] && $calls[0] === 'https://raw.githubusercontent.com/thomaskien/t2med-sumup/main/VERSION', 'Stabiler Kanal liest nicht den Hauptzweig');
    problem(fn() => $updater->setChannel('irgendwas'), 'Unbekannter Kanal akzeptiert', 422);
    $updater->setChannel('beta');
    check($updater->check()['source'] === Updater::BETA, 'Beta-Kanal nicht gewählt');
    $mainRemote = "1.5.0\n"; $info = $updater->check(true);
    check($info['main_available'] === true && $info['main_remote'] === '1.5.0', 'Neuere Version im Hauptzweig nicht erkannt');
    $script = (string)file_get_contents(dirname(__DIR__) . '/scripts/self-update.sh');
    check(str_contains($script, 'repo=' . Updater::STABLE['repo'] . '; branch=' . Updater::STABLE['branch']) && str_contains($script, 'repo=' . Updater::BETA['repo'] . '; branch=' . Updater::BETA['branch']), 'Kanalquellen in PHP und Skript verschieden');

    // Anfordern: nur mit Dienst, nicht während einer Kartenzahlung, nicht doppelt.
    problem(fn() => $updater->request(), 'Anforderung ohne Dienst', 503);
    touch(Updater::$service);
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = $root . '/data'; $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $app = new App(new Config($v)); $app->db->migrate();
    $launch = $app->launch(['context_id' => 'x', 'oauth_token' => '', 'fhir_base_url' => '']);
    parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f); $e = $app->exchange($f['launch'], null);
    $visit = $app->visit($app->browser($e['cookie']), $e['visit_id']);
    $app->saveService($visit, ['label' => 'Attest', 'price' => '10,00']);
    $p = $app->start($visit, ['services' => $app->db->query('SELECT id FROM services')->fetchAll(PDO::FETCH_COLUMN), 'request_id' => App::id()]);
    problem(fn() => $updater->request(), 'Aktualisierung trotz laufender Kartenzahlung', 409);
    $app->mock($visit, ['payment_id' => $p['id'], 'status' => 'successful']);
    check(str_contains($updater->request()['message'], '1.4.1-rechnung.14'), 'Anforderung ohne Version');
    check(is_file($root . '/data/update-request') && $updater->status()['state'] === 'requested', 'Anfragedatei fehlt');
    problem(fn() => $updater->request(), 'Doppelte Anforderung', 409);
    check(file_get_contents($root . '/data/update-request') === 'beta', 'Kanal fehlt in der Anfragedatei');
    unlink($root . '/data/update-request');
    file_put_contents(Updater::$state . '/status.json', json_encode(['state' => 'failed', 'message' => 'Fehlgeschlagen; zurückgesetzt.', 'from' => 'a', 'to' => 'b', 'finished' => 1]));
    file_put_contents(Updater::$state . '/update.log', "Zeile 1\nZeile 2\x1b[31m\n");
    $status = $updater->status();
    check($status['state'] === 'failed' && $status['log'] === ['Zeile 1', 'Zeile 2[31m'], 'Status/Protokoll falsch: ' . json_encode($status));
    file_put_contents($root . '/app/VERSION', "1.4.1-rechnung.14\n");
    check($updater->check(true)['available'] === false, 'Aktuelle Version als Update gemeldet');
    file_put_contents($root . '/app/VERSION', "1.4.1-rechnung.12\n");

    // Über die Einstellungsseite: Anmeldung und CSRF für das Starten.
    $page = new SettingsPage($app->config);
    $page->setUpdater($updater);
    problem(fn() => $page->handle('update_check', [], null, null), 'Prüfen ohne Anmeldung', 401);
    $admin = new Admin($app->db, $root . '/data');
    $login = $page->handle('setup', ['token' => $admin->createSetupToken(), 'password' => 'beispiel-passwort'], null, null);
    preg_match('/^ks_admin=([^;]+)/', (string)$page->cookie(), $m);
    check($page->handle('update_check', ['force' => true], $m[1], null)['available'] === true, 'Prüfen über die Seite falsch');
    problem(fn() => $page->handle('update_start', [], $m[1], 'falsch'), 'Start ohne CSRF', 403);
    check(str_contains($page->handle('update_start', [], $m[1], $login['csrf'])['message'], 'gestartet'), 'Start über die Seite falsch');
    echo "Aktualisierung im Webinterface ok\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
}
