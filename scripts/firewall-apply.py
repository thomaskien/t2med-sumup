#!/usr/bin/env python3
"""Firewall der Kienzle-SumUp-Weboberfläche (root-Dienst kienzle-sumup-firewall).

Die Anwendung legt nur eine kleine Anfragedatei ab. Dieses Programm prüft sie streng, erzeugt die Regeln
aus festen Vorlagen (nie aus Text der Anfrage), lädt sie in eine eigene nftables-Tabelle und setzt sie
automatisch zurück, wenn der Administrator nicht binnen zwei Minuten bestätigt, dass die Seite noch erreichbar ist.
Die Tabelle „kienzle_sumup“ berührt andere Regeln des Servers nicht.
"""
import ipaddress, json, os, re, stat, subprocess, sys, time

E = os.environ.get
NFT = E('KS_FW_NFT', '/usr/sbin/nft')
SS = E('KS_FW_SS', '/usr/bin/ss')
REQUEST = E('KS_FW_REQUEST', '/var/lib/kienzle-sumup/firewall-request')
CONFIRM = E('KS_FW_CONFIRM', '/var/lib/kienzle-sumup/firewall-confirm')
STATE = E('KS_FW_STATE', '/var/lib/kienzle-sumup-firewall')
RULES = E('KS_FW_RULES', '/etc/kienzle-sumup/firewall.nft')
TOML = E('KS_FW_TOML', '/etc/kienzle-sumup/kienzle-sumup.toml')
APP_UID = int(E('KS_FW_UID', '-1'))
WAIT = int(E('KS_FW_WAIT', '120'))
TABLE = 'kienzle_sumup'

ALLOWED_RANGES = [ipaddress.ip_network(n) for n in
                  ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '169.254.0.0/16', 'fc00::/7', 'fe80::/10')]


class Rejected(Exception):
    pass


# Bekannte Dienste der Kienzle-Projekte. Muss mit src/Firewall.php (SERVICES) übereinstimmen; tests/firewall.php prüft das.
# scope: nets = nur aus den freigegebenen Netzen, any = von überall (nötig, wenn der Zugriff aus dem Internet kommt).
SERVICES = {
    'middleware': {'tcp': ['8766'], 'scope': 'nets'},
    'ami': {'tcp': ['5038'], 'scope': 'nets'},
    'sip': {'tcp': ['5060'], 'udp': ['5060'], 'scope': 'nets'},
    'rtp': {'udp': ['10000-20000'], 'scope': 'nets'},
    'kfweb': {'tcp': ['8443', '8088'], 'scope': 'nets'},
    'wireguard': {'udp': ['51820'], 'scope': 'any'},
    'kfapp': {'tcp': ['80', '443'], 'scope': 'any'},
}
CUSTOM = re.compile(r'^(tcp|udp) (\d{1,5})(?:-(\d{1,5}))?$')


def clean_custom(values):
    if values is None:
        return []
    if not isinstance(values, list) or len(values) > 20:
        raise Rejected('Höchstens 20 eigene Ports.')
    out = []
    for v in values:
        m = CUSTOM.match(v) if isinstance(v, str) else None
        if not m:
            raise Rejected('Eigener Port ungültig (Beispiel: tcp 8080 oder udp 5000-5100).')
        lo, hi = int(m.group(2)), int(m.group(3) or m.group(2))
        if not (1 <= lo <= hi <= 65535):
            raise Rejected('Portnummer außerhalb 1–65535.')
        entry = f'{m.group(1)} {lo}' + (f'-{hi}' if hi != lo else '')
        if entry not in out:
            out.append(entry)
    return out


def clean_services(values):
    if values is None:
        return []
    if not isinstance(values, list) or not all(isinstance(v, str) and v in SERVICES for v in values):
        raise Rejected('Unbekannter Dienst.')
    return sorted(set(values))


def status(state, message, **extra):
    os.makedirs(STATE, mode=0o755, exist_ok=True)
    data = {'state': state, 'message': message, 'time': int(time.time())}
    data.update(extra)
    tmp = os.path.join(STATE, '.status.tmp')
    with open(tmp, 'w') as f:
        json.dump(data, f, ensure_ascii=False)
    os.chmod(tmp, 0o644)
    os.replace(tmp, os.path.join(STATE, 'status.json'))


def read_small(path, limit=4096):
    """Nur echte, kleine Dateien des App-Benutzers lesen (kein Symlink, keine fremden Dateien)."""
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_size > limit:
            raise Rejected('Anfrage ungültig.')
        if APP_UID >= 0 and info.st_uid != APP_UID:
            raise Rejected('Anfrage stammt nicht vom Dienstbenutzer.')
        return os.read(fd, limit + 1).decode('utf-8', 'strict')
    finally:
        os.close(fd)


