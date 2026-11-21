<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Excel-Liste für die Steuerberatung: alle im Rechnungstool erstellten und per Karte
 * bezahlten Rechnungen eines Jahres oder Monats. T2med-Rechnungen (virtueller Drucker)
 * gehören nicht dazu; sie werden in T2med geführt. Testzahlungen nur auf ausdrücklichen Wunsch.
 */
final class TaxExport {
    private const MONTHS = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public function __construct(private Database $db, private Config $config) {}

    public static function filename(int $year, int $month): string {
        return 'Rechnungen-' . $year . ($month ? '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) : '') . '.xlsx';
    }

    /** Jahre mit Zahlungen (für die Auswahl), neuestes zuerst; mindestens das aktuelle Jahr. */
    public function years(): array {
        $zone = new \DateTimeZone($this->config->get('app', 'timezone'));
        $years = [(int)(new \DateTimeImmutable('now', $zone))->format('Y')];
        $range = $this->db->one("SELECT MIN(paid_at) AS first FROM payments WHERE payment_status='successful' AND paid_at IS NOT NULL AND t2med_invoice IS NULL");
        if ($range && $range['first']) {
            $first = (int)(new \DateTimeImmutable('@' . $range['first']))->setTimezone($zone)->format('Y');
            for ($y = $years[0] - 1; $y >= $first; $y--) $years[] = $y;
        }
        return $years;
    }

    /** Rechnungen des Zeitraums als Liste aufbereiteter Datensätze (Beträge in Cent). */
    public function invoices(int $year, int $month, bool $includeTest = false): array {
        if ($year < 2000 || $year > 2100 || $month < 0 || $month > 12) throw new Problem('Ungültiger Zeitraum.', 422);
        $zone = new \DateTimeZone($this->config->get('app', 'timezone'));
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month ?: 1), $zone);
        $to = $month ? $from->modify('+1 month') : $from->modify('+1 year');
        $rows = $this->db->query("SELECT * FROM payments WHERE payment_status='successful' AND paid_at>=? AND paid_at<? AND t2med_invoice IS NULL ORDER BY paid_at, rowid",
            [$from->getTimestamp(), $to->getTimestamp()])->fetchAll();
        $out = [];
        foreach ($rows as $payment) {
            $invoice = $payment['invoice_json'] ? json_decode($payment['invoice_json'], true, 32, JSON_THROW_ON_ERROR) : null;
            $test = $invoice ? !empty($invoice['mock']) : $payment['reader_id'] === 'mock-reader';
            if ($test && !$includeTest) continue;
            $items = $invoice['items'] ?? json_decode($payment['services_json'], true, 32, JSON_THROW_ON_ERROR);
            $paid = (new \DateTimeImmutable('@' . $payment['paid_at']))->setTimezone($zone);
            $out[] = ['date' => $paid->format('Y-m-d'), 'month' => (int)$paid->format('n'), 'number' => (string)($payment['transaction_id'] ?? ''),
                'service_date' => $invoice['service_date'] ?? '', 'patient' => $invoice['patient']['name'] ?? '',
                'kind' => $invoice ? ($invoice['title'] === 'PRIVATÄRZTLICHE RECHNUNG' ? 'Privatärztliche Rechnung' : 'Leistungsbeleg') : 'Zahlung ohne Beleg',
                'small_business' => !empty($invoice['tax']['small_business']), 'test' => $test,
                'gross' => (int)$payment['amount_cents'], 'items' => $items, 'totals' => Tax::totals($items), 'reference' => $payment['reference'],
                'cash' => ($payment['payment_method'] ?? 'card') === 'cash'];
        }
        return $out;
    }

    /**
     * Per Karte bezahlte T2med-Rechnungen (virtueller Drucker „SumUp-Rechnung“) zum Abhaken in T2med.
     * $status: 'open' = noch nicht als erledigt markiert, 'all' = alle im Zeitraum (Jahr 0 = alle Jahre).
     */
    public function t2medPaid(string $status = 'open', int $year = 0, int $month = 0, bool $includeTest = false): array {
        if (!in_array($status, ['open', 'all'], true) || ($year !== 0 && ($year < 2000 || $year > 2100)) || $month < 0 || $month > 12 || ($month && !$year)) throw new Problem('Ungültige Auswahl.', 422);
        $where = ["p.payment_status='successful'", 'p.t2med_invoice IS NOT NULL']; $params = [];
        if ($status === 'open') array_push($where, 'p.t2med_done_at IS NULL', 'p.voided_at IS NULL');
        if ($year) {
            $zone = new \DateTimeZone($this->config->get('app', 'timezone'));
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month ?: 1), $zone);
            $to = $month ? $from->modify('+1 month') : $from->modify('+1 year');
            $where[] = 'p.paid_at>=? AND p.paid_at<?'; array_push($params, $from->getTimestamp(), $to->getTimestamp());
        }
        if (!$includeTest) $where[] = "p.reader_id<>'mock-reader'";
        $zone = new \DateTimeZone($this->config->get('app', 'timezone'));
        $rows = $this->db->query('SELECT p.id,p.t2med_invoice,p.amount_cents,p.paid_at,p.transaction_id,p.reader_id,p.payment_method,p.t2med_done_at,p.corrected_cents,p.voided_at,p.correction_note,i.invoice_date,i.patient_name,i.patient_birthdate
            FROM payments p LEFT JOIN invoice_inbox i ON i.id=p.inbox_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.t2med_invoice', $params)->fetchAll();
        $date = static fn(?int $t): string => $t ? (new \DateTimeImmutable('@' . $t))->setTimezone($zone)->format('Y-m-d') : '';
        usort($rows, static fn($a, $b) => strnatcasecmp($a['t2med_invoice'], $b['t2med_invoice']));
        return array_map(static function ($r) use ($date, $zone) {
            $voided = $r['voided_at'] !== null;
            $corrected = $r['corrected_cents'] !== null;
            return ['id' => $r['id'], 'number' => $r['t2med_invoice'], 'invoice_date' => (string)($r['invoice_date'] ?? ''),
                'patient' => (string)($r['patient_name'] ?? ''), 'birthdate' => (string)($r['patient_birthdate'] ?? ''),
                // Wirksamer Betrag für Statistik und Excel; das Original bleibt als SumUp-Betrag erhalten.
                'amount_cents' => $voided ? 0 : ($corrected ? (int)$r['corrected_cents'] : (int)$r['amount_cents']), 'original_cents' => (int)$r['amount_cents'],
                'voided' => $voided, 'corrected' => $corrected && !$voided, 'note' => (string)$r['correction_note'],
                'paid_date' => $date((int)$r['paid_at']), 'paid_time' => (new \DateTimeImmutable('@' . $r['paid_at']))->setTimezone($zone)->format('H:i'),
                'transaction_id' => (string)$r['transaction_id'], 'method' => ($r['payment_method'] ?? 'card') === 'cash' ? 'cash' : 'card', 'done_date' => $date($r['t2med_done_at'] !== null ? (int)$r['t2med_done_at'] : null),
                'test' => $r['reader_id'] === 'mock-reader'];
        }, $rows);
    }

    /**
     * Nachträgliche Korrektur einer bezahlten T2med-Rechnung für Statistik und Excel-Liste: Betrag ändern,
     * stornieren oder zurücksetzen. Die SumUp-Zahlung selbst (Originalbetrag, Transaktion) bleibt unverändert;
     * eine Erstattung muss im SumUp-Konto erfolgen.
     * $change: ['action' => 'amount'|'void'|'restore', 'amount_cents' => int, 'note' => string]
     */
    public function correctT2medPayment(string $id, array $change, int $maxCents): array {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new Problem('Ungültige Auswahl.', 422);
        $row = $this->db->one("SELECT id,amount_cents FROM payments WHERE id=? AND t2med_invoice IS NOT NULL AND payment_status='successful'", [$id]);
        if (!$row) throw new Problem('Diese bezahlte Rechnung gibt es nicht.', 404);
        $note = trim(preg_replace('/\s+/u', ' ', (string)($change['note'] ?? '')));
        if (mb_strlen($note) > 200 || preg_match('/[\x00-\x1f]/', $note)) throw new Problem('Die Notiz darf höchstens 200 Zeichen lang sein.', 422);
        switch ($change['action'] ?? '') {
            case 'restore':
                $this->db->query("UPDATE payments SET corrected_cents=NULL, voided_at=NULL, correction_note='' WHERE id=?", [$id]);
                break;
            case 'void':
                $this->db->query('UPDATE payments SET voided_at=?, correction_note=? WHERE id=?', [time(), $note, $id]);
                break;
            case 'amount':
                $cents = (int)($change['amount_cents'] ?? 0);
                if ($cents < 1 || $cents > $maxCents) throw new Problem('Der Betrag liegt außerhalb des erlaubten Bereichs.', 422);
                // Gleicher Betrag wie bezahlt: keine Korrektur.
                $this->db->query('UPDATE payments SET corrected_cents=?, voided_at=NULL, correction_note=? WHERE id=?', [$cents === (int)$row['amount_cents'] ? null : $cents, $note, $id]);
                break;
            default:
                throw new Problem('Unbekannte Korrektur.', 422);
        }
        return ['id' => $id];
    }

    /** Ausgewählte T2med-Zahlungen als „in T2med abgehakt“ markieren (oder zurücknehmen). */
    public function markT2medDone(array $ids, bool $done): int {
        if (!array_is_list($ids) || count($ids) > 2000) throw new Problem('Ungültige Auswahl.', 422);
        $count = 0;
        $this->db->transaction(function () use ($ids, $done, &$count): void {
            foreach ($ids as $id) {
                if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new Problem('Ungültige Auswahl.', 422);
                $count += $this->db->query("UPDATE payments SET t2med_done_at=? WHERE id=? AND t2med_invoice IS NOT NULL AND payment_status='successful'", [$done ? time() : null, $id])->rowCount();
            }
        });
        return $count;
    }

    public static function t2medFilename(string $status, int $year, int $month): string {
        return 'T2med-Rechnungen-bezahlt' . ($status === 'open' ? '-offen' : '') . ($year ? '-' . $year : '') . ($month ? '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) : '') . '.xlsx';
    }

    /** Excel-Liste der bezahlten T2med-Rechnungen, nach Rechnungsnummer sortiert. */
    public function t2medWorkbook(string $status, int $year, int $month, bool $includeTest = false): string {
        $rows = [];
        foreach ($this->t2medPaid($status, $year, $month, $includeTest) as $r) {
            $row = [$r['number'], $r['invoice_date'] ?: null, $r['patient'], $r['birthdate'] ?: null, $r['amount_cents'], $r['paid_date'], $r['paid_time'], $r['method'] === 'cash' ? 'Barzahlung' : 'Kartenzahlung', $r['transaction_id'], $r['done_date'] ?: null, $r['voided'] ? 'storniert' : ($r['corrected'] ? 'Betrag korrigiert (Original ' . Money::format($r['original_cents']) . ')' : ''), $r['note']];
            if ($includeTest) $row[] = $r['test'] ? 'ja' : '';
            $rows[] = $row;
        }
        $columns = [['title' => 'Rechnungsnummer', 'width' => 18], ['title' => 'Rechnungsdatum', 'type' => 'date', 'width' => 16], ['title' => 'Patient', 'width' => 28],
            ['title' => 'Geburtsdatum', 'type' => 'date', 'width' => 14], ['title' => 'Betrag', 'type' => 'money', 'width' => 12], ['title' => 'Bezahlt am', 'type' => 'date', 'width' => 13],
            ['title' => 'Uhrzeit', 'width' => 8], ['title' => 'Zahlungsart', 'width' => 14], ['title' => 'SumUp-Transaktion / Barnummer', 'width' => 38], ['title' => 'In T2med abgehakt am', 'type' => 'date', 'width' => 20],
            ['title' => 'Korrektur', 'width' => 34], ['title' => 'Notiz', 'width' => 30]];
        if ($includeTest) $columns[] = ['title' => 'Testzahlung', 'width' => 12];
        $xlsx = new Xlsx();
        $xlsx->sheet('T2med bezahlt', $columns, $rows, true);
        return $xlsx->build();
    }

    /** XLSX mit den Blättern Rechnungen, Positionen und Monatssummen. */
    public function workbook(int $year, int $month, bool $names = false, bool $includeTest = false): string {
        $invoices = $this->invoices($year, $month, $includeTest);
        $rates = [];
        foreach ($invoices as $invoice) foreach ($invoice['totals'] as $rate => $_) if ($rate > 0) $rates[$rate] = true;
        krsort($rates); $rates = array_keys($rates);

        $columns = [['title' => 'Rechnungsdatum', 'type' => 'date', 'width' => 16], ['title' => 'Rechnungsnummer', 'width' => 38], ['title' => 'Leistungsdatum', 'type' => 'date', 'width' => 16]];
        if ($names) $columns[] = ['title' => 'Patient', 'width' => 26];
        array_push($columns, ['title' => 'Art', 'width' => 24], ['title' => 'Brutto', 'type' => 'money', 'width' => 12]);
        foreach ($rates as $rate) array_push($columns, ['title' => 'Netto ' . Tax::percent($rate), 'type' => 'money', 'width' => 13], ['title' => 'MwSt. ' . Tax::percent($rate), 'type' => 'money', 'width' => 13]);
        array_push($columns, ['title' => 'Ohne MwSt.', 'type' => 'money', 'width' => 12], ['title' => 'Umsatzsteuer-Hinweis', 'width' => 24],
            ['title' => 'Zahlungsart', 'width' => 14], ['title' => 'Zahlungsreferenz', 'width' => 40]);
        if ($includeTest) $columns[] = ['title' => 'Testzahlung', 'width' => 12];
        $rows = [];
        foreach ($invoices as $invoice) {
            $row = [$invoice['date'], $invoice['number'], $invoice['service_date'] ?: null];
            if ($names) $row[] = $invoice['patient'];
            array_push($row, $invoice['kind'], $invoice['gross']);
            foreach ($rates as $rate) array_push($row, $invoice['totals'][$rate]['net'] ?? null, $invoice['totals'][$rate]['vat'] ?? null);
            array_push($row, $invoice['totals'][0]['gross'] ?? null, $invoice['small_business'] ? 'Kleinunternehmer § 19 UStG' : '', $invoice['cash'] ? 'Bar' : 'Kartenzahlung', $invoice['reference']);
            if ($includeTest) $row[] = $invoice['test'] ? 'ja' : '';
            $rows[] = $row;
        }
        $xlsx = new Xlsx();
        $xlsx->sheet('Rechnungen', $columns, $rows, true);

        $items = [];
        foreach ($invoices as $invoice) foreach ($invoice['items'] as $item) {
            $rate = (int)($item['vat_rate'] ?? 0); $gross = (int)$item['price_cents']; $vat = Tax::included($gross, $rate);
            $items[] = [$invoice['date'], $invoice['number'], (string)($item['goae_code'] ?? ''), (string)$item['label'],
                ($item['factor'] ?? '') !== '' ? str_replace('.', ',', (string)$item['factor']) : '', $gross, $rate, $vat, $gross - $vat];
        }
        $xlsx->sheet('Positionen', [['title' => 'Rechnungsdatum', 'type' => 'date', 'width' => 16], ['title' => 'Rechnungsnummer', 'width' => 38],
            ['title' => 'GOÄ', 'width' => 8], ['title' => 'Leistung', 'width' => 40], ['title' => 'Faktor', 'width' => 8],
            ['title' => 'Brutto', 'type' => 'money', 'width' => 12], ['title' => 'MwSt.-Satz', 'type' => 'percent', 'width' => 11],
            ['title' => 'MwSt.', 'type' => 'money', 'width' => 12], ['title' => 'Netto', 'type' => 'money', 'width' => 12]], $items, true);

        $sumColumns = [['title' => 'Monat', 'width' => 14], ['title' => 'Anzahl', 'type' => 'int', 'width' => 9], ['title' => 'Brutto', 'type' => 'money', 'width' => 13]];
        foreach ($rates as $rate) array_push($sumColumns, ['title' => 'Netto ' . Tax::percent($rate), 'type' => 'money', 'width' => 13], ['title' => 'MwSt. ' . Tax::percent($rate), 'type' => 'money', 'width' => 13]);
        array_push($sumColumns, ['title' => 'Ohne MwSt.', 'type' => 'money', 'width' => 13], ['title' => 'davon bar', 'type' => 'money', 'width' => 13], ['title' => 'davon Kartenzahlung', 'type' => 'money', 'width' => 19]);
        $months = [];
        foreach ($month ? [$month] : range(1, 12) as $m) $months[$m] = array_merge([self::MONTHS[$m] . ' ' . $year, 0, 0], array_fill(0, count($rates) * 2 + 3, 0));
        foreach ($invoices as $invoice) {
            $row = &$months[$invoice['month']];
            $row[1]++; $row[2] += $invoice['gross'];
            foreach ($rates as $i => $rate) { $row[3 + 2 * $i] += $invoice['totals'][$rate]['net'] ?? 0; $row[4 + 2 * $i] += $invoice['totals'][$rate]['vat'] ?? 0; }
            $row[3 + 2 * count($rates)] += $invoice['totals'][0]['gross'] ?? 0;
            $row[4 + 2 * count($rates) + ($invoice['cash'] ? 0 : 1)] += $invoice['gross'];
            unset($row);
        }
        $xlsx->sheet($month ? 'Summe' : 'Monatssummen', $sumColumns, array_values($months), true);
        return $xlsx->build();
    }
}
