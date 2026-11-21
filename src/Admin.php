<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Zugang zur Einstellungsseite. Ersteinrichtung über einen einmaligen Link, den der Installer
 * ausgibt; danach Admin-Passwort. Arbeitsplätze kassieren weiterhin ohne Passwort.
 */
final class Admin {
    public const COOKIE = 'ks_admin';
    private const TTL = 1800;          // Leerlauf: Standard 30 Minuten ohne Aktion (einstellbar)
    private const MAX_AGE = 43200;     // Höchstdauer einer Sitzung: Standard 12 Stunden (einstellbar)
    /** Rechte des Mitarbeiter-Zugangs (Schalter in den Einstellungen) und die Aktionen, die sie freigeben. */
    public const RIGHTS = [
        'paid' => ['Bezahlt-Liste abhaken', ['t2med_paid', 't2med_done', 't2med_correct']],
        'inbox' => ['Rechnungseingang ansehen und Rechnungen löschen', ['inbox_overview', 'inbox_delete', 'inbox_delete_many', 'inbox_clear']],
        'letter' => ['Rechnungen per Post (Letterxpress) senden', ['inbox_overview', 'inbox_letter', 'inbox_letter_many']],
        'catalog' => ['Leistungen und GOÄ-Positionen bearbeiten', ['catalog', 'catalog_service_save', 'catalog_service_delete', 'catalog_group_save', 'catalog_group_delete']],
    ];
    /** „Gerät merken“: bis zu 30 Tage ohne Nutzung, höchstens 6 Monate, nur aus dem Netz der Anmeldung. */
    private const REMEMBER_IDLE = 2592000;
    public const REMEMBER_MAX = 15811200;
    /** Adresse des anfragenden Geräts (für Tests und die Einstellungsseite setzbar). */
    public ?string $client = null;
    public const MAX_IDLE_MINUTES = 263520;   // 183 Tage (ca. 6 Monate)
    public const MAX_HOURS = 4392;            // 183 Tage
    public const DEFAULT_RIGHTS = ['paid', 'inbox'];
    private const GLOBAL_LIMIT = 200;  // Fehlversuche aller Absender je Stunde, bevor Anmeldungen kurz pausieren

    /** Wartezeit nach falschem Passwort in Mikrosekunden (bremst Raten; Tests setzen 0). */
    public static int $failDelay = 800000;
    public readonly Throttle $throttle;
    public readonly AuditLog $audit;

    public function __construct(private Database $db, private string $stateDir, ?string $client = null) {
        $this->throttle = new Throttle($db, $client ?? '');
        $this->audit = new AuditLog($stateDir);
    }

    public function tokenPath(): string { return rtrim($this->stateDir, '/') . '/admin-setup-token'; }
    public function configured(): bool { return $this->setting('admin_password_hash') !== null; }

    /** Für den Installer: neuen Einrichtungslink erzeugen (bei --admin-reset auch das Passwort löschen). */
    public function createSetupToken(bool $reset = false): ?string {
        if ($reset) {
            $this->db->query("DELETE FROM settings WHERE key IN ('admin_password_hash','admin_failures','admin_global_failures','admin_global_lock')");
            Throttle::clearAll($this->db);
            $this->db->query('DELETE FROM admin_sessions');
        }
        if ($this->configured()) return null;
        $token = bin2hex(random_bytes(24));
        $path = $this->tokenPath();
        if (file_put_contents($path . '.new', hash('sha256', $token)) === false || !chmod($path . '.new', 0600) || !rename($path . '.new', $path)) {
            throw new Problem('Einrichtungslink konnte nicht gespeichert werden.', 500);
        }
        return $token;
    }

    public function setup(string $token, string $password): array {
        if ($this->configured()) throw new Problem('Das Admin-Passwort ist bereits festgelegt. Bitte anmelden.', 409);
        $this->checkLock();
        $stored = is_file($this->tokenPath()) ? trim((string)file_get_contents($this->tokenPath())) : '';
        if ($stored === '' || !hash_equals($stored, hash('sha256', $token))) {
            $this->failed('setup');
            throw new Problem('Einrichtungslink ungültig. Neuen Link erzeugen: sudo bash scripts/install-server.sh --admin-reset', 403);
        }
        $this->setPassword($password);
        @unlink($this->tokenPath());
        $this->audit->write('setup-ok');
        return $this->open();
    }

