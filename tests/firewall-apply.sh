#!/usr/bin/env bash
# Root-Dienst der Firewall (scripts/firewall-apply.py) mit einem nachgebauten nft: Prüfung, Bestätigung, Rücksetzen.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
t=$(mktemp -d); trap 'rm -rf "$t"' EXIT
mkdir -p "$t/state" "$t/etc" "$t/data"
cat > "$t/nft" <<'FAKE'
#!/usr/bin/env bash
# Merkt sich die geladene Tabelle in $FAKE_TABLE; „-c“ prüft nur.
echo "$*" >> "$FAKE_LOG"
case "$1" in
  -c) cat > /dev/null; exit 0;;
  -f) if [ "$2" = - ]; then input=$(cat); else input=$(cat "$2"); fi; if [ "$FAKE_FAIL" = 1 ]; then echo 'Error: kaputt' >&2; exit 1; fi
      if printf '%s' "$input" | grep -q '^table inet kienzle_sumup {'; then printf '%s\n' "$input" | sed -n '/^table inet kienzle_sumup {/,$p' > "$FAKE_TABLE"; fi; exit 0;;
  list) [ -s "$FAKE_TABLE" ] && cat "$FAKE_TABLE" || exit 1; exit 0;;
  delete) : > "$FAKE_TABLE"; exit 0;;
esac
FAKE
chmod +x "$t/nft"
printf '[app]\nbase_url = "https://x"\nport = 7868\n' > "$t/etc/kienzle-sumup.toml"
printf '#!/bin/sh\necho "LISTEN 0 128 0.0.0.0:2222 0.0.0.0:* users:((\\"sshd\\",pid=1,fd=3))"\n' > "$t/ss"; chmod +x "$t/ss"
export KS_FW_NFT="$t/nft" KS_FW_SS="$t/ss" KS_FW_REQUEST="$t/data/firewall-request" KS_FW_CONFIRM="$t/data/firewall-confirm" \
  KS_FW_STATE="$t/state" KS_FW_RULES="$t/etc/firewall.nft" KS_FW_TOML="$t/etc/kienzle-sumup.toml" KS_FW_UID="$(id -u)" KS_FW_WAIT=3 \
  FAKE_TABLE="$t/table" FAKE_LOG="$t/log" FAKE_FAIL=0
: > "$t/table"; : > "$t/log"
run() { python3 -I "$root/scripts/firewall-apply.py"; }
state() { python3 -c "import json,sys;print(json.load(open('$t/state/status.json'))['$1'])"; }
fail() { echo "FEHLER: $*"; exit 1; }

# 1. Bestätigt: Regeln laden, dauerhaft speichern
echo '{"action":"apply","cidrs":["192.168.1.7/24","10.8.0.0/24"],"ssh":true,"ipp":true,"strict":false}' > "$t/data/firewall-request"
( sleep 1; touch "$t/data/firewall-confirm" ) &
run; wait
[ "$(state state)" = active ] || fail "nicht aktiv: $(cat "$t/state/status.json")"
grep -q 'ip saddr { 192.168.1.0/24, 10.8.0.0/24 } tcp dport { 631, 2222, 7868 } accept' "$t/etc/firewall.nft" || fail "Regel fehlt: $(cat "$t/etc/firewall.nft")"
grep -q 'tcp dport { 631, 2222, 7868 } drop' "$t/etc/firewall.nft" || fail 'drop-Regel fehlt'
[ ! -e "$t/data/firewall-request" ] && [ ! -e "$t/data/firewall-confirm" ] || fail 'Anfrage/Bestätigung nicht aufgeräumt'
[ "$(stat -c %a "$t/etc/firewall.nft")" = 600 ] || fail 'Regeldatei nicht privat'
python3 -I "$root/scripts/firewall-apply.py" --boot && grep -q -- "-f $t/etc/firewall.nft" "$t/log" || fail 'Start lädt die Regeln nicht'
echo 1 ok

# 1b. Dienste und eigene Ports
echo '{"action":"apply","cidrs":["192.168.1.0/24"],"ssh":false,"ipp":false,"strict":true,"services":["middleware","wireguard","kfapp","rtp"],"custom":["tcp 8080","udp 5000-5100"]}' > "$t/data/firewall-request"
( sleep 1; touch "$t/data/firewall-confirm" ) &
run; wait
[ "$(state state)" = active ] || fail "Dienste nicht aktiv: $(cat "$t/state/status.json")"
grep -q '^    tcp dport { 80, 443 } accept' "$t/etc/firewall.nft" || fail 'kfapp (von überall) fehlt'
grep -q '^    udp dport { 51820 } accept' "$t/etc/firewall.nft" || fail 'wireguard (von überall) fehlt'
grep -q 'ip saddr { 192.168.1.0/24 } tcp dport { 7868, 8080, 8766 } accept' "$t/etc/firewall.nft" || fail 'middleware/eigener Port fehlt'
grep -q 'ip saddr { 192.168.1.0/24 } udp dport { 5000-5100, 10000-20000 } accept' "$t/etc/firewall.nft" || fail 'rtp/eigener UDP-Bereich fehlt'
grep -q 'policy drop' "$t/etc/firewall.nft" || fail 'strict ohne policy drop'
echo 1b ok

