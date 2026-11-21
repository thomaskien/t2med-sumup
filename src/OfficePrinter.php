<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Normaler Praxisdrucker (A4) über CUPS. CUPS wandelt die PDF selbst in die Druckersprache um;
 * der Drucker kann lokal oder auf einem anderen CUPS-Server (z. B. kienzlebox) eingerichtet sein.
 */
class OfficePrinter {
    public function __construct(private Config $config) {}

    public function enabled(): bool { return (bool)$this->config->get('office_printing', 'enabled', false); }
    public function label(): string { return (string)$this->config->get('office_printing', 'queue', ''); }

    /** Übergibt die PDF an CUPS und liefert die Auftragsnummer. Der Titel enthält keine Patientendaten. */
    public function send(string $pdf, string $title): string {
        if (!$this->enabled()) throw new Problem('Praxisdrucker ist nicht eingerichtet.', 409);
        if (!str_starts_with($pdf, '%PDF-')) throw new Problem('Druckdaten sind keine PDF.', 500);
        $title = preg_replace('/[^A-Za-z0-9 ._\/-]/', '', $title);
        $options = ['-o', 'media=' . $this->config->get('office_printing', 'media', 'A4')];
        if ($this->config->get('office_printing', 'duplex', false)) array_push($options, '-o', 'sides=two-sided-long-edge');
        return Cups::submit($this->config->get('office_printing', 'cups_server', ''), $this->config->get('office_printing', 'queue'),
            $title, $options, $pdf, fn(array $args, string $data) => $this->run($args, $data));
    }
    protected function run(array $args, string $data): array {
        return Cups::run($args, $data, 30);
    }
}