    public function login(string $password, bool $remember = false, string $agent = ''): array {
        $this->checkLock();
        $hash = $this->setting('admin_password_hash');
        if ($hash === null) throw new Problem('Noch kein Admin-Passwort festgelegt. Bitte den Einrichtungslink aus dem Installer öffnen.', 409);
        $staff = $this->setting('staff_password_hash');
        if (!password_verify($password, $hash)) {
            if ($staff !== null && password_verify($password, $staff)) {   // Mitarbeiter-Zugang mit eigenem Passwort
                $this->throttle->clear('login');
                $this->audit->write('login-ok', 'Mitarbeiter' . ($remember ? ' Geraet-gemerkt' : ''));
                return $this->open('staff', $remember, $agent);
            }
            $this->failed('login'); usleep(self::$failDelay); throw new Problem('Passwort falsch.', 401);
        }
        $this->throttle->clear('login');
        $this->audit->write('login-ok', $remember ? 'Geraet-gemerkt' : '');
        if (password_needs_rehash($hash, self::algorithm())) $this->setPassword($password);
        return $this->open('admin', $remember, $agent);
    }

    // ---- Zugang: Abmeldezeiten und Mitarbeiter-Zugang ----------------------------------------
    /** Minuten ohne Aktion bis zur Abmeldung (5 bis 480). */
    public function idleSeconds(): int { return max(5, min(self::MAX_IDLE_MINUTES, (int)($this->setting('admin_idle_minutes') ?? self::TTL / 60))) * 60; }
    /** Stunden bis zur Zwangs-Abmeldung auch bei ständiger Nutzung (1 bis 72). */
    public function maxSeconds(): int { return max(1, min(self::MAX_HOURS, (int)($this->setting('admin_max_hours') ?? self::MAX_AGE / 3600))) * 3600; }
    public function staffConfigured(): bool { return $this->setting('staff_password_hash') !== null; }
    /** Aktuell freigegebene Rechte des Mitarbeiter-Zugangs. */
    public function staffRights(): array {
        $stored = json_decode((string)$this->setting('staff_rights'), true);
        return array_values(array_intersect(array_keys(self::RIGHTS), is_array($stored) ? $stored : self::DEFAULT_RIGHTS));
    }
    public function access(): array {
        $rights = [];
        foreach (self::RIGHTS as $key => [$label]) $rights[] = ['key' => $key, 'label' => $label];
        return ['idle_minutes' => intdiv($this->idleSeconds(), 60), 'max_hours' => intdiv($this->maxSeconds(), 3600), 'staff' => $this->staffConfigured(),
            'staff_rights' => $this->staffRights(), 'rights' => $rights];
    }
    /** Speichert Abmeldezeiten und Mitarbeiter-Zugang. $password leer = Passwort unverändert; $remove löscht den Zugang. */
    public function saveAccess(int $idleMinutes, int $maxHours, array $rights, string $password, bool $remove): void {
        if ($idleMinutes < 5 || $idleMinutes > self::MAX_IDLE_MINUTES) throw new Problem('Die Abmeldung bei Inaktivität muss zwischen 5 Minuten und 6 Monaten (183 Tagen) liegen.', 422);
        if ($maxHours < 1 || $maxHours > self::MAX_HOURS) throw new Problem('Die Höchstdauer einer Sitzung muss zwischen 1 Stunde und 6 Monaten (183 Tagen) liegen.', 422);
        if ($idleMinutes * 60 > $maxHours * 3600) throw new Problem('Die Abmeldung bei Inaktivität darf nicht länger sein als die Höchstdauer der Sitzung.', 422);
        $rights = array_values(array_intersect(array_keys(self::RIGHTS), array_map('strval', $rights)));
        if ($password !== '') {
            $admin = (string)$this->setting('admin_password_hash');
            if (password_verify($password, $admin)) throw new Problem('Das Mitarbeiter-Passwort muss sich vom Admin-Passwort unterscheiden.', 422);
            $this->checkPassword($password);
        } elseif (!$remove && !$this->staffConfigured() && $rights) {
            throw new Problem('Bitte ein Passwort für den Mitarbeiter-Zugang festlegen.', 422);
        }
        $this->store('admin_idle_minutes', (string)$idleMinutes);
        $this->store('admin_max_hours', (string)$maxHours);
        $this->store('staff_rights', json_encode($rights));
        if ($remove) {
            $this->db->query("DELETE FROM settings WHERE key='staff_password_hash'");
            $this->db->query("DELETE FROM admin_sessions WHERE role='staff'");
        } elseif ($password !== '') {
            $this->store('staff_password_hash', password_hash($password, self::algorithm()));
            $this->db->query("DELETE FROM admin_sessions WHERE role='staff'");
        }
        $this->audit->write('zugang-gespeichert', $remove ? 'Mitarbeiter entfernt' : ($password !== '' ? 'Mitarbeiter-Passwort neu' : ''));
    }

