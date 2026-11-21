<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Legt die Original-PDF einer T2med-Rechnung per SMB in einem Netzwerkordner ab
 * (z. B. für die Steuerberatung). Dateiname ohne Patientendaten.
 */
class InvoiceExport {
    public function __construct(private Config $config) {}

    public function enabled(): bool { return (bool)$this->config->get('invoice_export', 'enabled', false); }
    public function when(): string { return $this->config->get('invoice_export', 'when', 'paid'); }
    /** Auch die in der App erzeugten Rechnungen/Leistungsbelege ablegen? */
    public function appInvoices(): bool { return $this->enabled() && (bool)$this->config->get('invoice_export', 'app_invoices', false); }

    /** Dateiname: 2026-03-15_RE2026-0042_42,50EUR[_Karte].pdf */
    /** $paid: false = unbezahlt, true/'Karte' = per Karte bezahlt, 'Bar' = bar bezahlt. */
    public static function filename(array $invoice, bool|string $paid): string {
        $number = preg_replace('/[^A-Za-z0-9._-]/', '_', $invoice['invoice_number']);
        $amount = number_format($invoice['amount_cents'] / 100, 2, ',', '');
        return $invoice['invoice_date'] . '_' . $number . '_' . $amount . 'EUR' . ($paid === true ? '_Karte' : (is_string($paid) && $paid !== '' ? '_' . $paid : '')) . '.pdf';
    }
    /** Unterordner mit Platzhaltern {jahr} und {monat} aus dem Rechnungsdatum. */
    public function directory(array $invoice): array {
        [$year, $month] = explode('-', $invoice['invoice_date']);
        $path = str_replace(['{jahr}', '{monat}'], [$year, $month], (string)$this->config->get('invoice_export', 'directory', ''));
        return array_values(array_filter(explode('/', $path), static fn($part) => $part !== ''));
    }

    public function export(array $invoice, string $pdf, bool|string $paid): string {
        if (!$this->enabled()) throw new Problem('Ablage im Netzwerkordner ist deaktiviert.', 409);
        if (!str_starts_with($pdf, '%PDF-')) throw new Problem('Original-PDF der Rechnung fehlt.', 409);
        // Testzahlungen nie mit echten Rechnungen verwechseln.
        $name = ($this->config->get('sumup', 'mode') === 'mock' ? 'TEST_' : '') . self::filename($invoice, $paid);
        $folders = $this->directory($invoice);
        $file = tempnam(sys_get_temp_dir(), 'ks-invoice-'); $auth = tempnam(sys_get_temp_dir(), 'ks-auth-');
        if ($file === false || $auth === false) throw new Problem('Ablage konnte nicht vorbereitet werden.', 500);
        try {
            chmod($file, 0600); chmod($auth, 0600);
            if (file_put_contents($file, $pdf) !== strlen($pdf)) throw new Problem('Ablage konnte nicht vorbereitet werden.', 500);
            $user = (string)$this->config->get('invoice_export', 'username', '');
            $credentials = [];
            if ($user !== '') {
                file_put_contents($auth, 'username = ' . $user . "\npassword = " . $this->config->get('invoice_export', 'password', '') . "\n" .
                    ($this->config->get('invoice_export', 'domain', '') !== '' ? 'domain = ' . $this->config->get('invoice_export', 'domain') . "\n" : ''));
                $credentials = ['-A', $auth];
            } else $credentials = ['-N', '-U', 'guest'];
            // Unterordner nacheinander anlegen; „existiert bereits“ ist kein Fehler.
            $path = [];
            foreach ($folders as $folder) {
                $path[] = $folder;
                $this->run($credentials, 'mkdir "' . implode('\\', $path) . '"');
            }
            $command = ($path ? 'cd "' . implode('\\', $path) . '"; ' : '') . 'put "' . $file . '" "' . $name . '"';
            $result = $this->run($credentials, $command);
            preg_match_all('/\bNT_STATUS_[A-Z0-9_]{1,64}\b/', $result['output'] . "\n" . $result['stderr'], $matches);
            if ($result['code'] !== 0 || $matches[0]) {
                $codes = array_slice(array_unique($matches[0]), 0, 3);
                throw new Problem('Netzwerkordner: ' . ($codes ? implode(', ', $codes) : 'Exit-Code ' . (int)$result['code']) . '.', 502);
            }
            return implode('/', [...$folders, $name]);
        } finally {
            @unlink($file); @unlink($auth);
        }
    }
    /** Verbindungstest: Testdatei schreiben und wieder löschen. */
    public function check(): string {
        if (!$this->enabled()) throw new Problem('Ablage im Netzwerkordner ist deaktiviert.', 409);
        $folders = $this->directory(['invoice_date' => date('Y-m-d')]);
        $name = 'kienzle-sumup-verbindungstest.txt';
        $file = tempnam(sys_get_temp_dir(), 'ks-check-'); $auth = tempnam(sys_get_temp_dir(), 'ks-auth-');
        try {
            chmod($file, 0600); chmod($auth, 0600); file_put_contents($file, "Kienzle-SumUp Verbindungstest\n");
            $credentials = $this->credentials($auth);
            $path = [];
            foreach ($folders as $folder) { $path[] = $folder; $this->run($credentials, 'mkdir "' . implode('\\', $path) . '"'); }
            $result = $this->run($credentials, ($path ? 'cd "' . implode('\\', $path) . '"; ' : '') . 'put "' . $file . '" "' . $name . '"; del "' . $name . '"');
            preg_match_all('/\bNT_STATUS_[A-Z0-9_]{1,64}\b/', $result['output'] . "\n" . $result['stderr'], $m);
            if ($result['code'] !== 0 || $m[0]) throw new Problem('Netzwerkordner: ' . ($m[0] ? implode(', ', array_unique($m[0])) : 'Exit-Code ' . (int)$result['code']) . '.', 502);
            return 'Schreiben und Löschen in ' . $this->config->get('invoice_export', 'share') . ($folders ? '/' . implode('/', $folders) : '') . ' erfolgreich.';
        } finally { @unlink($file); @unlink($auth); }
    }
    private function credentials(string $auth): array {
        $user = (string)$this->config->get('invoice_export', 'username', '');
        if ($user === '') return ['-N', '-U', 'guest'];
        file_put_contents($auth, 'username = ' . $user . "\npassword = " . $this->config->get('invoice_export', 'password', '') . "\n" .
            ($this->config->get('invoice_export', 'domain', '') !== '' ? 'domain = ' . $this->config->get('invoice_export', 'domain') . "\n" : ''));
        return ['-A', $auth];
    }
    protected function run(array $credentials, string $command): array {
        if (!is_executable('/usr/bin/smbclient')) throw new Problem('Samba-Client fehlt. Bitte den Server-Installer ausführen (smbclient).', 503);
        return Command::run(['/usr/bin/smbclient', $this->config->get('invoice_export', 'share'), ...$credentials,
            '--option=client min protocol=SMB2', '--timeout=15', '-c', $command], '', 25, 65536);
    }
}
