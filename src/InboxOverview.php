<?php
declare(strict_types=1);
namespace KienzleSumup;

/** Eingegangene und abgelehnte T2med-Rechnungen für die Einstellungsseite (nur mit Admin-Sitzung). */
final class InboxOverview {
    /** Übersicht für die Einstellungsseite: eingegangene Rechnungen (ohne Klartext-Patientendaten) und abgelehnte Drucke. */
    public static function build(Database $db, Config $config): array {
        $limit = time() - $config->get('invoice_inbox', 'max_age_days', 14) * 86400;
        $items = [];
        foreach ($db->query('SELECT * FROM invoice_inbox ORDER BY received_at DESC LIMIT 30')->fetchAll() as $row) {
            $pay = $db->one("SELECT payment_status FROM payments WHERE t2med_invoice=? AND payment_status IN ('starting','pending','unknown','cancel_requested','successful') ORDER BY created_at DESC LIMIT 1", [$row['invoice_number']]);
            $status = $pay ? ($pay['payment_status'] === 'successful' ? 'bezahlt' : 'am Terminal') : ($row['superseded_at'] ? 'ersetzt' : ($row['received_at'] < $limit ? 'abgelaufen' : 'offen'));
            $items[] = ['id' => $row['id'], 'number' => $row['invoice_number'], 'invoice_date' => $row['invoice_date'], 'amount_cents' => (int)$row['amount_cents'],
                'received_at' => (int)$row['received_at'], 'status' => $status, 'patient' => $row['patient_name'], 'birthdate' => $row['patient_birthdate'],
                'deletable' => !in_array($status, ['bezahlt', 'am Terminal'], true),
                'letter' => Letterxpress::state($row), 'postable' => $status === 'offen' && $row['pdf'] !== null];
        }
        return ['enabled' => (bool)$config->get('invoice_inbox', 'enabled', false), 'letter_enabled' => (bool)$config->get('letterxpress', 'enabled', false), 'letter_mode' => (string)$config->get('letterxpress', 'mode', 'test'), 'max_age_days' => $config->get('invoice_inbox', 'max_age_days', 14),
            'items' => $items, 'rejected' => (new InboxLog($config->get('app', 'state_dir')))->recent(10)];
    }

    /** Eingegangene Rechnung löschen. Bezahlte oder gerade kassierte Rechnungen bleiben erhalten. */
    public static function delete(Database $db, string $id): void {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new Problem('Ungültige Auswahl.', 422);
        $row = $db->one('SELECT invoice_number FROM invoice_inbox WHERE id=?', [$id]);
        if (!$row) throw new Problem('Diese Rechnung gibt es nicht (mehr).', 404);
        $busy = $db->one("SELECT 1 AS x FROM payments WHERE (inbox_id=? OR t2med_invoice=?) AND payment_status IN ('starting','pending','unknown','cancel_requested','successful') LIMIT 1", [$id, $row['invoice_number']]);
        if ($busy) throw new Problem('Bezahlte oder gerade kassierte Rechnungen bleiben erhalten.', 409);
        $db->query('DELETE FROM invoice_inbox WHERE id=?', [$id]);
    }
}