    public function changePassword(string $current, string $new, string $role = 'admin'): void {
        $this->checkLock();
        if ($role === 'staff') {
            if (!password_verify($current, (string)$this->setting('staff_password_hash'))) { $this->failed('login'); usleep(self::$failDelay); throw new Problem('Bisheriges Passwort falsch.', 401); }
            if (password_verify($new, (string)$this->setting('admin_password_hash'))) throw new Problem('Das Mitarbeiter-Passwort muss sich vom Admin-Passwort unterscheiden.', 422);
            $this->checkPassword($new);
            $this->store('staff_password_hash', password_hash($new, self::algorithm()));
            $this->db->query("DELETE FROM admin_sessions WHERE role='staff'");
            $this->audit->write('passwort-geaendert', 'Mitarbeiter');
            return;
        }
        if (!password_verify($current, (string)$this->setting('admin_password_hash'))) { $this->failed('login'); usleep(self::$failDelay); throw new Problem('Bisheriges Passwort falsch.', 401); }
        if ($this->staffConfigured() && password_verify($new, (string)$this->setting('staff_password_hash'))) throw new Problem('Das Admin-Passwort muss sich vom Mitarbeiter-Passwort unterscheiden.', 422);
        $this->setPassword($new);
        $this->audit->write('passwort-geaendert');
    }

    /** Liefert die Sitzung zu einem Cookie-Wert oder null; verlängert sie gleitend. */
    public function session(?string $token): ?array {
        if (!$token || !preg_match('/^[A-Za-z0-9_-]{40,64}$/D', $token)) return null;
        $row = $this->db->one('SELECT * FROM admin_sessions WHERE token_hash=? AND expires_at>?', [hash('sha256', $token), time()]);
        if (!$row) return null;
        $remember = (int)$row['remember'] === 1;
        // Gemerkte Geräte gelten nur aus dem Netz der Anmeldung: ein gestohlenes Cookie nützt von außen nichts.
        if ($remember && !hash_equals((string)$row['net'], self::net($this->client ?? (string)($_SERVER['REMOTE_ADDR'] ?? '')))) return null;
        $max = $remember ? self::REMEMBER_MAX : $this->maxSeconds();
        if ((int)$row['created_at'] < time() - $max) {   // zu alt: auch bei Dauernutzung neu anmelden
            $this->db->query('DELETE FROM admin_sessions WHERE token_hash=?', [$row['token_hash']]);
            return null;
        }
        $this->db->query('UPDATE admin_sessions SET expires_at=?, last_used=? WHERE token_hash=?', [min(time() + ($remember ? self::REMEMBER_IDLE : $this->idleSeconds()), (int)$row['created_at'] + $max), time(), $row['token_hash']]);
        return $row;
    }
    public function logout(?string $token): void {
        if ($token) $this->db->query('DELETE FROM admin_sessions WHERE token_hash=?', [hash('sha256', $token)]);
    }
    public static function cookie(string $value, int $ttl = self::TTL): string {
        return self::COOKIE . '=' . $value . '; Path=/; Secure; HttpOnly; SameSite=Strict; Max-Age=' . $ttl;
    }