def clean_networks(values):
    if not isinstance(values, list) or not values or len(values) > 20:
        raise Rejected('Es müssen 1 bis 20 Netze angegeben werden.')
    out = []
    for value in values:
        if not isinstance(value, str) or len(value) > 50:
            raise Rejected('Netz ungültig.')
        try:
            net = ipaddress.ip_network(value.strip(), strict=False)
        except ValueError:
            raise Rejected('Netz ungültig: ' + re.sub(r'[^0-9a-fA-F:./]', '', value)[:50])
        if not any(net.version == r.version and net.subnet_of(r) for r in ALLOWED_RANGES):
            raise Rejected('Nur private Netze (10.x, 172.16–31.x, 192.168.x, 100.64–127.x, fc00::/7) sind erlaubt: ' + str(net))
        if str(net) not in out:
            out.append(str(net))
    return out


def web_port():
    try:
        text = open(TOML, encoding='utf-8').read(65536)
    except OSError:
        raise Rejected('Konfiguration nicht lesbar.')
    section = ''
    for line in text.splitlines():
        line = line.strip()
        if line.startswith('['):
            section = line
        elif section == '[app]':
            m = re.match(r'^port\s*=\s*(\d{2,5})\s*(#.*)?$', line)
            if m and 1 <= int(m.group(1)) <= 65535:
                return int(m.group(1))
    raise Rejected('Webport nicht gefunden.')


def ssh_ports():
    """Ports, auf denen sshd lauscht (aus ss, nur wenn der Prozess sshd heißt); sonst 22."""
    try:
        out = subprocess.run([SS, '-H', '-ltnp'], capture_output=True, text=True, timeout=10).stdout
    except (OSError, subprocess.SubprocessError):
        return [22]
    ports = set()
    for line in out.splitlines():
        if 'sshd' in line:
            m = re.search(r'[:\]](\d{1,5})\s', line)
            if m and 1 <= int(m.group(1)) <= 65535:
                ports.add(int(m.group(1)))
    return sorted(ports) or [22]


def port_sets(web, ssh, ipp, services, custom):
    """Ports, die verwaltet werden: (nets_tcp, nets_udp, any_tcp, any_udp) als nft-Listen („80“, „1000-2000“)."""
    sets = {('nets', 'tcp'): [str(web)], ('nets', 'udp'): [], ('any', 'tcp'): [], ('any', 'udp'): []}
    sets[('nets', 'tcp')] += [str(p) for p in (ssh or [])] + (['631'] if ipp else [])
    for sid in services:
        for proto in ('tcp', 'udp'):
            sets[(SERVICES[sid]['scope'], proto)] += SERVICES[sid].get(proto, [])
    for entry in custom:
        proto, port = entry.split(' ')
        sets[('nets', proto)].append(port)
    def order(p):
        return int(p.split('-')[0])
    return {k: sorted(set(v), key=lambda p: (order(p), p)) for k, v in sets.items()}


def build_rules(nets, web, ssh=None, ipp=False, strict=False, services=(), custom=()):
    """Regeln aus festen Vorlagen. Alle Eingaben sind zuvor geprüft: Netze (ipaddress), Ports (int), Dienste (Katalog)."""
    v4 = [n for n in nets if ':' not in n]
    v6 = [n for n in nets if ':' in n]
    sets = port_sets(web, ssh, ipp, services, custom)
    fmt = lambda ports: ', '.join(ports)
    lines = [f'table inet {TABLE}', f'delete table inet {TABLE}', f'table inet {TABLE} {{', '  chain input {',
             f'    type filter hook input priority -10; policy {"drop" if strict else "accept"};',
             '    iifname "lo" accept', '    ct state established,related accept', '    ct state invalid drop',
             '    meta l4proto { icmp, ipv6-icmp } accept']
    if strict:
        lines += ['    udp sport 67 udp dport 68 accept', '    ip6 saddr fe80::/10 udp dport 546 accept']
    for proto in ('tcp', 'udp'):
        if sets[('any', proto)]:
            lines.append(f'    {proto} dport {{ {fmt(sets[("any", proto)])} }} accept')
    for family, group in (('ip', v4), ('ip6', v6)):
        if not group:
            continue
        for proto in ('tcp', 'udp'):
            if sets[('nets', proto)]:
                lines.append(f'    {family} saddr {{ {", ".join(group)} }} {proto} dport {{ {fmt(sets[("nets", proto)])} }} accept')
        if strict:
            lines.append(f'    {family} saddr {{ {", ".join(group)} }} udp dport 5353 accept')
    if not strict:
        for proto in ('tcp', 'udp'):
            if sets[('nets', proto)]:
                lines.append(f'    {proto} dport {{ {fmt(sets[("nets", proto)])} }} drop')
    lines += ['  }', '}', '']
    return '\n'.join(lines)


