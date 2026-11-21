<?php
declare(strict_types=1);
namespace KienzleSumup;

/** JSON-Aktionen der Einstellungsseite. Alles außer Status, Ersteinrichtung und Anmeldung braucht eine Admin-Sitzung. */
final class SettingsPage {
    private Database $db;
    private Admin $admin;
    private ?string $cookie = null;

    private PrinterAdmin $printers;
    private ?Updater $updater = null;
    public function updater(): Updater { return $this->updater ??= new Updater($this->db, dirname(__DIR__)); }
    public function setUpdater(Updater $updater): void { $this->updater = $updater; }

    public function __construct(private Config $base, private ?HttpClient $http = null, ?PrinterAdmin $printers = null) {
        $this->printers = $printers ?? new PrinterAdmin();
        $this->db = new Database($base->get('app', 'state_dir'));
        $this->db->migrate();
        $this->admin = new Admin($this->db, $base->get('app', 'state_dir'));
    }

    public function cookie(): ?string { return $this->cookie; }

    /** Adresse des anfragenden Geräts (für Tests setzbar). */
    public ?string $client = null;
    public ?Network $network = null;
    private function firewall(): Firewall { return new Firewall($this->db, $this->network ?? new Network()); }
    private function clientIp(): string { return $this->client ?? (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'); }

    private function sess(?string $token): ?array { $this->admin->client = $this->clientIp(); return $this->admin->session($token); }
    /** Aktionen, bei denen ein gemerktes Gerät das Admin-Passwort erneut eingeben muss. */
    private const CONFIRM = ['save', 'access_save', 'update_start', 'update_channel', 'firewall_apply', 'firewall_disable', 'printer_setup'];

    public function handle(string $action, array $input, ?string $sessionToken, ?string $csrf): array {
        if ($action === 'status') {
            $session = $this->sess($sessionToken);
            return ['setup_required' => !$this->admin->configured(), 'logged_in' => (bool)$session, 'csrf' => $session['csrf'] ?? null, 'role' => $session['role'] ?? null];
        }
        if ($action === 'setup' || $action === 'login') {
            $result = $action === 'setup'
                ? $this->admin->setup(App::string($input, 'token', 100), App::string($input, 'password', 400))
                : (function () use ($input) { $this->admin->client = $this->clientIp(); return $this->admin->login(App::string($input, 'password', 400), ($input['remember'] ?? false) === true, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300)); })();
            $this->cookie = Admin::cookie($result['token'], $result['ttl']);
            return ['logged_in' => true, 'csrf' => $result['csrf'], 'role' => $result['role'], 'remember' => $result['remember'] ?? false];
        }
        $session = $this->sess($sessionToken);
        if (!$session) throw new Problem('Bitte anmelden.', 401);
        if (!in_array($action, ['get', 'audit', 'printer_setup', 'printers', 't2med_paid', 'update_check', 'update_status', 'firewall_info', 'firewall_status', 'inbox_overview', 'catalog'], true) && !hash_equals($session['csrf'], (string)$csrf)) throw new Problem('Sitzung abgelaufen. Bitte Seite neu laden.', 403);
        // Gemerktes Gerät: heikle Aktionen nur mit erneuter Passworteingabe.
        // Der Terminzettel enthält keine Zugangsdaten: Änderungen daran brauchen auch auf gemerkten Geräten kein Passwort.
        $plainSave = $action === 'save' && App::string($input, 'section', 40) === 'terminzettel';
        if ((int)($session['remember'] ?? 0) === 1 && !$plainSave && in_array($action, self::CONFIRM, true)) $this->admin->confirmPassword(App::string($input, 'confirm', 400, false));
        // Mitarbeiter-Zugang: nur die freigegebenen Aktionen (Rechte werden bei jeder Anfrage neu gelesen).
        $staff = ($session['role'] ?? 'admin') === 'staff';
        if ($staff) $this->requireStaffRight($action);
        // Alles, was etwas verändert, im Protokoll festhalten (ohne Werte).
        if (!in_array($action, ['get', 'printers', 't2med_paid', 'update_check', 'update_status', 'status', 'audit', 'inbox_overview', 'catalog', 'firewall_info', 'firewall_status', 'terminzettel_preview', 'printer_discover', 'test'], true)) {
            $this->admin->audit->write($action, $staff ? 'Mitarbeiter' : ($action === 'save' ? App::string($input, 'section', 40) : ''));
        }
        $result = match ($action) {
            'get' => $staff ? $this->staffView() : $this->view(),
            'access_save' => $this->accessSave($input),
            'devices' => ['devices' => $this->admin->devices($sessionToken)],
            'device_revoke' => (function () use ($input, $sessionToken) { $n = $this->admin->revoke(App::string($input, 'id', 16)); return ['devices' => $this->admin->devices($sessionToken), 'message' => $n . ($n === 1 ? ' Gerät abgemeldet.' : ' Geräte abgemeldet.')]; })(),
            'inbox_overview' => InboxOverview::build($this->db, $this->config()),
            'inbox_clear' => (function () { (new InboxLog($this->config()->get('app', 'state_dir')))->clear(); return InboxOverview::build($this->db, $this->config()) + ['message' => 'Liste der abgelehnten Drucke geleert.']; })(),
            'audit' => ['lines' => $this->admin->audit->recent(40)],
            'firewall_info' => $this->firewall()->overview($this->clientIp()) + ['web_port' => (int)$this->config()->get('app', 'port', 7868)],
            'firewall_status' => $this->firewall()->status(),
            'firewall_apply' => $this->firewall()->apply($input, $this->clientIp()),
            'firewall_confirm' => $this->firewall()->confirm(),
            'firewall_disable' => $this->firewall()->disable(),
            'save' => $this->save(App::string($input, 'section', 40), is_array($input['values'] ?? null) ? $input['values'] : []),
            'test' => ['message' => $this->test(App::string($input, 'kind', 40))],
            'password' => $this->password($input, $staff ? 'staff' : 'admin'),
            'logout' => $this->logout($sessionToken),
            'printer_setup' => $this->printerSetup(),
            'printers' => $this->printerList(),
            'update_check' => $this->updater()->check(($input['force'] ?? false) === true) + ['status' => $this->updater()->status()],
            'update_status' => $this->updater()->status(),
            'update_start' => $this->updater()->request(),
            'update_channel' => (function () use ($input) { $u = $this->updater(); $u->setChannel(App::string($input, 'channel', 10)); return $u->check(true) + ['status' => $u->status(), 'message' => 'Kanal gespeichert.']; })(),
            't2med_paid' => $this->t2medPaid($input),
            't2med_done' => $this->t2medDone($input),
            't2med_correct' => $this->t2medCorrect($input),
            'inbox_delete' => $this->inboxDelete($input),
            'inbox_letter' => $this->inboxLetter($input),
            'inbox_letter_many' => $this->inboxMany($input, 'letter'),
            'inbox_delete_many' => $this->inboxMany($input, 'delete'),
            'catalog' => $this->catalog()->overview(),
            'catalog_service_save' => $this->catalogChange(fn(Catalog $c) => $c->saveService($input), 'Leistung gespeichert.'),
            'catalog_service_delete' => $this->catalogChange(fn(Catalog $c) => $c->deleteService(App::string($input, 'id', 32)), 'Leistung gelöscht. Frühere Zahlungen behalten ihre Angaben.'),
            'catalog_group_save' => $this->catalogChange(fn(Catalog $c) => $c->saveGroup($input), 'Kombination gespeichert.'),
            'catalog_group_delete' => $this->catalogChange(fn(Catalog $c) => $c->deleteGroup(App::string($input, 'id', 32)), 'Kombination gelöscht. Die Einzelleistungen bleiben erhalten.'),
            'terminzettel_preview' => $this->terminzettelPreview(is_array($input['values'] ?? null) ? $input['values'] : []),
            'printer_discover' => ['found' => $this->printerReady()->discover()],
            'printer_add' => $this->printerAdd($input),
            'printer_use' => $this->printerUse(App::string($input, 'name', 41), App::string($input, 'kind', 20)),
            'printer_remove' => $this->printerRemove(App::string($input, 'name', 41)),
            default => throw new Problem('Unbekannte Aktion.', 404),
        };
        // Ohne das Recht „per Post“ blendet der Rechnungseingang die Versand-Schaltflächen aus.
        if ($staff && isset($result['letter_enabled']) && !in_array('letter', $this->admin->staffRights(), true)) $result['letter_enabled'] = false;
        return $result;
    }

    private function config(): Config { return Settings::effective($this->base); }

    /** Private IPv4-Adressen dieses Servers (für Druckeradressen aus anderen Netzen, z. B. VPN). */
    private function serverAddresses(): array {
        $found = [];
        try {
            foreach (($this->network ?? new Network())->detect()['interfaces'] as $interface) {
                foreach ($interface['addresses'] as $a) if ($a['family'] === 4 && $a['private']) $found[] = ['name' => $interface['name'], 'ip' => $a['ip']];
            }
        } catch (\Throwable) {}
        return array_slice($found, 0, 8);
    }

    private function view(): array {
        $config = $this->config();
        $tools = [];
        foreach (['pdftotext' => '/usr/bin/pdftotext', 'smbclient' => '/usr/bin/smbclient', 'lp' => '/usr/bin/lp', 'rsvg-convert' => '/usr/bin/rsvg-convert'] as $name => $path) $tools[$name] = is_executable($path);
        $broken = Settings::read($this->base) && $config === $this->base;
        return ['access' => $this->admin->access(), 'sections' => Settings::view($config), 'tools' => $tools + ['gd' => extension_loaded('gd')],
            'base_url' => $config->get('app', 'base_url'), 'version' => trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')),
            'mode' => $config->get('sumup', 'mode'), 'fhir_mode' => $config->get('fhir', 'mode'),
            'warning' => $broken ? 'Gespeicherte Einstellungen passen nicht zur aktuellen Version und werden ignoriert. Bitte die Bereiche prüfen und neu speichern.' : null,
            'invoice_printer' => InvoicePrinter::status($this->printers, $config) + ['ips' => $this->serverAddresses()], 'clients' => $this->clients(),
            'export_years' => (new TaxExport($this->db, $config))->years(),
            'terminzettel' => ['installed' => Terminzettel::installed(), 'target' => Terminzettel::target($config), 'ips' => $this->serverAddresses(),
                'printer_url' => 'http://' . (parse_url((string)$config->get('app', 'base_url'), PHP_URL_HOST) ?: 'server') . ':631/printers/Terminzettel']];
    }

    private function save(string $section, array $values): array {
        $config = Settings::save($this->db, $this->base, $section, $values);
        $message = 'Gespeichert. Gilt ab sofort für alle Arbeitsplätze.';
        if ($section === 'invoice_inbox') {
            // Drucker „SumUp-Rechnung“ gleich mit anlegen bzw. entfernen – ohne Terminal.
            try {
                $state = InvoicePrinter::apply($this->printers, (bool)$config->get('invoice_inbox', 'enabled', false));
                if ($state === true) $message .= ' Drucker „SumUp-Rechnung“ ist eingerichtet.';
                elseif ($state === false) $message .= ' Drucker „SumUp-Rechnung“ ist entfernt.';
                else $message .= ' Hinweis: Der Drucker „SumUp-Rechnung“ braucht einmal den aktuellen Server-Installer.';
            } catch (Problem $e) {
                $message .= ' Drucker „SumUp-Rechnung“: ' . $e->getMessage();
            }
        }
        return $this->view() + ['message' => $message];
    }

    private function test(string $kind): string {
        $config = $this->config();
        $mock = $config->get('sumup', 'mode') !== 'live';
        switch ($kind) {
            case 'sumup':
                return (new SumUpClient($config, $this->http ?? new HttpClient()))->check();
            case 'receipt_printer':
                if (!$config->get('printing', 'enabled', false)) throw new Problem('Bondrucker ist nicht aktiviert.', 409);
                // ESC/POS-Text: Initialisieren, Text, Vorschub, Schnitt.
                (new ReceiptPrinter($config))->send("\x1b@Kienzle-SumUp Testdruck\n" . date('d.m.Y H:i') . "\n\n\n\n\x1dVB\x00");
                return 'Testbon an den Bondrucker übergeben.';
            case 'terminzettel':
                if (!Terminzettel::target($config)) throw new Problem('Zuerst unter „Drucker einrichten“ einen Bondrucker an diesem Server einrichten.', 409);
                $bytes = Terminzettel::sample($this->terminzettelValues([]), 'escpos')['data'];
                (new ReceiptPrinter($config))->send($bytes);
                return 'Beispiel-Terminzettel an den Bondrucker übergeben.';
            case 'office_printer':
                $office = new OfficePrinter($config);
                if (!$office->enabled()) throw new Problem('Praxisdrucker ist nicht aktiviert.', 409);
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="210mm" height="297mm" viewBox="0 0 210 297"><rect width="210" height="297" fill="white"/>'
                    . '<text x="20" y="30" font-family="DejaVu Sans" font-size="8">Kienzle-SumUp Testseite</text><text x="20" y="42" font-family="DejaVu Sans" font-size="4">' . date('d.m.Y H:i') . '</text></svg>';
                return 'Testseite übergeben: Auftrag ' . $office->send(ReceiptPdf::convert($svg), 'Kienzle-SumUp Testseite') . '.';
            case 'export':
                return (new InvoiceExport($config))->check();
            case 'letterxpress':
                return (new Letterxpress($config, $this->http ?? new HttpClient(), $this->db))->check();
            default:
                throw new Problem('Unbekannter Test.', 404);
        }
    }

    private function password(array $input, string $role = 'admin'): array {
        $this->admin->changePassword(App::string($input, 'current', 400), App::string($input, 'new', 400), $role);
        $result = $this->admin->login(App::string($input, 'new', 400));
        $this->cookie = Admin::cookie($result['token'], $result['ttl']);
        return ['csrf' => $result['csrf'], 'message' => 'Passwort geändert.'];
    }

    /** Aktionen, die jeder angemeldete Mitarbeiter ausführen darf. */
    private const STAFF_ALWAYS = ['get', 'status', 'password', 'logout'];
    private function requireStaffRight(string $action): void {
        if (in_array($action, self::STAFF_ALWAYS, true)) return;
        foreach ($this->admin->staffRights() as $right) if (in_array($action, Admin::RIGHTS[$right][1], true)) return;
        $this->admin->audit->write('verweigert', $action . ' Mitarbeiter');
        throw new Problem('Dafür fehlt dem Mitarbeiter-Zugang die Berechtigung.', 403);
    }
    /** Mitarbeiter sehen nur Modus, Version und die freigegebenen Bereiche – keine Einstellungen, Schlüssel oder Zugangsdaten. */
    private function staffView(): array {
        $config = $this->config();
        return ['role' => 'staff', 'rights' => $this->admin->staffRights(), 'mode' => $config->get('sumup', 'mode'), 'fhir_mode' => $config->get('fhir', 'mode'),
            'version' => trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')), 'export_years' => (new TaxExport($this->db, $config))->years()];
    }
    private function accessSave(array $input): array {
        $this->admin->saveAccess((int)($input['idle_minutes'] ?? 0), (int)($input['max_hours'] ?? 0), is_array($input['rights'] ?? null) ? $input['rights'] : [],
            App::string($input, 'staff_password', 400, false), ($input['remove_staff'] ?? false) === true);
        return $this->admin->access() + ['message' => 'Zugang gespeichert. Die Abmeldezeit gilt ab der nächsten Anmeldung.'];
    }

    private function logout(?string $token): array {
        $this->admin->logout($token);
        $this->cookie = Admin::cookie('', 0);
        return ['logged_in' => false];
    }

    /** Gespeicherte Terminzettel-Werte, überlagert von noch nicht gespeicherten Formularwerten. */
    private function terminzettelValues(array $form): array {
        $values = (Settings::read($this->base)['terminzettel'] ?? []);
        foreach (Settings::SCHEMA['terminzettel']['fields'] as $key => $field) {
            if (!array_key_exists($key, $form)) continue;
            $value = $form[$key];
            $values[$key] = $field['type'] === 'bool' ? $value === true : (is_scalar($value) ? (string)$value : '');
        }
        return Terminzettel::overlay($this->config(), $values);
    }
    private function terminzettelPreview(array $form): array {
        $result = Terminzettel::sample($this->terminzettelValues($form));
        return ['image' => 'data:image/png;base64,' . base64_encode($result['data']), 'notes' => $result['notes']];
    }

    private function printerReady(): PrinterAdmin {
        if (!$this->printers->available()) throw new Problem('Druckdienst fehlt auf diesem Server. Bitte einmal sudo bash scripts/install-server.sh ausführen.', 503);
        return $this->printers;
    }
    private function printerList(): array {
        $config = $this->config();
        $receipt = $config->get('printing', 'enabled', false) && $config->get('printing', 'transport', 'samba') === 'cups' && $config->get('printing', 'cups_server', '') === '' ? $config->get('printing', 'queue') : null;
        $office = $config->get('office_printing', 'enabled', false) && $config->get('office_printing', 'cups_server', '') === '' ? $config->get('office_printing', 'queue') : null;
        if (!$this->printers->available()) return ['available' => false, 'queues' => [], 'kinds' => PrinterAdmin::KINDS];
        $queues = array_map(fn($q) => $q + ['role' => $q['name'] === $receipt ? 'receipt' : ($q['name'] === $office ? 'office' : null)], $this->printers->queues());
        return ['available' => true, 'queues' => $queues, 'kinds' => PrinterAdmin::KINDS];
    }
    private function printerAdd(array $input): array {
        $kind = App::string($input, 'kind', 20, false);
        $added = $this->printerReady()->add($kind, App::string($input, 'name', 41, false), App::string($input, 'address', 320, false));
        $this->assign($added['name'], $kind);
        return $this->view() + ['printers' => $this->printerList(), 'added' => $added['name'],
            'message' => 'Drucker „' . $added['name'] . '“ eingerichtet und als ' . ($kind === 'office' ? 'Praxisdrucker (A4)' : 'Bondrucker') . ' übernommen. Jetzt „Testdruck“ wählen.'];
    }
    private function printerUse(string $name, string $kind): array {
        if (!isset(PrinterAdmin::KINDS[$kind])) throw new Problem('Bitte wählen, wofür der Drucker verwendet wird.', 422);
        if (!in_array($name, array_column($this->printerReady()->queues(), 'name'), true)) throw new Problem('Drucker nicht gefunden.', 404);
        $this->assign($name, $kind);
        return $this->view() + ['printers' => $this->printerList(), 'message' => '„' . $name . '“ wird jetzt als ' . ($kind === 'office' ? 'Praxisdrucker (A4)' : 'Bondrucker') . ' verwendet.'];
    }
    private function printerRemove(string $name): array {
        $this->printerReady()->remove($name);
        return ['printers' => $this->printerList(), 'message' => 'Drucker „' . $name . '“ entfernt.'];
    }
    /** Einstellungen auf die Warteschlange umstellen (Bondrucker: Modell und Breite passend). */
    private function assign(string $name, string $kind): void {
        if ($kind === 'office') {
            Settings::save($this->db, $this->base, 'office_printing', ['enabled' => true, 'queue' => $name, 'cups_server' => '']);
        } else {
            Settings::save($this->db, $this->base, 'printing', ['enabled' => true, 'model' => $kind, 'transport' => 'cups', 'queue' => $name, 'cups_server' => '', 'width_dots' => '']);
        }
    }

    /** Einrichtungspaket für Arbeitsplätze als Download; nur mit Admin-Sitzung. */
    public function clientDownload(?string $sessionToken, string $system, callable $header, callable $write, string $certificate = '/etc/kienzle-sumup/server.crt'): void {
        $own = $this->sess($sessionToken);
        if (($own['role'] ?? null) === 'admin' && (int)$own['remember'] === 1) throw new Problem('Pakete mit Schlüsseln lädt man nur nach einer Anmeldung ohne „Gerät merken“.', 403);
        if (($own['role'] ?? null) !== 'admin') throw new Problem('Bitte zuerst in den Einstellungen anmelden.', 401);
        $this->admin->audit->write('arbeitsplatz-paket', $system);
        $package = new ClientPackage($this->config(), dirname(__DIR__), $certificate);
        $files = $package->files($system);
        $size = ClientPackage::zip($files, fn() => null);   // Größe vorab für Content-Length
        $header('Content-Type: application/zip');
        $header('Content-Disposition: attachment; filename="' . ClientPackage::filename($system) . '"');
        $header('Content-Length: ' . $size);
        ClientPackage::zip($files, $write);
    }

    private function t2medFilter(array $input): array {
        $status = ($input['status'] ?? 'open') === 'all' ? 'all' : 'open';
        $year = filter_var($input['year'] ?? 0, FILTER_VALIDATE_INT); $month = filter_var($input['month'] ?? 0, FILTER_VALIDATE_INT);
        if ($year === false || $month === false) throw new Problem('Ungültiger Zeitraum.', 422);
        return [$status, $year, $month, ($input['test'] ?? false) === true || ($input['test'] ?? '') === '1'];
    }
    private function t2medPaid(array $input): array {
        [$status, $year, $month, $test] = $this->t2medFilter($input);
        $export = new TaxExport($this->db, $this->config());
        return ['rows' => $export->t2medPaid($status, $year, $month, $test), 'open_total' => count($export->t2medPaid('open', 0, 0, $test))];
    }
    private function t2medCorrect(array $input): array {
        $action = App::string($input, 'change', 10);
        $max = (int)$this->config()->get('app', 'max_amount_cents');
        $change = ['action' => $action, 'note' => App::string($input, 'note', 200, false)];
        if ($action === 'amount') $change['amount_cents'] = Money::cents($input['amount'] ?? null, $max);
        (new TaxExport($this->db, $this->config()))->correctT2medPayment(App::string($input, 'id', 32), $change, $max);
        $text = ['amount' => 'Betrag korrigiert.', 'void' => 'Zahlung storniert. Eine Erstattung an den Patienten erfolgt nicht automatisch, bitte im SumUp-Konto vornehmen.', 'restore' => 'Korrektur zurückgenommen.'][$action];
        return $this->t2medPaid($input['filter'] ?? []) + ['message' => $text];
    }
    private function catalog(): Catalog { return new Catalog($this->db, $this->config()); }
    private function catalogChange(callable $change, string $message): array {
        $catalog = $this->catalog();
        $change($catalog);
        return $catalog->overview() + ['message' => $message];
    }
    /** Rechnung aus dem Eingang per Post senden (nur mit Admin-Sitzung, CSRF und ausdrücklicher Bestätigung in der Oberfläche). */
    private function inboxLetter(array $input): array {
        $config = $this->config();
        $result = (new Letterxpress($config, $this->http ?? new HttpClient(), $this->db))->send(App::string($input, 'id', 32));
        return InboxOverview::build($this->db, $config) + ['message' => $result['message']];
    }
    /** Mehrere Rechnungen auf einmal senden oder löschen; jede einzeln, Fehler werden gezählt statt abzubrechen. */
    private function inboxMany(array $input, string $what): array {
        $ids = is_array($input['ids'] ?? null) ? array_values(array_unique(array_filter($input['ids'], 'is_string'))) : [];
        if (!$ids) throw new Problem('Keine Rechnung ausgewählt.', 422);
        if (count($ids) > 50) throw new Problem('Bitte höchstens 50 Rechnungen auf einmal auswählen.', 422);
        $config = $this->config();
        $letter = $what === 'letter' ? new Letterxpress($config, $this->http ?? new HttpClient(), $this->db) : null;
        $done = 0; $errors = [];
        foreach ($ids as $id) {
            try {
                if ($letter) $letter->send(App::string(['id' => $id], 'id', 32)); else InboxOverview::delete($this->db, App::string(['id' => $id], 'id', 32));
                $done++;
            } catch (Problem $e) { $errors[] = $e->getMessage(); }
        }
        $errors = array_values(array_unique($errors));
        $verb = $letter ? ($config->get('letterxpress', 'mode', 'test') === 'live' ? 'an Letterxpress übergeben' : 'als Testbrief an Letterxpress übergeben') : 'gelöscht';
        $message = $done . ($done === 1 ? ' Rechnung ' : ' Rechnungen ') . $verb . '.';
        if ($errors) $message .= ' Nicht ausgeführt: ' . (count($ids) - $done) . ' (' . mb_substr(implode(' ', $errors), 0, 300) . ')';
        return InboxOverview::build($this->db, $config) + ['message' => $message];
    }
    private function inboxDelete(array $input): array {
        InboxOverview::delete($this->db, App::string($input, 'id', 32));
        return InboxOverview::build($this->db, $this->config()) + ['message' => 'Rechnung aus dem Eingang gelöscht.'];
    }
    private function t2medDone(array $input): array {
        $done = ($input['done'] ?? true) !== false;
        $count = (new TaxExport($this->db, $this->config()))->markT2medDone(is_array($input['ids'] ?? null) ? $input['ids'] : [], $done);
        return $this->t2medPaid($input['filter'] ?? []) + ['message' => $count . ($count === 1 ? ' Rechnung' : ' Rechnungen') . ($done ? ' als in T2med abgehakt markiert.' : ' wieder als offen markiert.')];
    }

    /** Excel-Listen (Steuerberatung bzw. bezahlte T2med-Rechnungen); nur mit Admin-Sitzung. */
    public function taxExport(?string $sessionToken, array $query, callable $header, callable $write): void {
        $session = $this->sess($sessionToken);
        if (!$session) throw new Problem('Bitte zuerst in den Einstellungen anmelden.', 401);
        if (($session['role'] ?? 'admin') === 'staff' && (($query['kind'] ?? '') !== 't2med' || !in_array('paid', $this->admin->staffRights(), true))) throw new Problem('Dafür fehlt dem Mitarbeiter-Zugang die Berechtigung.', 403);
        $this->admin->audit->write('export', (string)($query['kind'] ?? 'steuer'));
        $export = new TaxExport($this->db, $this->config());
        if (($query['kind'] ?? '') === 't2med') {
            [$status, $year, $month, $test] = $this->t2medFilter($query);
            $data = $export->t2medWorkbook($status, $year, $month, $test);
            $name = TaxExport::t2medFilename($status, $year, $month);
        } else {
            $year = filter_var($query['year'] ?? '', FILTER_VALIDATE_INT);
            $month = filter_var($query['month'] ?? '0', FILTER_VALIDATE_INT);
            if ($year === false || $month === false) throw new Problem('Ungültiger Zeitraum.', 422);
            $data = $export->workbook($year, $month, ($query['names'] ?? '') === '1', ($query['test'] ?? '') === '1');
            $name = TaxExport::filename($year, $month);
        }
        $header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $header('Content-Disposition: attachment; filename="' . $name . '"');
        $header('Content-Length: ' . strlen($data));
        $write($data);
    }

    private function clients(): array {
        $out = [];
        foreach (ClientPackage::SYSTEMS as $id => $spec) {
            $bundled = array_values(array_filter($spec['assets'], fn($a) => is_file(dirname(__DIR__) . '/client-dist/' . $a)));
            $out[] = ['id' => $id, 'label' => $spec['label'], 'bundled' => (bool)$bundled];
        }
        return $out;
    }

    /** Einrichtungsdatei für einen entfernten Druckserver (Schlüssel und Serverzertifikat). */
    private function printerSetup(): array {
        $config = $this->config();
        if (!$config->get('invoice_inbox', 'enabled', false)) throw new Problem('Zuerst „T2med-Rechnungen übernehmen“ aktivieren und speichern.', 409);
        $cert = @file_get_contents('/etc/kienzle-sumup/server.crt');
        return ['server_url' => $config->get('app', 'base_url'), 'inbox_key' => $config->get('invoice_inbox', 'key'),
            'certificate' => $cert && !str_contains($cert, 'PRIVATE KEY') ? $cert : ''];
    }
}
