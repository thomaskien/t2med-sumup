<?php
declare(strict_types=1);
namespace KienzleSumup;

/** Lokaler Bon: keinerlei Abruf oder Übertragung medizinischer Daten an SumUp. */
final class InvoiceReceipt {
    /**
     * Setzt den Beleg (52,5 mm breit) lesbar vergrößert auf eine A4-Seite für den Praxisdrucker.
     * Sehr lange Belege werden so verkleinert, dass sie auf eine Seite passen.
     */
    public static function a4(string $svg): string {
        if (!preg_match('/<svg\b[^>]*\bviewBox="0 0 (\d+) (\d+)"/', $svg, $m)) throw new Problem('Beleg kann nicht für A4 aufbereitet werden.', 500);
        [$w, $h] = [(int)$m[1], (int)$m[2]];
        $width = 90.0; $height = $width * $h / $w;
        if ($height > 267) { $height = 267.0; $width = $height * $w / $h; }
        $x = (210 - $width) / 2;
        $inner = preg_replace('/<svg\b([^>]*?)\s(width|height)="[^"]*"([^>]*?)\s(width|height)="[^"]*"/', '<svg$1$3', $svg, 1);
        $inner = preg_replace('/<svg\b/', sprintf('<svg x="%.2f" y="15" width="%.2f" height="%.2f"', $x, $width, $height), $inner, 1);
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="210mm" height="297mm" viewBox="0 0 210 297">'
            . '<rect width="210" height="297" fill="white"/>' . $inner . '</svg>';
    }
    public static function source(array $invoice, array $payment, ?string $sumupReceipt = null): string {
        if ($payment['status'] !== 'successful') throw new Problem('Ein bezahlter Beleg benötigt eine bestätigte Zahlung.', 409);
        $parts = []; $y = 28;
        $text = static function (string $value, bool $bold = false, int $size = 22) use (&$parts, &$y): void {
            $value = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value);
            $width = (int)floor(396 / ($size * 0.61));
            while ($value !== '') {
                $line = mb_substr($value, 0, $width);
                if (mb_strlen($value) > $width && ($space = mb_strrpos($line, ' ')) !== false && $space > (int)($width / 2)) $line = mb_substr($line, 0, $space);
                $value = ltrim(mb_substr($value, mb_strlen($line)));
                $parts[] = '<text x="12" y="'.$y.'" font-size="'.$size.'" font-weight="'.($bold ? 'bold' : 'normal').'">'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</text>';
                $y += $size + 8;
            }
        };
        $rule = static function () use (&$parts, &$y): void {
            $y += 4; $parts[] = '<path d="M12 '.$y.' H408" stroke="black" stroke-width="1"/>'; $y += 34;
        };
        $amount = static function (string $label, int $cents, bool $bold = false) use (&$parts, &$y, $text): void {
            $money = Money::format($cents);
            if (mb_strlen($label) * 22 * 0.61 + mb_strlen($money) * 24 * 0.61 + 16 > 396) $text($label, $bold);
            else $parts[] = '<text x="12" y="'.$y.'" font-size="22" font-weight="'.($bold ? 'bold' : 'normal').'">'.htmlspecialchars($label, ENT_XML1, 'UTF-8').'</text>';
            $parts[] = '<text x="408" y="'.$y.'" text-anchor="end" font-size="24" font-weight="'.($bold ? 'bold' : 'normal').'">'.htmlspecialchars(Money::format($cents), ENT_XML1, 'UTF-8').'</text>';
            $y += 36;
        };
        $date = static fn(string $value): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) ? "$m[3].$m[2].$m[1]" : $value;
        if ($payment['mock']) { $text('TESTBELEG', true); $text('KEINE ECHTE ZAHLUNG'); $rule(); }
        $text($invoice['practice']['name'], true, 24);
        foreach (['street','city','contact'] as $key) $text($invoice['practice'][$key] ?? '');
        $tax = $invoice['tax'] ?? null;   // ältere Rechnungen ohne Steuerangaben bleiben unverändert
        if ($tax && ($tax['tax_id'] ?? '') !== '') $text('St.-Nr./USt-IdNr.: ' . $tax['tax_id'], false, 20);
        $rule();
        $text($invoice['title'], true);
        $cash = ($payment['method'] ?? 'card') === 'cash';
        $text($cash ? 'Rechnungsnummer:' : 'Rechnungsnummer (SumUp):'); $text($invoice['number'], false, 20);
        $text('Rechnungsdatum:'); $text($date($invoice['issued_date']));
        $y += 10;
        $text('Patient:', true); $text($invoice['patient']['name']);
        if ($invoice['patient']['birthdate'] !== '') $text('Geb.: '.$date($invoice['patient']['birthdate']));
        foreach ($invoice['patient']['address'] as $line) $text($line);
        $y += 10;
        $text('Leistungsdatum:'); $text($date($invoice['service_date']));
        $rule();
        foreach ($invoice['items'] as $item) {
            if (($item['goae_code'] ?? '') !== '') $text('GOÄ '.$item['goae_code'], true);
            $text($item['label']);
            $amount(($item['factor'] ?? '') !== '' ? 'Faktor '.str_replace('.', ',', $item['factor']) : 'Betrag', $item['price_cents']);
            if (!empty($item['on_request'])) $text('Auf Verlangen', false, 20);
            if ((int)($item['vat_rate'] ?? 0) > 0) $text('inkl. ' . Tax::percent((int)$item['vat_rate']) . ' MwSt.', false, 20);
            if (!empty($item['reason'])) { $text('Begründung:', false, 20); $text($item['reason'], false, 20); }
            $y += 14;
        }
        $rule(); $amount('GESAMT', $invoice['total_cents'], true);
        if ($tax) {
            $totals = Tax::totals($invoice['items']);
            if (array_filter(array_keys($totals))) {
                foreach ($totals as $rate => $sum) {
                    if ($rate === 0) { $amount('Ohne MwSt.', $sum['gross']); continue; }
                    $amount('Netto ' . Tax::percent($rate), $sum['net']);
                    $amount('MwSt. ' . Tax::percent($rate), $sum['vat']);
                }
            }
            $note = !empty($tax['small_business']) ? ($tax['small_business_note'] ?? '') : (isset($totals[0]) ? ($tax['exempt_note'] ?? '') : '');
            if ($note !== '') { $y += 6; $text($note, false, 20); }
        }
        $rule();
        if ($sumupReceipt === null) {
            $text('ZAHLUNGSBESTÄTIGUNG', true);
            $amount($cash ? 'Bar bezahlt' : 'Bezahlt per Karte', $invoice['total_cents'], true);
            $text($cash ? 'Betrag bar erhalten' : 'Status: ERFOLGREICH'); $text($payment['paid_date']);
            if (!$cash) {
                $y += 8; $text('Zahlungsreferenz:', false, 20); $text($payment['reference'], false, 20);
                $y += 8; $text('SumUp-Transaktion:', false, 20); $text($payment['transaction_id'], false, 20);
            }
            $rule();
        }
        $text('Vielen Dank.');
        if ($sumupReceipt !== null) {
            // Nur das gerenderte Bild einbetten, kein fremdes SVG in unser Dokument übernehmen.
            $png = ReceiptPdf::raster($sumupReceipt, 420);
            $size = getimagesizefromstring($png);
            if (!$size || $size[0] !== 420 || $size[1] < 1 || $size[1] > 20000) throw new Problem('Ungültige Belegabmessungen.', 502);
            $parts[] = '<image x="0" y="'.$y.'" width="420" height="'.$size[1].'" xlink:href="data:image/png;base64,'.base64_encode($png).'"/>';
            $y += $size[1];
        }
        $height = $y + 4;
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="52.5mm" height="'.($height / 8).'mm" viewBox="0 0 420 '.$height.'"><rect width="420" height="'.$height.'" fill="white"/><g fill="black" font-family="DejaVu Sans Mono, monospace">'.implode('', $parts).'</g></svg>';
    }
    /** Quittung für eine bar bezahlte T2med-Rechnung: Betrag, Name, Datum, Rechnungsnummer, „bar erhalten“. */
    public static function quittung(array $d): string {
        $parts = []; $y = 28;
        $text = static function (string $value, bool $bold = false, int $size = 22) use (&$parts, &$y): void {
            $value = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value);
            $width = (int)floor(396 / ($size * 0.61));
            while ($value !== '') {
                $line = mb_substr($value, 0, $width);
                if (mb_strlen($value) > $width && ($space = mb_strrpos($line, ' ')) !== false && $space > (int)($width / 2)) $line = mb_substr($line, 0, $space);
                $value = ltrim(mb_substr($value, mb_strlen($line)));
                $parts[] = '<text x="12" y="'.$y.'" font-size="'.$size.'" font-weight="'.($bold ? 'bold' : 'normal').'">'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</text>';
                $y += $size + 8;
            }
        };
        $rule = static function () use (&$parts, &$y): void { $y += 4; $parts[] = '<path d="M12 '.$y.' H408" stroke="black" stroke-width="1"/>'; $y += 34; };
        if (!empty($d['mock'])) { $text('TESTBELEG', true); $text('KEINE ECHTE ZAHLUNG'); $rule(); }
        if (($d['practice']['name'] ?? '') !== '') {
            $text($d['practice']['name'], true, 24);
            foreach (['street', 'city', 'contact'] as $key) $text($d['practice'][$key] ?? '');
            $rule();
        }
        $text('QUITTUNG', true, 28); $y += 6;
        $text('Rechnungsnummer:'); $text($d['number'], true, 22);
        if (($d['invoice_date'] ?? '') !== '') { $y += 6; $text('Rechnungsdatum:'); $text($d['invoice_date']); }
        $y += 6; $text('Name:'); $text($d['patient'], true);
        $y += 6; $text('Datum:'); $text($d['paid_date']);
        $rule();
        $text('Rechnungsbetrag:');
        $parts[] = '<text x="408" y="'.$y.'" text-anchor="end" font-size="30" font-weight="bold">'.htmlspecialchars(Money::format($d['amount_cents']), ENT_XML1, 'UTF-8').'</text>'; $y += 44;
        $text('Betrag bar erhalten.', true);
        $rule();
        $text('Vielen Dank.');
        $height = $y + 4;
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="52.5mm" height="'.($height / 8).'mm" viewBox="0 0 420 '.$height.'"><rect width="420" height="'.$height.'" fill="white"/><g fill="black" font-family="DejaVu Sans Mono, monospace">'.implode('', $parts).'</g></svg>';
    }
}
