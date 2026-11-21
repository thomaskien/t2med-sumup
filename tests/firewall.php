<?php
declare(strict_types=1);
// Netzwerkabfrage und Firewall-Anfragen der Weboberfläche (ohne echten root-Dienst).
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, Config, Database, Firewall, Network, Problem, SettingsPage};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function http(callable $fn): int { try { $fn(); } catch (Problem $e) { return $e->http; } return 0; }

// Netzadressen
check(Network::normalize('192.168.1.77/24') === '192.168.1.0/24', 'normalize v4');
check(Network::normalize('fd12:3456::1/48') === 'fd12:3456::/48', 'normalize v6');
check(Network::normalize('192.168.1.0/33') === null && Network::normalize('abc') === null && Network::normalize('10.0.0.0/8/1') === null, 'ungültige Netze akzeptiert');
check(Network::contains('192.168.1.0/24', '192.168.1.200') && !Network::contains('192.168.1.0/24', '192.168.2.1'), 'contains v4');
check(Network::contains('192.168.1.0/24', '::ffff:192.168.1.5'), 'IPv4-mapped nicht erkannt');
check(Network::contains('fd12::/16', 'fd12:1::5') && !Network::contains('fd12::/16', 'fd13::1'), 'contains v6');
foreach (['10.1.0.0/16', '172.16.0.0/12', '172.31.5.0/24', '192.168.0.0/16', '100.64.0.0/10', 'fd00::/8'] as $p) check(Network::isPrivate($p), "$p nicht privat");
foreach (['0.0.0.0/0', '8.8.8.0/24', '172.32.0.0/16', '192.169.0.0/24', '10.0.0.0/7', '::/0', '2001:db8::/32'] as $p) check(!Network::isPrivate($p), "$p fälschlich privat");

// Abfrage mit festen Beispielausgaben
$answers = [
    'addr' => json_encode([['ifname' => 'lo', 'operstate' => 'UNKNOWN', 'addr_info' => [['family' => 'inet', 'local' => '127.0.0.1', 'prefixlen' => 8, 'scope' => 'host']]],
        ['ifname' => 'enp3s0', 'operstate' => 'UP', 'addr_info' => [['family' => 'inet', 'local' => '192.168.1.5', 'prefixlen' => 24, 'scope' => 'global'], ['family' => 'inet6', 'local' => 'fe80::1', 'prefixlen' => 64, 'scope' => 'link']]],
        ['ifname' => 'wg0', 'operstate' => 'UNKNOWN', 'addr_info' => [['family' => 'inet', 'local' => '203.0.113.7', 'prefixlen' => 32, 'scope' => 'global']]]]),
    'route' => json_encode([['dst' => 'default', 'gateway' => '192.168.1.1', 'dev' => 'enp3s0']]),
    'neigh' => json_encode([['dst' => '192.168.1.20', 'dev' => 'enp3s0', 'state' => ['REACHABLE']], ['dst' => '192.168.1.99', 'dev' => 'enp3s0', 'state' => ['FAILED']], ['dst' => 'fe80::2', 'dev' => 'enp3s0', 'state' => ['STALE']]]),
    'ss' => "LISTEN 0 4096 0.0.0.0:7868 0.0.0.0:*\nLISTEN 0 128 127.0.0.1:631 0.0.0.0:*\nLISTEN 0 128 [::]:22 [::]:*\nLISTEN 0 128 0.0.0.0:22 0.0.0.0:*\n",
];
$fake = function (array $c) use ($answers): ?string {
    if (str_ends_with($c[0], 'ss')) return $answers['ss'];
    return match ($c[2] ?? '') { 'addr' => $answers['addr'], 'route' => $answers['route'], 'neigh' => $answers['neigh'], default => null };
};
// Die Werkzeuge müssen existieren, damit detect() sie aufruft: Pfade des Prüfsystems verwenden.
{
    $n = (new Network($fake))->detect();
    check(count($n['interfaces']) === 2 && $n['interfaces'][0]['name'] === 'enp3s0', 'Schnittstellen falsch');
    check($n['interfaces'][0]['addresses'][0]['network'] === '192.168.1.0/24' && $n['interfaces'][0]['addresses'][0]['private'], 'Netz falsch');
    check(count($n['interfaces'][0]['addresses']) === 1, 'Link-Local-IPv6 nicht ausgeblendet');
    check(!$n['interfaces'][1]['addresses'][0]['private'], 'öffentliche Adresse als privat');
    check($n['gateway']['ip'] === '192.168.1.1', 'Gateway falsch');
    check(count($n['neighbors']) === 1 && $n['neighbors'][0]['ip'] === '192.168.1.20', 'Nachbarn falsch');
    check(array_column($n['listening'], 'port') === [22, 631, 7868] && $n['listening'][1]['local_only'] === true && $n['listening'][0]['local_only'] === false, 'lauschende Ports falsch');
}
check((new Network(fn() => null))->detect()['interfaces'] === [], 'Ausfall der Abfrage nicht abgefangen');

