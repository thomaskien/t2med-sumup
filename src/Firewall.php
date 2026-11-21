<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Firewall aus dem Webinterface. Die Anwendung wählt nur Netze und Optionen; die Regeln erzeugt und lädt der
 * root-Dienst kienzle-sumup-firewall (scripts/firewall-apply.py) aus festen Vorlagen. Schutz vor Aussperren:
 * das anfragende Gerät muss in einem freigegebenen Netz liegen, und ohne Bestätigung binnen zwei Minuten
 * stellt der Dienst die vorherigen Regeln wieder her.
 */
final class Firewall {
    public static string $state = '/var/lib/kienzle-sumup-firewall';
    public static string $service = '/usr/local/sbin/kienzle-sumup-firewall';
    private const KEY = 'firewall_config';

    /**
     * Bekannte Dienste der Kienzle-Projekte (Ports laut deren Dokumentation). Muss mit SERVICES in
     * scripts/firewall-apply.py übereinstimmen. scope: nets = nur aus den freigegebenen Netzen,
     * any = von überall (Zugriff kommt aus dem Internet).
     */
    public const SERVICES = [
        'middleware' => ['label' => 'Kienzlefon-Middleware (kienzlefon-t2med): Weboberfläche und Schnittstelle für Arbeitsplätze', 'tcp' => ['8766'], 'scope' => 'nets', 'note' => 'Standardport 8766; auch der T2med-Aufruf „Telefonverzeichnis aktualisieren“ nutzt ihn.'],
        'ami' => ['label' => 'Kienzlefon-Telefonanlage (Asterisk): Manager-Schnittstelle AMI für die Middleware', 'tcp' => ['5038'], 'scope' => 'nets', 'note' => 'Nur nötig, wenn die Middleware auf einem anderen Rechner läuft als die Telefonanlage.'],
        'sip' => ['label' => 'Kienzlefon-Telefonanlage: SIP für Telefone im Praxisnetz', 'tcp' => ['5060'], 'udp' => ['5060'], 'scope' => 'nets', 'note' => 'Interner SIP-Registrar, Standardport 5060.'],
        'rtp' => ['label' => 'Kienzlefon-Telefonanlage: Sprachdaten (RTP)', 'udp' => ['10000-20000'], 'scope' => 'nets', 'note' => 'Asterisk-Standardbereich; im Projekt nicht festgelegt. Bei geänderter rtp.conf unter „Eigene Ports“ anpassen.'],
        'kfweb' => ['label' => 'Kienzlefon-Weboberfläche', 'tcp' => ['8443', '8088'], 'scope' => 'nets', 'note' => '8443 mit TLS, 8088 ohne (Vorgabe des Installers).'],
        'wireguard' => ['label' => 'WireGuard-VPN (z. B. KI-Anbindung von Kienzlefon)', 'udp' => ['51820'], 'scope' => 'any', 'note' => 'Nur nötig, wenn dieser Server das VPN annimmt. Gegenstellen kommen aus dem Internet, daher von überall.'],
        'kfapp' => ['label' => 'Kienzlefon-App, Quellserver (nginx, Telepraxis-Empfang)', 'tcp' => ['80', '443'], 'scope' => 'any', 'note' => 'Die App-Anbindung kommt aus dem Internet (Port 80 und 443 müssen erreichbar sein).'],
    ];

    public function __construct(private Database $db, private Network $network) {}

