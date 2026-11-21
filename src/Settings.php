<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Einstellungen aus der Weboberfläche. Sie liegen verschlüsselt in der Datenbank und
 * überlagern die vom Installer geschriebene TOML-Datei. Die TOML-Datei enthält danach
 * nur noch Grundwerte (Adresse, Port, Schlüssel) und Vorgaben.
 */
final class Settings {
    public const T2MED_DEMO_API_KEY = '7QwA7931lJSQfMKuTH4MQXLn4YEiNhE5tggnYKlY4HE';
    private const ROW = 'config_overrides';

    /** Felder je Abschnitt: Typ, Beschriftung, Auswahl, Hilfetext. */
    public const SCHEMA = [
        'sumup' => ['label' => 'Betrieb und SumUp', 'fields' => [
            'mode' => ['type' => 'choice', 'label' => 'Betrieb', 'choices' => ['mock' => 'Testmodus (keine echten Zahlungen)', 'live' => 'Live (echte Kartenzahlungen)']],
            'api_key' => ['type' => 'secret', 'label' => 'SumUp API-Key', 'help' => 'SumUp → Einstellungen → Für Entwickler → API Keys (geheimer Schlüssel)'],
            'affiliate_key' => ['type' => 'secret', 'label' => 'SumUp Affiliate-Key'],
            'app_id' => ['type' => 'string', 'label' => 'SumUp App-ID', 'default' => 'de.kienzle.sumup'],
            'merchant_code' => ['type' => 'string', 'label' => 'SumUp Händlercode', 'help' => 'z. B. MK01A8C2'],
            'reader_id' => ['type' => 'string', 'label' => 'SumUp Reader-ID', 'help' => 'API-ID des gekoppelten Solo, siehe README'],
        ]],
        'app' => ['label' => 'Zahlungen', 'fields' => [
            'max_amount_cents' => ['type' => 'money', 'label' => 'Höchstbetrag pro Zahlung (Euro)'],
        ]],
        'fhir' => ['label' => 'T2med-Anbindung', 'fields' => [
            'mode' => ['type' => 'choice', 'label' => 'Anbindung', 'choices' => ['mock' => 'Testmodus (Testpatient, keine Akteneinträge)', 'live' => 'Live (echte Patientenakte)']],
            'base_url' => ['type' => 'string', 'label' => 'FHIR-Basisadresse (vom Server erreichbar)', 'default' => 'https://t2med-server:16567/aps/fhir/api/r4'],
            'launch_urls' => ['type' => 'list', 'label' => 'Erlaubte fhirBasisUrl aus T2med (eine pro Zeile)', 'help' => 'Leer: wie FHIR-Basisadresse'],
            'api_key' => ['type' => 'secret', 'label' => 'T2med FHIR API-Key', 'help' => 'Leer bei der ersten Einrichtung: T2med-Demo-Key'],
            'entry_code' => ['type' => 'string', 'label' => 'Aktenkürzel', 'default' => 'ZAHLUNG'],
            'ca_pem' => ['type' => 'pem', 'label' => 'T2med-Serverzertifikat (PEM, optional)', 'help' => 'Nur nötig, wenn T2med ein eigenes Zertifikat verwendet'],
            'pin_certificate' => ['type' => 'bool', 'label' => 'Zertifikat ohne passenden Hostnamen an den öffentlichen Schlüssel binden'],
        ]],
        'practice' => ['label' => 'Praxisdaten (Rechnungen und Belege)', 'fields' => [
            'name' => ['type' => 'string', 'label' => 'Praxis / Rechnungsaussteller'],
            'street' => ['type' => 'string', 'label' => 'Straße und Hausnummer'],
            'city' => ['type' => 'string', 'label' => 'PLZ und Ort'],
            'contact' => ['type' => 'string', 'label' => 'Kontakt auf dem Beleg (optional)'],
            'append_sumup_receipt' => ['type' => 'bool', 'label' => 'Originalen SumUp-Beleg statt lokaler Zahlungsbestätigung anhängen'],
        ]],
        'tax' => ['label' => 'Umsatzsteuer', 'fields' => [
            'small_business' => ['type' => 'bool', 'label' => 'Kleinunternehmerregelung (§ 19 UStG): alle Rechnungen ohne MwSt.', 'default' => false],
            'rate' => ['type' => 'percent', 'label' => 'MwSt.-Satz in Prozent', 'default' => 1900],
            'manual_vat' => ['type' => 'bool', 'label' => '„Sonstiger Betrag“ mit MwSt. berechnen', 'default' => false],
            'tax_id' => ['type' => 'string', 'label' => 'Steuernummer oder USt-IdNr. (steht auf der Rechnung)', 'default' => '', 'optional' => true],
            'exempt_note' => ['type' => 'text', 'label' => 'Hinweis bei Positionen ohne MwSt. (optional)', 'default' => ''],
            'small_business_note' => ['type' => 'text', 'label' => 'Hinweis bei Kleinunternehmerregelung', 'default' => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.'],
        ]],
        'mail' => ['label' => 'E-Mail-Versand', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Belege per E-Mail senden'],
            'host' => ['type' => 'string', 'label' => 'SMTP-Server'],
            'encryption' => ['type' => 'choice', 'label' => 'Verschlüsselung', 'choices' => ['starttls' => 'STARTTLS (meist Port 587)', 'smtps' => 'SMTPS (meist Port 465)']],
            'port' => ['type' => 'int', 'label' => 'SMTP-Port', 'default' => 587],
            'username' => ['type' => 'string', 'label' => 'Benutzername (leer bei Relay ohne Anmeldung)'],
            'password' => ['type' => 'secret', 'label' => 'Passwort'],
            'from_address' => ['type' => 'string', 'label' => 'Absenderadresse'],
            'from_name' => ['type' => 'string', 'label' => 'Absendername'],
        ]],
        'printing' => ['label' => 'Bondrucker', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Bondrucker verwenden („Beleg drucken“)'],
            'model' => ['type' => 'choice', 'label' => 'Modell', 'choices' => ['tm-m10' => 'Epson TM-m10 (58 mm)', 'tm-m30iii' => 'Epson TM-m30III (80 mm)']],
            'transport' => ['type' => 'choice', 'label' => 'Verbindung', 'choices' => ['cups' => 'CUPS-RAW-Warteschlange (z. B. per LAN)', 'samba' => 'Samba-Freigabe auf dem Druckserver']],
            'queue' => ['type' => 'string', 'label' => 'CUPS-Warteschlange', 'default' => 'TMm10'],
            'cups_server' => ['type' => 'string', 'label' => 'CUPS-Server (leer: dieser Server)'],
            'share' => ['type' => 'string', 'label' => 'Samba-Freigabe', 'default' => '//kienzlebox/TMm10'],
            'width_dots' => ['type' => 'int', 'label' => 'Druckbreite in Punkten (leer: passend zum Modell)', 'optional' => true],
            'cut' => ['type' => 'bool', 'label' => 'Bon schneiden', 'default' => true],
            'cash_receipt_auto' => ['type' => 'bool', 'label' => 'Quittung automatisch drucken bei Barzahlung', 'default' => false],
        ]],
        'office_printing' => ['label' => 'Praxisdrucker (A4)', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Praxisdrucker für Rechnungen verwenden'],
            'queue' => ['type' => 'string', 'label' => 'CUPS-Warteschlange', 'default' => 'Praxisdrucker', 'help' => 'lpstat -p zeigt alle'],
            'cups_server' => ['type' => 'string', 'label' => 'CUPS-Server (leer: dieser Server)'],
            'media' => ['type' => 'choice', 'label' => 'Papier', 'choices' => ['A4' => 'A4', 'Letter' => 'Letter']],
            'duplex' => ['type' => 'bool', 'label' => 'Beidseitig drucken'],
            'invoice_auto' => ['type' => 'bool', 'label' => 'T2med-Rechnungen immer automatisch drucken (Karte, Bar und „Später“)', 'default' => true],
        ]],
        'terminzettel' => ['label' => 'Terminzettel', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Terminzettel aus T2med auf dem Bondrucker drucken', 'default' => true],
            'header_text' => ['type' => 'text', 'label' => 'Kopf: erste Zeile Praxisname, darunter Adressbereich (leer = keiner)', 'default' => ''],
            'header_first_size' => ['type' => 'choice', 'label' => 'Erste Zeile (Praxisname): Schriftgröße', 'choices' => ['same' => 'Wie der Adressbereich'] + ['1x1' => 'Normal (1×1)', '1x2' => 'Hoch (1×2)', '2x1' => 'Breit (2×1)', '2x2' => 'Groß (2×2)', '2x3' => 'Groß und hoch (2×3)', '3x3' => 'Sehr groß (3×3)', '4x4' => 'Riesig (4×4)'], 'default' => 'same'],
            'header_first_bold' => ['type' => 'choice', 'label' => 'Erste Zeile (Praxisname): fett', 'choices' => ['same' => 'Wie der Adressbereich', 'yes' => 'Fett', 'no' => 'Nicht fett'], 'default' => 'same'],
            'header_size' => ['type' => 'choice', 'label' => 'Adressbereich (übrige Zeilen): Schriftgröße', 'choices' => ['1x1' => 'Normal (1×1)', '1x2' => 'Hoch (1×2)', '2x1' => 'Breit (2×1)', '2x2' => 'Groß (2×2)', '2x3' => 'Groß und hoch (2×3)', '3x3' => 'Sehr groß (3×3)', '4x4' => 'Riesig (4×4)'], 'default' => '2x2'],
            'header_bold' => ['type' => 'bool', 'label' => 'Adressbereich (übrige Zeilen): fett', 'default' => true],
            'heading' => ['type' => 'string', 'label' => 'Überschrift', 'default' => 'IHRE TERMINE', 'optional' => true],
            'heading_size' => ['type' => 'choice', 'label' => 'Schriftgröße Überschrift', 'choices' => ['1x1' => 'Normal (1×1)', '1x2' => 'Hoch (1×2)', '2x1' => 'Breit (2×1)', '2x2' => 'Groß (2×2)', '2x3' => 'Groß und hoch (2×3)', '3x3' => 'Sehr groß (3×3)', '4x4' => 'Riesig (4×4)'], 'default' => '2x2'],
            'show_patient' => ['type' => 'bool', 'label' => 'Patientennamen drucken', 'default' => true],
            'appointment_size' => ['type' => 'choice', 'label' => 'Schriftgröße Termine', 'choices' => ['1x1' => 'Normal (1×1)', '1x2' => 'Hoch (1×2)', '2x1' => 'Breit (2×1)', '2x2' => 'Groß (2×2)', '2x3' => 'Groß und hoch (2×3)', '3x3' => 'Sehr groß (3×3)', '4x4' => 'Riesig (4×4)'], 'default' => '2x2'],
            'footer_text' => ['type' => 'text', 'label' => 'Endtext (leer = keiner)', 'default' => 'Können Sie einen Termin nicht wahrnehmen, sagen Sie bitte unbedingt Bescheid.'],
            'footer_size' => ['type' => 'choice', 'label' => 'Schriftgröße Endtext', 'choices' => ['1x1' => 'Normal (1×1)', '1x2' => 'Hoch (1×2)', '2x1' => 'Breit (2×1)', '2x2' => 'Groß (2×2)', '2x3' => 'Groß und hoch (2×3)', '3x3' => 'Sehr groß (3×3)', '4x4' => 'Riesig (4×4)'], 'default' => '2x2'],
            'qr_enabled' => ['type' => 'bool', 'label' => 'Kalender-QR je Termin drucken', 'default' => true],
            'qr_position' => ['type' => 'choice', 'label' => 'Position des QR-Codes', 'choices' => ['right' => 'Rechts neben dem Termin', 'left' => 'Links neben dem Termin', 'below' => 'Unter dem Termin (mittig)'], 'default' => 'right'],
            'qr_size' => ['type' => 'choice', 'label' => 'Größe des QR-Codes', 'choices' => ['small' => 'Klein', 'medium' => 'Mittel', 'large' => 'Groß'], 'default' => 'medium'],
            'qr_summary' => ['type' => 'string', 'label' => 'Kalender-Titel (Präfix)', 'default' => 'Termin Arztpraxis', 'optional' => true],
            'cut' => ['type' => 'bool', 'label' => 'Bon abschneiden', 'default' => true],
        ]],
        'invoice_inbox' => ['label' => 'T2med-Rechnungen übernehmen', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Rechnungen über den Drucker „SumUp-Rechnung“ übernehmen'],
            'max_age_days' => ['type' => 'int', 'label' => 'Offene Rechnungen anbieten für (Tage)', 'default' => 14],
            'label_number' => ['type' => 'list', 'label' => 'Eigene Textschnipsel vor der Rechnungsnummer (einer pro Zeile)', 'optional' => true],
            'label_date' => ['type' => 'list', 'label' => 'Eigene Textschnipsel vor dem Rechnungsdatum', 'optional' => true],
            'label_total' => ['type' => 'list', 'label' => 'Eigene Textschnipsel vor der Gesamtsumme', 'optional' => true],
            'label_transfer' => ['type' => 'list', 'label' => 'Eigene Textschnipsel vor dem Überweisungsbetrag (Gegenprobe)', 'optional' => true],
            'label_patient' => ['type' => 'list', 'label' => 'Eigene Textschnipsel vor Patient und Geburtsdatum', 'optional' => true],
        ]],
        'letterxpress' => ['label' => 'Letterxpress (Rechnung per Post)', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Rechnungen per Post über Letterxpress versenden'],
            'username' => ['type' => 'string', 'label' => 'Letterxpress Benutzername'],
            'api_key' => ['type' => 'secret', 'label' => 'Letterxpress API-Key'],
            'mode' => ['type' => 'choice', 'label' => 'Betrieb', 'default' => 'test', 'choices' => ['test' => 'Test (nur Postausgang bei Letterxpress, es wird nichts gedruckt oder verschickt)', 'live' => 'Live (echter Postversand, kostenpflichtig)']],
            'color' => ['type' => 'choice', 'label' => 'Druck', 'default' => '1', 'choices' => ['1' => 'Schwarz-Weiß', '4' => 'Farbe']],
            'duplex' => ['type' => 'bool', 'label' => 'Beidseitig drucken', 'default' => false],
            'shipping' => ['type' => 'choice', 'label' => 'Versandart', 'default' => 'auto', 'choices' => ['auto' => 'Automatisch (Inland oder Ausland)', 'national' => 'Nur Inland', 'international' => 'Ausland']],
            'registered' => ['type' => 'choice', 'label' => 'Einschreiben', 'default' => 'none', 'choices' => ['none' => 'Kein Einschreiben', 'r1' => 'Einwurf-Einschreiben', 'r2' => 'Einschreiben']],
        ]],
        'invoice_export' => ['label' => 'Ablage im Netzwerkordner', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Rechnungen als PDF in einen Netzwerkordner kopieren'],
            'share' => ['type' => 'string', 'label' => 'Freigabe (//server/freigabe)', 'default' => '//nas/steuerberater'],
            'directory' => ['type' => 'string', 'label' => 'Unterordner ({jahr} und {monat} möglich)', 'default' => 'Rechnungen/{jahr}/{monat}'],
            'username' => ['type' => 'string', 'label' => 'Benutzername (leer: Gastzugriff)'],
            'password' => ['type' => 'secret', 'label' => 'Passwort'],
            'domain' => ['type' => 'string', 'label' => 'Domäne/Arbeitsgruppe (optional)'],
            'app_invoices' => ['type' => 'bool', 'label' => 'Auch in der App erzeugte Rechnungen ablegen'],
            'when' => ['type' => 'choice', 'label' => 'T2med-Rechnungen ablegen', 'choices' => ['paid' => 'nur per Karte bezahlte', 'received' => 'jede gedruckte']],
        ]],
    ];

    public static function read(Config $base): array {
        $path = rtrim($base->get('app', 'state_dir'), '/') . '/payments.sqlite';
        if (!is_file($path)) return [];
        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key=?');
            $stmt->execute([self::ROW]);
            $cipher = $stmt->fetchColumn();
        } catch (\PDOException) {
            return [];   // Tabelle noch nicht angelegt (vor der ersten Migration)
        }
        return $cipher ? self::decrypt((string)$cipher, $base) : [];
    }

    /** Wirksame Konfiguration; ungültige Überlagerung fällt auf die TOML-Datei zurück. */
    public static function effective(Config $base): Config {
        $overrides = self::read($base);
        if (!$overrides) return $base;
        try {
            return new Config(self::merge($base->values, $overrides));
        } catch (\RuntimeException $e) {
            error_log('kienzle-sumup: gespeicherte Einstellungen ungültig, verwende Konfigurationsdatei: ' . $e->getMessage());
            return $base;
        }
    }

    public static function merge(array $values, array $overrides): array {
        foreach ($overrides as $section => $keys) {
            if (!is_array($keys) || !isset(self::SCHEMA[$section]) && $section !== 'internal') continue;
            foreach ($keys as $key => $value) $values[$section][$key] = $value;
        }
        unset($values['internal']);
        return $values;
    }

    /** Werte für die Oberfläche; Geheimnisse nur als „gesetzt“. */
    public static function view(Config $config): array {
        $sections = [];
        foreach (self::SCHEMA as $section => $definition) {
            $fields = [];
            foreach ($definition['fields'] as $key => $field) {
                $value = $config->get($section, $key, $section === 'terminzettel' ? (Terminzettel::existing()[$key] ?? $field['default'] ?? null) : ($field['default'] ?? null));
                if ($field['type'] === 'secret') $value = ['set' => is_string($value) && $value !== ''];
                elseif ($field['type'] === 'pem') $value = ['set' => is_string($config->get('fhir', 'ca_file', '')) && $config->get('fhir', 'ca_file', '') !== ''];
                elseif ($field['type'] === 'money') $value = intdiv((int)$value, 100);
                elseif ($field['type'] === 'percent') $value = rtrim(rtrim(number_format((int)$value / 100, 2, ',', ''), '0'), ',');
                elseif ($field['type'] === 'list') $value = implode("\n", (array)$value);
                $fields[] = ['key' => $key, 'value' => $value] + SettingsHelp::for($section, $key) + $field;
            }
            $sections[] = ['id' => $section, 'label' => $definition['label'], 'fields' => $fields];
        }
        return $sections;
    }

    /** Einen Abschnitt speichern; liefert die neue wirksame Konfiguration oder wirft Problem mit Klartext. */
    public static function save(Database $db, Config $base, string $section, array $input): Config {
        if (!isset(self::SCHEMA[$section])) throw new Problem('Unbekannter Einstellungsbereich.', 404);
        $overrides = self::read($base);
        $current = self::effective($base);
        $values = $overrides[$section] ?? [];
        foreach (self::SCHEMA[$section]['fields'] as $key => $field) {
            if (!array_key_exists($key, $input)) continue;
            $raw = $input[$key];
            $values[$key] = match ($field['type']) {
                'bool' => $raw === true,
                'int' => $raw === '' || $raw === null ? null : (is_numeric($raw) && (string)(int)$raw === trim((string)$raw) ? (int)$raw : throw new Problem($field['label'] . ': ganze Zahl erwartet.', 422)),
                'money' => is_numeric($raw) && (int)$raw > 0 ? (int)round((float)$raw * 100) : throw new Problem($field['label'] . ': Betrag erwartet.', 422),
                'choice' => isset($field['choices'][$raw]) ? $raw : throw new Problem($field['label'] . ': ungültige Auswahl.', 422),
                'list' => array_values(array_filter(array_map(fn($line) => rtrim(trim($line), '/'), preg_split('/[\r\n,]+/', (string)$raw)))),
                'secret' => trim((string)$raw) === '' ? $current->get($section, $key, '') : trim((string)$raw),
                'pem' => self::pem($base, (string)$raw),
                'text' => self::text($field['label'], $raw),
                'percent' => self::percent($field['label'], (string)$raw),
                default => trim((string)$raw),
            };
            if ($values[$key] === null) unset($values[$key]);
        }
        if ($section === 'fhir') {
            if (array_key_exists('ca_pem', $values)) {
                $values['ca_file'] = $values['ca_pem'] ?? $current->get('fhir', 'ca_file', '');
                unset($values['ca_pem']);
            }
            if (($values['mode'] ?? '') === 'live') {
                if (empty($values['api_key'])) $values['api_key'] = self::T2MED_DEMO_API_KEY;
                if (empty($values['launch_urls'])) $values['launch_urls'] = [rtrim((string)($values['base_url'] ?? ''), '/')];
                $values['base_url'] = rtrim((string)($values['base_url'] ?? ''), '/');
            }
        }
        if ($section === 'printing' && isset($values['model']) && !isset($values['width_dots'])) {
            $values['width_dots'] = Config::PRINTER_MODELS[$values['model']]['width_dots'];
        }
        if ($section === 'invoice_inbox' && ($values['enabled'] ?? false) && !preg_match('/^[a-f0-9]{64}$/D', (string)$current->get('invoice_inbox', 'key', ''))) {
            $values['key'] = bin2hex(random_bytes(32));   // Schlüssel für den Druckserver automatisch erzeugen
        }
        $overrides[$section] = $values;
        // Abhängigkeiten, die sonst eine Speicherung blockieren würden.
        $merged = self::merge($base->values, $overrides);
        if ($section === 'sumup' && ($values['mode'] ?? '') === 'mock' && ($merged['fhir']['mode'] ?? 'mock') === 'live') {
            throw new Problem('Im Testmodus darf T2med nicht live angebunden sein. Bitte zuerst bei der T2med-Anbindung den Testmodus wählen.', 422);
        }
        try {
            $config = new Config($merged);
        } catch (\RuntimeException $e) {
            throw new Problem($e->getMessage(), 422);
        }
        $db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at',
            [self::ROW, self::encrypt($overrides, $base), time()]);
        Terminzettel::sync($config, $overrides['terminzettel'] ?? []);
        if ($section === 'invoice_inbox') InvoicePrinter::sync($config);
        return $config;
    }

    /** Prozentsatz „19“ oder „7,5“ als Hundertstelprozent (1900, 750); 0 bis 30 %. */
    private static function percent(string $label, string $raw): int {
        $raw = trim(str_replace([',', '%'], ['.', ''], $raw));
        if (!preg_match('/^(\d{1,2})(?:\.(\d{1,2}))?$/D', $raw, $m)) throw new Problem($label . ': Zahl erwartet, z. B. 19 oder 7.', 422);
        $value = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        if ($value > 3000) throw new Problem($label . ': höchstens 30 %.', 422);
        return $value;
    }

    /** Mehrzeiliger Freitext (Terminzettel): höchstens 1000 Zeichen, keine Steuerzeichen. */
    private static function text(string $label, mixed $raw): string {
        $text = trim(str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], (string)$raw), "\n ");
        if (mb_strlen($text) > 1000 || preg_match('/[\x00-\x09\x0b-\x1f\x7f-\x9f]/u', $text) || !mb_check_encoding($text, 'UTF-8')) {
            throw new Problem($label . ': höchstens 1000 Zeichen, nur normaler Text.', 422);
        }
        return $text;
    }

    /** PEM-Zertifikat prüfen und im Datenverzeichnis ablegen; leer = unverändert, „-“ = entfernen. */
    private static function pem(Config $base, string $pem): ?string {
        $pem = trim(str_replace("\r", '', $pem));
        if ($pem === '') return null;
        if ($pem === '-') return '';
        if (str_contains($pem, 'PRIVATE KEY') || substr_count($pem, 'BEGIN CERTIFICATE') !== 1 || !openssl_x509_read($pem)) {
            throw new Problem('Bitte genau ein öffentliches PEM-Zertifikat einfügen (ohne privaten Schlüssel).', 422);
        }
        $path = rtrim($base->get('app', 'state_dir'), '/') . '/t2med-ca.pem';
        if (file_put_contents($path, $pem . "\n") === false) throw new Problem('Zertifikat konnte nicht gespeichert werden.', 500);
        chmod($path, 0640);
        return $path;
    }

    private static function encrypt(array $data, Config $base): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $nonce, hex2bin($base->get('app', 'encryption_key'))));
    }
    private static function decrypt(string $cipher, Config $base): array {
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < 40) return [];
        $json = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), hex2bin($base->get('app', 'encryption_key')));
        if ($json === false) { error_log('kienzle-sumup: gespeicherte Einstellungen nicht lesbar (Schlüssel geändert?)'); return []; }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
