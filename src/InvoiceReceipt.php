<?php
declare(strict_types=1);
namespace KienzleSumup;

/** Lokaler Bon: keinerlei Abruf oder Übertragung medizinischer Daten an SumUp. */
final class InvoiceReceipt {
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
        $rule();
        $text($invoice['title'], true);
        $text('Rechnungsnummer (SumUp):'); $text($invoice['number'], false, 20);
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
            if (!empty($item['reason'])) { $text('Begründung:', false, 20); $text($item['reason'], false, 20); }
            $y += 14;
        }
        $rule(); $amount('GESAMT', $invoice['total_cents'], true);
        $rule();
        if ($sumupReceipt === null) {
            $text('ZAHLUNGSBESTÄTIGUNG', true);
            $amount('Bezahlt per Karte', $invoice['total_cents'], true);
            $text('Status: ERFOLGREICH'); $text($payment['paid_date']);
            $y += 8; $text('Zahlungsreferenz:', false, 20); $text($payment['reference'], false, 20);
            $y += 8; $text('SumUp-Transaktion:', false, 20); $text($payment['transaction_id'], false, 20);
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
}