    public function overview(string $client): array {
        $client = Network::clean($client);
        $net = $this->network->detect();
        $config = $this->config();
        $covering = null;
        foreach ($net['interfaces'] as $i) foreach ($i['addresses'] as $a) if (Network::contains($a['network'], $client)) $covering = $a['network'];
        $listening = array_column($net['listening'], 'port');
        if ($config === null) {
            // Vorschlag: das Netz, aus dem gerade zugegriffen wird; sonst alle erkannten privaten Netze.
            $suggest = [];
            if ($covering && Network::isPrivate($covering)) $suggest = [$covering];
            else foreach ($net['interfaces'] as $i) foreach ($i['addresses'] as $a) if ($a['private'] && $a['family'] === 4 && !in_array($a['network'], $suggest, true)) $suggest[] = $a['network'];
            // Dienste, die hier schon lauschen, werden vorgewählt.
            $found = [];
            foreach (self::SERVICES as $id => $svc) foreach (['tcp', 'udp'] as $proto) foreach ($svc[$proto] ?? [] as $port) if ($proto === 'tcp' && ctype_digit($port) && in_array((int)$port, $listening, true) && !in_array($port, ['80', '443'], true)) $found[$id] = $id;
            $config = ['cidrs' => $suggest, 'ssh' => true, 'ipp' => true, 'strict' => false, 'services' => array_values($found), 'custom' => []];
        }
        return ['network' => $net, 'client' => ['ip' => $client, 'network' => $covering, 'loopback' => Network::isLoopback($client)],
            'config' => $config + ['services' => [], 'custom' => []], 'catalog' => self::SERVICES, 'saved' => $this->config() !== null, 'status' => $this->status(), 'service' => is_file(self::$service)];
    }