// Firewall-Anfragen
$state = sys_get_temp_dir() . '/ks-fw-' . bin2hex(random_bytes(6)); mkdir($state, 0700);
Firewall::$state = $state . '/fw'; mkdir(Firewall::$state);
Firewall::$service = $state . '/service'; // fehlt zunächst
try {
    $db = new Database($state); $db->migrate();
    $fw = new Firewall($db, new Network(fn() => null));
    $in = ['networks' => ['192.168.1.0/24'], 'extra' => '10.8.0.0/24, 192.168.5.0/24', 'ssh' => true, 'ipp' => true, 'strict' => false];
    $ok = $fw->validate($in, '192.168.1.20');
    check($ok['cidrs'] === ['192.168.1.0/24', '10.8.0.0/24', '192.168.5.0/24'] && $ok['ssh'] === true && $ok['strict'] === false, 'Eingabe nicht übernommen');
    check(http(fn() => $fw->validate(['networks' => ['192.168.1.0/24']], '192.168.7.7')) === 422, 'Aussperren des eigenen Geräts nicht verhindert');
    check(http(fn() => $fw->validate(['networks' => ['192.168.1.0/24']], '::ffff:192.168.1.8')) === 0, 'IPv4-mapped Gerät fälschlich ausgesperrt');
    check(http(fn() => $fw->validate(['networks' => []], '192.168.1.20')) === 422, 'leere Netzliste akzeptiert');
    check(http(fn() => $fw->validate(['networks' => ['0.0.0.0/0']], '192.168.1.20')) === 422, '0.0.0.0/0 akzeptiert');
    check(http(fn() => $fw->validate(['networks' => ['8.8.8.0/24', '192.168.1.0/24']], '192.168.1.20')) === 422, 'öffentliches Netz akzeptiert');
    check(http(fn() => $fw->validate(['networks' => ['192.168.1.0/24; reboot']], '192.168.1.20')) === 422, 'Einschleusung akzeptiert');
    check(http(fn() => $fw->validate(['networks' => array_map(fn($i) => "10.$i.0.0/16", range(1, 21))], '10.1.0.5')) === 422, 'mehr als 20 Netze');
    check(http(fn() => $fw->validate(['networks' => ['10.0.0.0/8']], '127.0.0.1')) === 0, 'Zugriff vom Server selbst abgelehnt');
    check($fw->validate(['networks' => ['192.168.1.0/24'], 'ssh' => 'ja'], '192.168.1.20')['ssh'] === false, 'ssh nicht streng boolesch');

    // Dienste der Kienzle-Projekte und eigene Ports
    $svc = $fw->validate(['networks' => ['192.168.1.0/24'], 'services' => ['middleware', 'ami', 'middleware'], 'custom' => "tcp 8080\nudp 5000 - 5100, TCP:9000"], '192.168.1.20');
    check($svc['services'] === ['middleware', 'ami'] && $svc['custom'] === ['tcp 8080', 'udp 5000-5100', 'tcp 9000'], 'Dienste/eigene Ports falsch: ' . json_encode($svc));
    foreach ([['services' => ['rootkit']], ['services' => 'x'], ['custom' => 'tcp 0'], ['custom' => 'tcp 70000'], ['custom' => 'udp 200-100'], ['custom' => 'icmp 5'], ['custom' => 'tcp 80; reboot'], ['custom' => implode("\n", array_map(fn($i) => 'tcp ' . (1000 + $i), range(1, 21)))]] as $bad) {
        check(http(fn() => $fw->validate(['networks' => ['192.168.1.0/24']] + $bad, '192.168.1.20')) === 422, 'ungültig akzeptiert: ' . json_encode($bad));
    }
    // Katalog muss mit dem root-Dienst übereinstimmen
    $python = json_decode((string)shell_exec('python3 -I -c ' . escapeshellarg("import importlib.util,json,sys;s=importlib.util.spec_from_file_location('fw','" . dirname(__DIR__) . "/scripts/firewall-apply.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m);print(json.dumps(m.SERVICES))")), true);
    $php = [];
    foreach (Firewall::SERVICES as $id => $d) $php[$id] = array_filter(['tcp' => $d['tcp'] ?? null, 'udp' => $d['udp'] ?? null, 'scope' => $d['scope']]);
    ksort($php); ksort($python);
    check($php == $python, 'Dienstekatalog in PHP und im root-Dienst weichen ab');
    // Vorschlag: lauschende Dienste vorwählen
    $withMiddleware = new Firewall($db, new Network(function (array $c) use ($answers) {
        if (str_ends_with($c[0], 'ss')) return "LISTEN 0 1 0.0.0.0:8766 0.0.0.0:*\nLISTEN 0 1 0.0.0.0:443 0.0.0.0:*\n";
        return match ($c[2] ?? '') { 'addr' => $answers['addr'], default => null };
    }));
    $sug = $withMiddleware->overview('192.168.1.20')['config'];
    check($sug['services'] === ['middleware'], 'lauschende Middleware nicht vorgewählt: ' . json_encode($sug['services']));

    // Ohne Dienst keine Anfrage
    check(http(fn() => $fw->apply($in, '192.168.1.20')) === 503, 'Anwenden ohne Dienst');
    touch(Firewall::$service);
    $r = $fw->apply($in, '192.168.1.20');
    $req = json_decode((string)file_get_contents($state . '/firewall-request'), true);
    check($req['action'] === 'apply' && $req['cidrs'] === $ok['cidrs'] && $req['ssh'] === true && array_keys($req) === ['action', 'cidrs', 'ssh', 'ipp', 'strict', 'services', 'custom'], 'Anfragedatei falsch');
    check($fw->status()['state'] === 'requested', 'Zustand nach Anfrage');
    check(http(fn() => $fw->apply($in, '192.168.1.20')) === 409, 'Doppelanfrage erlaubt');
    check(http(fn() => $fw->confirm()) === 409, 'Bestätigen ohne wartende Änderung');
    check(($fw->overview('192.168.1.20')['config']['cidrs'] ?? []) === $ok['cidrs'] && $fw->overview('192.168.1.20')['saved'], 'Konfiguration nicht gespeichert');

    // Dienst meldet „wartet auf Bestätigung“
    unlink($state . '/firewall-request');
    file_put_contents(Firewall::$state . '/status.json', json_encode(['state' => 'pending', 'message' => 'x', 'deadline' => time() + 100, 'networks' => $ok['cidrs'], 'ports' => [7868]]));
    check($fw->status()['state'] === 'pending', 'pending nicht erkannt');
    check(http(fn() => $fw->apply($in, '192.168.1.20')) === 409, 'Anwenden während Bestätigung');
    $fw->confirm();
    check(is_file($state . '/firewall-confirm'), 'Bestätigungsdatei fehlt');
    // Abgelaufen, aber der Dienst hat nichts gemeldet (z. B. Server neu gestartet): als zurückgesetzt anzeigen
    file_put_contents(Firewall::$state . '/status.json', json_encode(['state' => 'pending', 'deadline' => time() - 60]));
    check($fw->status()['state'] === 'reverted', 'abgelaufenes pending nicht als zurückgesetzt');

    // Ausschalten
    $fw->disable();
    check(json_decode((string)file_get_contents($state . '/firewall-request'), true) === ['action' => 'disable'], 'Ausschalt-Anfrage falsch');

    // Über die Einstellungsseite: Anmeldepflicht und CSRF
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = $state; $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $page = new SettingsPage(new Config($v)); $page->client = '192.168.1.20';
    check(http(fn() => $page->handle('firewall_info', [], null, null)) === 401, 'Firewall-Info ohne Anmeldung');
    Admin::$failDelay = 0;
    $admin = new Admin($db, $state, '192.168.1.20'); $pw = 'Kaffee-Tasse-Fenster-42';
    $admin->setup($admin->createSetupToken(), $pw); $s = $admin->login($pw);
    check(http(fn() => $page->handle('firewall_apply', $in, $s['token'], 'falsch')) === 403, 'Anwenden ohne CSRF');
    check(http(fn() => $page->handle('firewall_disable', [], $s['token'], '')) === 403, 'Ausschalten ohne CSRF');
    $info = $page->handle('firewall_info', [], $s['token'], null);
    check($info['client']['ip'] === '192.168.1.20' && isset($info['network'], $info['config'], $info['status']), 'Info unvollständig');
    $page->handle('firewall_disable', [], $s['token'], $s['csrf']);
    check(str_contains((string)file_get_contents($state . '/admin-audit.log'), 'firewall_disable'), 'Firewall-Änderung nicht protokolliert');
    echo "OK firewall\n";
} finally {
    exec('rm -rf ' . escapeshellarg($state));
}