def nft(*args, stdin=None):
    return subprocess.run([NFT, *args], input=stdin, capture_output=True, text=True, timeout=30)


def current_table():
    r = nft('list', 'table', 'inet', TABLE)
    return r.stdout if r.returncode == 0 else None


def restore(previous):
    if previous:
        nft('-f', '-', stdin=f'delete table inet {TABLE}\n' + previous)
    else:
        nft('delete', 'table', 'inet', TABLE)


def confirmed(since):
    try:
        fd = os.open(CONFIRM, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        return False
    try:
        info = os.fstat(fd)
        return stat.S_ISREG(info.st_mode) and info.st_mtime >= since - 1 and (APP_UID < 0 or info.st_uid == APP_UID)
    finally:
        os.close(fd)


def handle():
    started = time.time()
    try:
        os.unlink(CONFIRM)
    except OSError:
        pass
    try:
        raw = read_small(REQUEST)
    except OSError:
        raw = None      # fehlt oder ist ein Symlink/keine reguläre Datei: ohne Wirkung
    except Rejected as e:
        raw = e
    finally:
        try:
            os.unlink(REQUEST)
        except OSError:
            pass
    if raw is None:
        return
    try:
        if isinstance(raw, Rejected):
            raise raw
        request = json.loads(raw)
        if not isinstance(request, dict) or request.get('action') not in ('apply', 'disable'):
            raise Rejected('Anfrage ungültig.')
        if request['action'] == 'disable':
            nft('delete', 'table', 'inet', TABLE)
            try: os.unlink(RULES)
            except OSError: pass
            status('off', 'Firewall ausgeschaltet.')
            return
        nets = clean_networks(request.get('cidrs'))
        web = web_port()
        ssh = ssh_ports() if request.get('ssh') is True else None
        ipp = request.get('ipp') is True
        strict = request.get('strict') is True
        services = clean_services(request.get('services'))
        custom = clean_custom(request.get('custom'))
        rules = build_rules(nets, web, ssh, ipp, strict, services, custom)
    except (Rejected, ValueError, UnicodeDecodeError) as e:
        status('failed', str(e) or 'Anfrage ungültig.')
        return

    check = nft('-c', '-f', '-', stdin=rules)
    if check.returncode != 0:
        status('failed', 'Regeln werden von nftables abgelehnt: ' + check.stderr.strip()[:200])
        return
    previous = current_table()
    applied = nft('-f', '-', stdin=rules)
    if applied.returncode != 0:
        restore(previous)
        status('failed', 'Regeln konnten nicht geladen werden: ' + applied.stderr.strip()[:200])
        return
    deadline = int(time.time()) + WAIT
    status('pending', f'Firewall geladen. Bitte binnen {WAIT} Sekunden bestätigen, sonst wird sie zurückgesetzt.',
           deadline=deadline, networks=nets, ports=sorted({web, *(ssh or []), *([631] if ipp else [])}), ssh=ssh is not None, ipp=ipp, strict=strict, services=services, custom=custom)
    while time.time() < deadline:
        if confirmed(started):
            os.makedirs(os.path.dirname(RULES), exist_ok=True)
            tmp = RULES + '.new'
            fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
            with os.fdopen(fd, 'w') as f:
                f.write(rules)
            os.replace(tmp, RULES)
            try: os.unlink(CONFIRM)
            except OSError: pass
            status('active', 'Firewall aktiv und bestätigt. Sie wird beim Start des Servers automatisch geladen.',
                   networks=nets, ports=sorted({web, *(ssh or []), *([631] if ipp else [])}), ssh=ssh is not None, ipp=ipp, strict=strict, services=services, custom=custom)
            return
        time.sleep(1)
    restore(previous)
    status('reverted', 'Nicht bestätigt: Die alte Firewall-Einstellung wurde wiederhergestellt.')


def boot():
    if os.path.isfile(RULES) and not os.path.islink(RULES):
        r = nft('-f', RULES)
        if r.returncode != 0:
            print('Firewall-Regeln konnten nicht geladen werden:', r.stderr.strip(), file=sys.stderr)
            return 1
    return 0


if __name__ == '__main__':
    if '--boot' in sys.argv:
        sys.exit(boot())
    handle()