    /** Netz des Geräts: IPv4 als /24, IPv6 als /64. */
    public static function net(string $address): string {
        if (str_contains($address, ':') && ($packed = @inet_pton($address)) !== false && strlen($packed) === 16) return bin2hex(substr($packed, 0, 8)) . '/64';
        if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}$/D', $address, $m)) return $m[1] . '.0/24';
        return $address;
    }
    /** Kurze Gerätebeschreibung aus dem Browser-Kennzeichen, z. B. „Chrome auf Windows“. */
    public static function describeAgent(string $agent): string {
        $browser = preg_match('/Edg\//', $agent) ? 'Edge' : (preg_match('/Firefox\//', $agent) ? 'Firefox' : (preg_match('/Chrome\//', $agent) ? 'Chrome' : (preg_match('/Safari\//', $agent) ? 'Safari' : 'Browser')));
        $system = preg_match('/Windows/', $agent) ? 'Windows' : (preg_match('/iPhone|iPad/', $agent) ? 'iOS' : (preg_match('/Android/', $agent) ? 'Android' : (preg_match('/Mac OS X/', $agent) ? 'macOS' : (preg_match('/Linux/', $agent) ? 'Linux' : '?'))));
        return $browser . ' auf ' . $system;
    }
    /** Gemerkte Geräte (ohne die Sitzungswerte selbst). $current = Cookie-Wert der aufrufenden Sitzung. */
    public function devices(?string $current): array {
        $own = $current ? hash('sha256', $current) : '';
        $out = [];
        foreach ($this->db->query('SELECT token_hash,role,label,created_at,last_used,net FROM admin_sessions WHERE remember=1 AND expires_at>? ORDER BY last_used DESC', [time()])->fetchAll() as $r) {
            $out[] = ['id' => substr($r['token_hash'], 0, 16), 'role' => $r['role'], 'label' => $r['label'], 'created_at' => (int)$r['created_at'], 'last_used' => (int)$r['last_used'], 'net' => $r['net'], 'current' => $r['token_hash'] === $own];
        }
        return $out;
    }
    /** Gemerktes Gerät abmelden; „all“ = alle gemerkten Geräte. */
    public function revoke(string $id): int {
        if ($id === 'all') { $n = $this->db->query('DELETE FROM admin_sessions WHERE remember=1')->rowCount(); }
        elseif (preg_match('/^[a-f0-9]{16}$/D', $id)) { $n = $this->db->query('DELETE FROM admin_sessions WHERE remember=1 AND substr(token_hash,1,16)=?', [$id])->rowCount(); }
        else throw new Problem('Ungültiges Gerät.', 422);
        $this->audit->write('geraet-abgemeldet', $id === 'all' ? 'alle' : '');
        return $n;
    }
    /** Passwort für heikle Aktionen von gemerkten Geräten erneut verlangen. */
    public const CONFIRM_SECONDS = 86400;
    public function confirmPassword(string $password): void {
        $this->checkLock();
        if ($password === '') throw new Problem('Passwort zur Bestätigung erforderlich.', 403);
        if (!password_verify($password, (string)$this->setting('admin_password_hash'))) { $this->failed('login'); usleep(self::$failDelay); throw new Problem('Passwort falsch.', 403); }
        $this->throttle->clear('login');
    }

    /** Hat diese Sitzung das Passwort in den letzten 24 Stunden schon einmal bestätigt? */
    public function confirmedRecently(array $session): bool { return (int)($session['confirmed_at'] ?? 0) > time() - self::CONFIRM_SECONDS; }
    public function markConfirmed(array $session): void { $this->db->query('UPDATE admin_sessions SET confirmed_at=? WHERE token_hash=?', [time(), $session['token_hash']]); }

    private function open(string $role = 'admin', bool $remember = false, string $agent = ''): array {
        $this->db->query('DELETE FROM admin_sessions WHERE expires_at<?', [time()]);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $csrf = bin2hex(random_bytes(24));
        $this->db->query('INSERT INTO admin_sessions(token_hash,csrf,created_at,expires_at,role,remember,net,label,last_used) VALUES(?,?,?,?,?,?,?,?,?)',
            [hash('sha256', $token), $csrf, time(), time() + ($remember ? self::REMEMBER_IDLE : $this->idleSeconds()), $role, $remember ? 1 : 0,
             $remember ? self::net($this->client ?? (string)($_SERVER['REMOTE_ADDR'] ?? '')) : '', $remember ? self::describeAgent($agent) : '', time()]);
        return ['token' => $token, 'csrf' => $csrf, 'role' => $role, 'remember' => $remember, 'ttl' => $remember ? self::REMEMBER_MAX : $this->maxSeconds()];
    }
    private function setPassword(string $password): void {
        $this->checkPassword($password);
        $this->store('admin_password_hash', password_hash($password, self::algorithm()));
        $this->db->query('DELETE FROM admin_sessions');   // andere Sitzungen abmelden
    }
    private function checkPassword(string $password): void {
        if (mb_strlen($password) < 10) throw new Problem('Das Admin-Passwort muss mindestens 10 Zeichen lang sein.', 422);
        if (mb_strlen($password) > 200) throw new Problem('Das Admin-Passwort ist zu lang.', 422);
        if (self::weak($password)) throw new Problem('Dieses Passwort ist zu einfach zu erraten. Bitte mehrere Wörter oder eine längere Zeichenfolge wählen, die nicht „passwort“, „admin“ oder eine Zahlenreihe enthält.', 422);
    }
    private static function algorithm(): string|int { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT; }
    /** Häufige und offensichtliche Passwörter ablehnen (Länge prüft der Aufrufer). */
    public static function weak(string $password): bool {
        $plain = mb_strtolower($password);
        if (count(array_unique(mb_str_split($plain))) < 5) return true;           // z. B. „aaaaaaaaaaaa“
        $squeezed = preg_replace('/[^a-z0-9]/', '', strtr($plain, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 's']));
        $rest = $squeezed;
        foreach (['passwort', 'password', 'admin', 'kienzle', 'sumup', 'qwertz', 'qwert', 'asdf', 'letmein', 'welcome', 'willkommen', 'praxis', 't2med',
                  '1234567', '0123456', 'abcdefg', '1111111', '0000000'] as $bad) $rest = str_replace($bad, '', $rest);
        // Besteht das Passwort fast nur aus solchen Mustern (plus ein paar Ziffern), ist es zu schwach.
        if ($rest !== $squeezed && strlen($rest) < 6) return true;
        return false;
    }
    private function checkLock(): void {
        $this->throttle->check('login');
        $until = (int)(json_decode((string)$this->setting('admin_global_lock'), true)['until'] ?? 0);
        if ($until > time()) throw new Problem('Zu viele Anmeldeversuche. Bitte ' . max(1, (int)ceil(($until - time()) / 60)) . ' Minuten warten.', 429);
    }
    private function failed(string $scope): void {
        // Je Absender: 5 Fehlversuche → 5 Minuten Sperre, bei Wiederholung doppelt so lang (höchstens 1 Stunde).
        $this->throttle->fail($scope === 'setup' ? 'login' : $scope);
        $this->audit->write($scope . '-fehlgeschlagen');
        // Gesamtbremse gegen verteilte Angriffe aus vielen Adressen: nur kurze Pause für alle.
        $state = json_decode((string)$this->setting('admin_global_failures'), true) ?: ['count' => 0, 'since' => time()];
        if ($state['since'] < time() - 3600) $state = ['count' => 0, 'since' => time()];
        if (++$state['count'] >= self::GLOBAL_LIMIT) {
            $this->store('admin_global_lock', json_encode(['until' => time() + 900]));
            $state = ['count' => 0, 'since' => time()];
        }
        $this->store('admin_global_failures', json_encode($state));
    }
    private function setting(string $key): ?string {
        $row = $this->db->one('SELECT value FROM settings WHERE key=?', [$key]);
        return $row ? $row['value'] : null;
    }
    private function store(string $key, string $value): void {
        $this->db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', [$key, $value, time()]);
    }
}
