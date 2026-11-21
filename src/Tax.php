<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Umsatzsteuer für Rechnungen aus dem Rechnungstool. Preise im Leistungskatalog sind
 * Endpreise (brutto); bei Positionen „mit MwSt.“ wird die enthaltene Steuer ausgewiesen.
 * Sätze in Hundertstelprozent (1900 = 19 %), Beträge in Cent.
 */
final class Tax {
    /** Aktuelle Einstellungen; bei Kleinunternehmerregelung immer ohne Steuer. */
    public static function settings(Config $config): array {
        $small = $config->get('tax', 'small_business', false) === true;
        $rate = $config->get('tax', 'rate', 1900);
        return ['small_business' => $small, 'rate' => is_int($rate) && $rate >= 0 && $rate <= 3000 ? $rate : 1900,
            'tax_id' => (string)$config->get('tax', 'tax_id', ''),
            'exempt_note' => (string)$config->get('tax', 'exempt_note', ''),
            'small_business_note' => (string)$config->get('tax', 'small_business_note', 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.'),
            'manual_vat' => $config->get('tax', 'manual_vat', false) === true];
    }

    /** Steuersatz einer Position zum Zeitpunkt der Zahlung (0 = ohne MwSt.). */
    public static function rateFor(array $settings, bool $withVat): int {
        return !$settings['small_business'] && $withVat ? $settings['rate'] : 0;
    }

    /** Im Bruttobetrag enthaltene Steuer, kaufmännisch gerundet. */
    public static function included(int $gross, int $rate): int {
        return $rate > 0 ? intdiv($gross * $rate * 2 + (10000 + $rate), 2 * (10000 + $rate)) : 0;
    }

    /**
     * Summen je Steuersatz: [Satz => ['gross','net','vat']]; Satz 0 = ohne MwSt.
     * Die Steuer wird je Satz auf die Summe gerechnet (nicht je Position), wie üblich auf Rechnungen.
     */
    public static function totals(array $items): array {
        $gross = [];
        foreach ($items as $item) {
            $rate = (int)($item['vat_rate'] ?? 0);
            $gross[$rate] = ($gross[$rate] ?? 0) + (int)$item['price_cents'];
        }
        ksort($gross);
        $out = [];
        foreach ($gross as $rate => $sum) {
            $vat = self::included($sum, $rate);
            $out[$rate] = ['gross' => $sum, 'net' => $sum - $vat, 'vat' => $vat];
        }
        return $out;
    }

    public static function percent(int $rate): string {
        return rtrim(rtrim(number_format($rate / 100, 2, ',', ''), '0'), ',') . ' %';
    }
}
