<?php
declare(strict_types=1);
// Härtung: Sperre pro Absender, Sitzungs-Höchstdauer, Passwortqualität, Schlüsseldrosselung, Protokoll.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, AuditLog, Database, Problem, Throttle};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function http(callable $fn): int { try { $fn(); } catch (Problem $e) { return $e->http; } return 0; }

Admin::$failDelay = 0;
$state = sys_get_temp_dir() . '/ks-sec-' . bin2hex(random_bytes(6));
mkdir($state, 0700);
try {
    $db = new Database($state); $db->migrate();
    $good = 'Kaffee-Tasse-Fenster-42';

    // Passwortqualität
    foreach (['passwort123', 'aaaaaaaaaaaa', '1234567890', 'AdminAdmin1', 'qwertzuiop', 'Praxis12345'] as $bad) check(Admin::weak($bad), "$bad nicht abgelehnt");
    foreach ([$good, 'Zebra-Lampe-Regen-77', 'x9$kQ2!vLm0w'] as $ok) check(!Admin::weak($ok), "$ok fälschlich abgelehnt");
    check(!Admin::weak('Das Passwort der Praxis Musterstadt 2026'), 'lange Passphrase mit Wort „Passwort“ abgelehnt');

    // Sperre gilt nur für den Absender, nicht für die Praxis
    $angreifer = new Admin($db, $state, '203.0.113.9');
    $praxis = new Admin($db, $state, '192.0.2.10');
    $token = $angreifer->createSetupToken();
    $angreifer->setup($token, $good);
    for ($i = 0; $i < 5; $i++) check(http(fn() => $angreifer->login('falsch-falsch-1')) === 401, 'Fehlversuch nicht 401');
    check(http(fn() => $angreifer->login($good)) === 429, 'Absender nach 5 Fehlversuchen nicht gesperrt (auch nicht mit richtigem Passwort)');
    $session = $praxis->login($good);
    check(!empty($session['token']), 'Praxis wurde mit ausgesperrt');

    // Sperre wird bei Wiederholung länger (nach Ablauf wieder 5 Fehler → doppelte Dauer)
    $row = $db->one("SELECT key,value FROM settings WHERE key LIKE 'throttle:login:%'");
    $s = json_decode($row['value'], true);
    check($s['lock'] === 300, 'erste Sperre nicht 5 Minuten');
    $s['until'] = time() - 1; $db->query('UPDATE settings SET value=? WHERE key=?', [json_encode($s), $row['key']]);
    for ($i = 0; $i < 5; $i++) http(fn() => $angreifer->login('falsch-falsch-1'));
    $s = json_decode($db->one('SELECT value FROM settings WHERE key=?', [$row['key']])['value'], true);
    check($s['lock'] === 600, 'zweite Sperre nicht doppelt so lang: ' . $s['lock']);

    // Erfolgreiche Anmeldung setzt den Zähler zurück
    $db->query("DELETE FROM settings WHERE key LIKE 'throttle:%'");
    http(fn() => $praxis->login('falsch-falsch-1'));
    $praxis->login($good);
    check(!$db->one("SELECT 1 FROM settings WHERE key LIKE 'throttle:login:%'"), 'Zähler nach Erfolg nicht gelöscht');

    // IPv6: ein ganzes /64-Netz zählt als ein Absender
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa::1'; $a = Throttle::clientAddress();
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:bbbb::9'; $b = Throttle::clientAddress();
    check($a === $b, 'IPv6-Adressen desselben /64 nicht gleich behandelt');
    unset($_SERVER['REMOTE_ADDR']);

    // Sitzung: Höchstdauer 12 Stunden, auch bei ständiger Nutzung
    $fresh = $praxis->login($good);
    check($praxis->session($fresh['token']) !== null, 'frische Sitzung ungültig');
    $db->query('UPDATE admin_sessions SET created_at=? WHERE token_hash=?', [time() - 43300, hash('sha256', $fresh['token'])]);
    check($praxis->session($fresh['token']) === null, 'Sitzung älter als 12 Stunden noch gültig');
    check(!$db->one('SELECT 1 FROM admin_sessions WHERE token_hash=?', [hash('sha256', $fresh['token'])]), 'abgelaufene Sitzung nicht gelöscht');

    // Passwortwechsel prüft das alte Passwort mit Sperre und lehnt schwache neue ab
    check(http(fn() => $praxis->changePassword($good, 'passwort1234')) === 422, 'schwaches neues Passwort akzeptiert');

    // Gesamtbremse bei verteiltem Angriff
    $db->query("DELETE FROM settings WHERE key LIKE 'throttle:%'");
    for ($i = 0; $i < 200; $i++) { $x = new Admin($db, $state, '198.51.100.' . ($i % 250) . '.' . $i); http(fn() => $x->login('falsch-falsch-1')); if ($i === 150) usleep(0); }
    check(http(fn() => (new Admin($db, $state, '192.0.2.77'))->login($good)) === 429, 'Gesamtbremse greift nicht');
    $db->query("DELETE FROM settings WHERE key IN ('admin_global_lock','admin_global_failures')");

    // Schlüssel-Drosselung (Starter/Druckserver): 10 Fehler → Sperre
    $t = new Throttle($db, '203.0.113.50');
    for ($i = 0; $i < 10; $i++) $t->fail('launcher', 10, 600);
    check(http(fn() => $t->check('launcher')) === 429, 'Starter-Schlüssel wird nicht gedrosselt');
    check(http(fn() => (new Throttle($db, '203.0.113.51'))->check('launcher')) === 0, 'fremder Absender fälschlich gesperrt');

    // Protokoll: Anmeldungen stehen drin, aber niemals Passwörter
    $log = (string)file_get_contents($state . '/admin-audit.log');
    check(str_contains($log, 'login-ok') && str_contains($log, 'login-fehlgeschlagen'), 'Protokoll unvollständig');
    check(!str_contains($log, $good) && !str_contains($log, 'falsch-falsch'), 'Passwort im Protokoll');
    check(decoct(fileperms($state . '/admin-audit.log') & 0777) === '600', 'Protokoll nicht privat');
    $audit = new AuditLog($state);
    $audit->write('save', "x\nINJECT 127.0.0.1 login-ok");
    $last = $audit->recent(1)[0];
    check(substr_count((string)file_get_contents($audit->path()), "\n") === count(file($audit->path())), 'Zeilenumbruch eingeschleust');
    check(!str_contains($last, "\n"), 'Protokolleintrag mit Zeilenumbruch');
    for ($i = 0; $i < 4000; $i++) $audit->write('save', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    check(filesize($audit->path()) < 400000, 'Protokoll wächst unbegrenzt');

    // --admin-reset hebt Sperren auf
    $reset = new Admin($db, $state, '203.0.113.9');
    $reset->createSetupToken(true);
    check(!$db->one("SELECT 1 FROM settings WHERE key LIKE 'throttle:%'"), 'Reset löscht Sperren nicht');
    echo "OK security\n";
} finally {
    exec('rm -rf ' . escapeshellarg($state));
}