# 2. Nicht bestätigt: alte Tabelle wird wiederhergestellt
cp "$t/table" "$t/table.before"
echo '{"action":"apply","cidrs":["172.16.0.0/16"],"ssh":false,"ipp":false,"strict":true}' > "$t/data/firewall-request"
run
[ "$(state state)" = reverted ] || fail "nicht zurückgesetzt: $(cat "$t/state/status.json")"
diff -q "$t/table" "$t/table.before" >/dev/null || fail 'alte Regeln nicht wiederhergestellt'
grep -q '172.16.0.0/16' "$t/etc/firewall.nft" && fail 'unbestätigte Regeln dauerhaft gespeichert'
echo 2 ok

# 3. Ablehnung: öffentliche Netze, Müll, Einschleusung, zu große Anfrage, Symlink
for bad in '{"action":"apply","cidrs":["0.0.0.0/0"]}' '{"action":"apply","cidrs":["8.8.8.0/24"]}' '{"action":"apply","cidrs":["192.168.1.0/24; flush ruleset"]}' \
           '{"action":"apply","cidrs":["192.168.1.0/24"],"services":["rootkit"]}' '{"action":"apply","cidrs":["192.168.1.0/24"],"custom":["tcp 0"]}' \
           '{"action":"apply","cidrs":["192.168.1.0/24"],"custom":["tcp 80; flush ruleset"]}' '{"action":"apply","cidrs":["192.168.1.0/24"],"custom":["udp 900-100"]}' \
           '{"action":"apply","cidrs":["192.168.1.0/24"],"services":"ami"}' '{"action":"apply","cidrs":[]}' '{"action":"drop"}' 'kein json' '{"action":"apply","cidrs":"192.168.0.0/16"}' '{"action":"apply","cidrs":[1]}'; do
  : > "$t/log"; echo "$bad" > "$t/data/firewall-request"; run
  [ "$(state state)" = failed ] || fail "nicht abgelehnt: $bad"
  grep -q -- '^-f' "$t/log" && fail "trotz Ablehnung geladen: $bad"
done
python3 -c "print('x'*5000)" > "$t/data/firewall-request"; run; [ "$(state state)" = failed ] || fail 'große Anfrage'
echo '{"action":"apply","cidrs":["192.168.0.0/16"]}' > "$t/secret"; ln -s "$t/secret" "$t/data/firewall-request"; run
[ ! -e "$t/data/firewall-request" ] || fail 'Symlink-Anfrage nicht entfernt'
grep -q 'saddr' "$t/etc/firewall.nft" || fail 'Regeldatei verändert'
cp "$t/table" "$t/table.symlink"; diff -q "$t/table" "$t/table.before" >/dev/null || fail 'Symlink-Anfrage wurde ausgeführt'
echo 3 ok

# 4. nft lehnt ab: alter Zustand bleibt
FAKE_FAIL=1; echo '{"action":"apply","cidrs":["192.168.1.0/24"]}' > "$t/data/firewall-request"; run; FAKE_FAIL=0
[ "$(state state)" = failed ] || fail 'nft-Fehler nicht gemeldet'
echo 4 ok

# 5. Ausschalten
echo '{"action":"disable"}' > "$t/data/firewall-request"; run
[ "$(state state)" = off ] && [ ! -e "$t/etc/firewall.nft" ] && [ ! -s "$t/table" ] || fail 'Ausschalten'
echo 5 ok

# 6. Echtes nft prüft die erzeugten Regeln auf Syntax (falls vorhanden)
if command -v nft >/dev/null; then
  for opts in "[],False,False" "[22],True,False" "[22],True,True"; do
    python3 -I - "$root" <<PY || { echo 'nft -c nicht möglich (ohne Rechte), übersprungen'; break; }
import importlib.util, subprocess, sys
spec = importlib.util.spec_from_file_location('fw', sys.argv[1] + '/scripts/firewall-apply.py'); fw = importlib.util.module_from_spec(spec); spec.loader.exec_module(fw)
ssh, ipp, strict = $opts
r = fw.build_rules(['192.168.1.0/24', 'fd12::/16'], 7868, ssh or None, ipp, strict)
p = subprocess.run(['nft', '-c', '-f', '-'], input=r, capture_output=True, text=True)
if 'Operation not permitted' in p.stderr: sys.exit(1)
assert p.returncode == 0, p.stderr + r
PY
  done
fi
echo "Firewall-Dienst ok"
