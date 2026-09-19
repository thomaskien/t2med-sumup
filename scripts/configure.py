#!/usr/bin/env python3
"""Interaktive Einrichtung; Geheimnisse ausschließlich in geschützte Dateien."""
import datetime
import getpass
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import sys
from urllib.parse import urlsplit

ROOT = Path('/etc/kienzle-sumup')
CONFIG = ROOT / 'kienzle-sumup.toml'
SOURCE = Path(__file__).resolve().parent.parent
T2MED_DEMO_API_KEY = '7QwA7931lJSQfMKuTH4MQXLn4YEiNhE5tggnYKlY4HE'


def ask(label, default='', secret=False):
    suffix = ' [vorhandenen Wert behalten]' if secret and default else (f' [{default}]' if default else '')
    value = (getpass.getpass if secret else input)(label + suffix + ': ').strip()
    return value or default


def write_private(path, text, mode=0o600):
    temp = path.with_name(path.name + '.new')
    descriptor = os.open(str(temp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, mode)
    with os.fdopen(descriptor, 'w', encoding='utf-8') as stream:
        stream.write(text)
    os.chmod(temp, mode)
    os.replace(temp, path)


def main():
    os.umask(0o077)
    ROOT.mkdir(parents=True, exist_ok=True)
    old = {}
    if CONFIG.exists():
        code = 'require $argv[1]; echo json_encode(KienzleSumup\\Config::load($argv[2])->values, JSON_THROW_ON_ERROR);'
        result = subprocess.run(['php', '-r', code, str(SOURCE / 'src/bootstrap.php'), str(CONFIG)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode:
            raise ValueError('Vorhandene Konfiguration ungültig. Bitte zuerst korrigieren; sie wird nicht überschrieben.')
        old = json.loads(result.stdout)
    get = lambda s,k,d='': old.get(s, {}).get(k,d)
    print('Kienzle-SumUp · Servereinrichtung (Enter übernimmt Vorgaben)')
    base = ask('HTTPS-Adresse ohne Port, z. B. https://praxisserver', get('app','base_url','https://'+socket.gethostname()).rsplit(':',1)[0] if get('app','base_url') else 'https://'+socket.gethostname())
    parsed = urlsplit(base)
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.path not in ('','/') or parsed.query or parsed.fragment:
        raise ValueError('Eine HTTPS-Adresse ohne Pfad angeben.')
    if ':' in parsed.hostname: raise ValueError('Bitte einen DNS-Namen oder eine IPv4-Adresse verwenden.')
    port = int(ask('HTTPS-Port (SUMU)', str(get('app','port',7868))))
    if not 1024 <= port <= 65535: raise ValueError('Port muss zwischen 1024 und 65535 liegen.')
    # Bei Updates kann der eigene Dienst bereits auf dem gewünschten Port laufen.
    own_active = subprocess.run(['systemctl','is-active','--quiet','kienzle-sumup-apache.service']).returncode == 0
    if not (own_active and port == get('app','port')):
        with socket.socket() as probe:
            try: probe.bind(('0.0.0.0',port))
            except OSError: raise ValueError(f'Port {port} ist bereits belegt.')
    base = f'https://{parsed.hostname}:{port}'
    mode = ask('Betrieb: mock oder live', get('sumup','mode','mock'))
    if mode not in ('mock','live'): raise ValueError('Bitte mock oder live wählen.')
    sumup = {'mode':mode}
    for key, label in [('api_key','SumUp API-Key'),('affiliate_key','SumUp Affiliate-Key'),('app_id','SumUp App-ID'),('merchant_code','SumUp Händlercode'),('reader_id','SumUp Reader-ID')]:
        default = get('sumup',key,'de.kienzle.sumup' if key=='app_id' else '')
        sumup[key] = ask(label,default,secret=key.endswith('key')) if mode=='live' else default
        if mode=='live' and not sumup[key]: raise ValueError(label+' fehlt.')
    fhir_mode = ask('t2med-Anbindung: mock oder live', get('fhir','mode',mode)) if mode=='live' else 'mock'
    if fhir_mode not in ('mock','live'): raise ValueError('Bitte mock oder live wählen.')
    fhir = {'mode':fhir_mode, 'base_url':get('fhir','base_url','https://t2med-server:16567/aps/fhir/api/r4'),
            'launch_urls':get('fhir','launch_urls',[]), 'api_key':get('fhir','api_key'),
            'entry_code':get('fhir','entry_code','ZAHLUNG'), 'ca_file':get('fhir','ca_file'),
            'pin_certificate':get('fhir','pin_certificate',False)}
    if fhir_mode=='live':
        fhir['base_url'] = ask('FHIR-Basisadresse, vom Server erreichbar',fhir['base_url']).rstrip('/')
        fhir['launch_urls'] = [s.strip().rstrip('/') for s in ask('Erlaubte fhirBasisUrl aus t2med (mehrere mit Komma trennen)', ','.join(fhir['launch_urls']) or fhir['base_url']).split(',') if s.strip()]
        label = 't2med FHIR API-Key' + (' (Enter verwendet den Demo-Key)' if not fhir['api_key'] else '')
        fhir['api_key'] = ask(label,fhir['api_key'],True) or T2MED_DEMO_API_KEY
        fhir['entry_code'] = ask('Aktenkürzel',fhir['entry_code'])
        ca = ask('t2med-Zertifikat/CA als PEM-Datei (leer: Systemvertrauen)',fhir['ca_file'])
        if ca:
            pem = Path(ca).read_text()
            if 'PRIVATE KEY' in pem: raise ValueError('Bitte nur öffentliches t2med-Zertifikat/CA angeben.')
            write_private(ROOT/'t2med-ca.pem',pem,0o640)
            fhir['ca_file'] = str(ROOT/'t2med-ca.pem')
        pin_cert = ask('t2med-Zertifikat ohne passenden Hostnamen: an öffentlichen Schlüssel binden (ja/nein)', 'ja' if fhir['pin_certificate'] else 'nein')
        if pin_cert not in ('ja', 'nein'):
            raise ValueError('Bitte nur ja oder nein eingeben.')
        fhir['pin_certificate'] = pin_cert == 'ja'
        if fhir['pin_certificate'] and not fhir['ca_file']:
            raise ValueError('Bei Schlüsselbindung muss ca_file mit öffentlichem t2med-Zertifikat gefüllt sein.')
    config = {'app': {'base_url':base,'port':port,'state_dir':'/var/lib/kienzle-sumup',
             'timezone':get('app','timezone','Europe/Berlin'),'session_hours':8,
             'max_amount_cents':int(ask('Maximalbetrag pro Zahlung in Cent',str(get('app','max_amount_cents',100000)))),
             'development':False,'launcher_key':get('app','launcher_key') or secrets.token_hex(32),
             'encryption_key':get('app','encryption_key') or secrets.token_hex(32)},'sumup':sumup,'fhir':fhir}
    mail = {key:get('mail',key,default) for key,default in {'enabled':False,'host':'','port':587,'encryption':'starttls','username':'','password':'','from_address':'','from_name':''}.items()}
    answer = ask('PDF-Belege direkt per E-Mail senden (ja/nein)', 'ja' if mail['enabled'] else 'nein')
    if answer not in ('ja','nein'): raise ValueError('Bitte ja oder nein eingeben.')
    mail['enabled'] = answer == 'ja'
    if mail['enabled']:
        mail['host'] = ask('SMTP-Server',mail['host'])
        mail['encryption'] = ask('SMTP-Verschlüsselung: starttls oder smtps',mail['encryption'])
        mail['port'] = int(ask('SMTP-Port',str(mail['port'] if get('mail','host') else (465 if mail['encryption']=='smtps' else 587))))
        mail['username'] = ask('SMTP-Benutzername (leer bei Relay ohne Anmeldung)',mail['username'])
        mail['password'] = ask('SMTP-Passwort',mail['password'],True) if mail['username'] else ''
        mail['from_address'] = ask('Absender-E-Mail-Adresse',mail['from_address'])
        mail['from_name'] = ask('Absendername / Praxisname',mail['from_name'])
    config['mail'] = mail
    printing = {key:get('printing',key,default) for key,default in {'enabled':False,'share':'//kienzlebox/TMm10','width_dots':420,'cut':True}.items()}
    answer = ask('Direktdruck auf Epson TM-m10 über Samba aktivieren (ja/nein)', 'ja' if printing['enabled'] else 'nein')
    if answer not in ('ja','nein'): raise ValueError('Bitte ja oder nein eingeben.')
    printing['enabled'] = answer == 'ja'
    if printing['enabled']:
        printing['share'] = ask('Samba-Druckerfreigabe (Gastzugriff ohne Passwort)',printing['share']).replace('\\','/')
        printing['width_dots'] = int(ask('Druckbreite in Punkten (TM-m10 mit 58 mm: 420)',str(printing['width_dots'])))
        answer = ask('Bon nach dem Drucken schneiden (ja/nein)', 'ja' if printing['cut'] else 'nein')
        if answer not in ('ja','nein'): raise ValueError('Bitte ja oder nein eingeben.')
        printing['cut'] = answer == 'ja'
    config['printing'] = printing
    print('Praxisdaten für lokale Leistungsbelege / GOÄ-Rechnungen (Name und Anschrift erforderlich).')
    practice = {}
    for key, label in [('name','Praxis / Rechnungsaussteller'),('street','Straße und Hausnummer'),('city','PLZ und Ort'),('contact','Kontakt auf dem Beleg (optional)')]:
        practice[key] = ask(label, get('practice',key,mail['from_name'] if key=='name' else ''))
    answer = ask('Originalen SumUp-Beleg nach Vielen Dank statt lokaler Zahlungsbestätigung anhängen (ja/nein)', 'ja' if get('practice','append_sumup_receipt',False) else 'nein')
    if answer not in ('ja','nein'): raise ValueError('Bitte ja oder nein eingeben.')
    practice['append_sumup_receipt'] = answer == 'ja'
    config['practice'] = practice
    text = '# Kienzle-SumUp · Zugangsdaten nicht weitergeben.\n'
    for section, values in config.items():
        text += '\n['+section+']\n'
        for key,value in values.items(): text += key+' = '+json.dumps(value,ensure_ascii=False)+'\n'
    candidate = ROOT/'validate.toml'
    write_private(candidate,text)
    code='require $argv[1]; KienzleSumup\\Config::load($argv[2]);'
    result = subprocess.run(['php','-r',code,str(SOURCE/'src/bootstrap.php'),str(candidate)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    candidate.unlink()
    if result.returncode: raise ValueError('Konfiguration ungültig. Bitte Adressen, Betriebsmodi und Eingaben prüfen.')
    cert = ROOT/'server.crt'; key = ROOT/'server.key'
    if cert.exists() != key.exists(): raise ValueError('Zertifikat/Schlüssel unvollständig. Bitte vorhandene Dateien prüfen.')
    if cert.exists():
        try: socket.inet_pton(socket.AF_INET,parsed.hostname); check_option='-checkip'
        except OSError: check_option='-checkhost'
        check = subprocess.run(['openssl','x509','-in',str(cert),'-noout',check_option,parsed.hostname],capture_output=True)
        # checkhost gibt bei einigen OpenSSL-Versionen trotz Nichtübereinstimmung 0 zurück.
        if check.returncode or b'does NOT match' in check.stdout:
            raise ValueError('Bestehendes Serverzertifikat passt nicht zur Adresse. Zertifikatwechsel bewusst durchführen und Clients neu einrichten.')
    else:
        now=datetime.datetime.now(datetime.timezone.utc)
        try: end=now.replace(year=now.year+50)
        except ValueError: end=now.replace(year=now.year+50,day=28)
        days=(end-now).days
        try: socket.inet_pton(socket.AF_INET,parsed.hostname); san='IP:'+parsed.hostname
        except OSError: san='DNS:'+parsed.hostname
        subprocess.run(['openssl','req','-x509','-newkey','rsa:3072','-sha256','-nodes','-days',str(days),
                        '-keyout',str(key),'-out',str(cert),'-subj','/CN='+parsed.hostname,
                        '-addext','subjectAltName='+san,'-addext','basicConstraints=critical,CA:TRUE',
                        '-addext','keyUsage=critical,digitalSignature,keyEncipherment,keyCertSign',
                        '-addext','extendedKeyUsage=serverAuth'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    write_private(CONFIG,text,0o640)
    setup=Path('/root/kienzle-sumup-clients');setup.mkdir(mode=0o700,exist_ok=True)
    der=subprocess.check_output(['openssl','x509','-in',str(cert),'-outform','DER'])
    client={'server_url':base,'launcher_key':config['app']['launcher_key'],'certificate_sha256':hashlib.sha256(der).hexdigest()}
    write_private(setup/'client.json',json.dumps(client,indent=2)+'\n')
    write_private(setup/'server.crt',cert.read_text(),0o644)
    print('Konfiguration und Client-Einrichtungsdaten gespeichert. Keine Schlüssel werden ausgegeben.')


if __name__=='__main__':
    try: main()
    except (ValueError,OSError,subprocess.SubprocessError) as exc:
        print('Einrichtung abgebrochen: '+str(exc),file=sys.stderr);sys.exit(1)