    /** Eingabe prüfen und bereinigen; wirft Problem, wenn das Gerät des Administrators ausgesperrt würde. */
    public function validate(array $input, string $client): array {
        $client = Network::clean($client);
        $list = [];
        foreach ((array)($input['networks'] ?? []) as $n) if (is_string($n)) $list[] = $n;
        foreach (preg_split('/[\s,;]+/', (string)($input['extra'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $n) $list[] = $n;
        $cidrs = [];
        foreach ($list as $raw) {
            $net = Network::normalize((string)$raw);
            if ($net === null) throw new Problem('Das Netz „' . mb_substr(preg_replace('/[^0-9a-fA-F:.\/]/', '', (string)$raw), 0, 50) . '“ ist ungültig. Beispiel: 192.168.1.0/24', 422);
            if (!Network::isPrivate($net)) throw new Problem('Nur private Netze sind erlaubt (10.x.x.x, 172.16–31.x.x, 192.168.x.x, 100.64–127.x.x). „' . $net . '“ gehört nicht dazu.', 422);
            if (!in_array($net, $cidrs, true)) $cidrs[] = $net;
        }
        if (!$cidrs) throw new Problem('Bitte mindestens ein Netz freigeben, sonst käme niemand mehr auf die Seite.', 422);
        if (count($cidrs) > 20) throw new Problem('Höchstens 20 Netze.', 422);
        if (!Network::isLoopback($client)) {
            $inside = false;
            foreach ($cidrs as $c) if (Network::contains($c, $client)) $inside = true;
            if (!$inside) throw new Problem('Dein jetziges Gerät (' . $client . ') läge außerhalb der freigegebenen Netze und wäre ausgesperrt. Bitte sein Netz mit freigeben.', 422);
        }
        $services = [];
        foreach ((array)($input['services'] ?? []) as $id) {
            if (!is_string($id) || !isset(self::SERVICES[$id])) throw new Problem('Unbekannter Dienst.', 422);
            if (!in_array($id, $services, true)) $services[] = $id;
        }
        $custom = [];
        foreach (preg_split('/[\r\n,;]+/', is_array($input['custom'] ?? null) ? implode("\n", array_filter($input['custom'], 'is_string')) : (string)($input['custom'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            if (!preg_match('/^\s*(tcp|udp)[\s:]+(\d{1,5})(?:\s*-\s*(\d{1,5}))?\s*$/i', $line, $m)) throw new Problem('Eigener Port ungültig: „' . mb_substr(preg_replace('/[^0-9a-zA-Z :-]/', '', $line), 0, 30) . '“. Beispiel: tcp 8080 oder udp 5000-5100', 422);
            $lo = (int)$m[2]; $hi = (int)($m[3] ?? $m[2]);
            if ($lo < 1 || $hi > 65535 || $lo > $hi) throw new Problem('Portnummern müssen zwischen 1 und 65535 liegen.', 422);
            $entry = strtolower($m[1]) . ' ' . $lo . ($hi !== $lo ? '-' . $hi : '');
            if (!in_array($entry, $custom, true)) $custom[] = $entry;
        }
        if (count($custom) > 20) throw new Problem('Höchstens 20 eigene Ports.', 422);
        return ['cidrs' => $cidrs, 'ssh' => ($input['ssh'] ?? false) === true, 'ipp' => ($input['ipp'] ?? false) === true, 'strict' => ($input['strict'] ?? false) === true,
            'services' => $services, 'custom' => $custom];
    }

    public function apply(array $input, string $client): array {
        $config = $this->validate($input, $client);
        if (!is_file(self::$service)) throw new Problem('Der Firewall-Dienst fehlt. Bitte einmal den aktuellen Server-Installer ausführen (oder in den Einstellungen aktualisieren).', 503);
        if (in_array($this->status()['state'], ['pending', 'requested'], true)) throw new Problem('Eine Firewall-Änderung wartet noch auf Bestätigung.', 409);
        $this->save($config);
        @unlink($this->db->directory . '/firewall-confirm');
        $this->write(['action' => 'apply'] + $config);
        return ['message' => 'Firewall wird geladen. Bitte gleich bestätigen, dass diese Seite noch funktioniert.'];
    }

    public function disable(): array {
        if (!is_file(self::$service)) throw new Problem('Der Firewall-Dienst fehlt.', 503);
        $this->write(['action' => 'disable']);
        return ['message' => 'Firewall wird ausgeschaltet.'];
    }

    /** Bestätigung, dass die Seite nach dem Laden erreichbar war: erst danach bleibt die Firewall dauerhaft. */
    public function confirm(): array {
        $status = $this->status();
        if ($status['state'] !== 'pending') throw new Problem('Es gibt nichts zu bestätigen.', 409);
        $path = $this->db->directory . '/firewall-confirm';
        if (file_put_contents($path, (string)time()) === false) throw new Problem('Bestätigung konnte nicht gespeichert werden.', 500);
        return ['message' => 'Bestätigt. Die Firewall wird dauerhaft übernommen.'];
    }

    public function status(): array {
        $s = json_decode((string)@file_get_contents(self::$state . '/status.json'), true);
        $s = is_array($s) ? $s : [];
        $requested = is_file($this->db->directory . '/firewall-request');
        $state = (string)($s['state'] ?? 'off');
        if ($requested && $state !== 'pending') $state = 'requested';
        if ($state === 'pending' && ($s['deadline'] ?? 0) < time() - 5) $state = 'reverted';
        return ['state' => $state, 'message' => (string)($s['message'] ?? ''), 'deadline' => $s['deadline'] ?? null, 'networks' => $s['networks'] ?? [],
            'ports' => $s['ports'] ?? [], 'ssh' => $s['ssh'] ?? false, 'ipp' => $s['ipp'] ?? false, 'strict' => $s['strict'] ?? false, 'services' => $s['services'] ?? [], 'custom' => $s['custom'] ?? [], 'now' => time()];
    }

    private function config(): ?array {
        $row = $this->db->one('SELECT value FROM settings WHERE key=?', [self::KEY]);
        $c = $row ? json_decode($row['value'], true) : null;
        return is_array($c) && isset($c['cidrs']) ? $c : null;
    }
    private function save(array $config): void {
        $this->db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', [self::KEY, json_encode($config), time()]);
    }
    private function write(array $request): void {
        $path = $this->db->directory . '/firewall-request';
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode($request, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $path)) { @unlink($tmp); throw new Problem('Anfrage konnte nicht gespeichert werden.', 500); }
    }
}
