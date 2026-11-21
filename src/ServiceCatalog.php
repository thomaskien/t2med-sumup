<?php
declare(strict_types=1);
namespace KienzleSumup;

final class ServiceCatalog {
    public const FEES = ['standard' => ['threshold' => 230, 'max' => 350], 'technical' => ['threshold' => 180, 'max' => 250], 'lab' => ['threshold' => 115, 'max' => 130]];
    /** Faktor als Text („2.3“, „2.332“); bis zu 4 Nachkommastellen, damit gewünschte Endbeträge exakt einstellbar sind. */
    public static function factor(string $input, string $type): string {
        if (!isset(self::FEES[$type])) throw new Problem('Ungültiger Gebührenrahmen.');
        $input = str_replace(',', '.', $input);
        if (!preg_match('/^([1-9])(?:\.(\d{1,4}))?$/D', $input, $m)) throw new Problem('Bitte einen gültigen GOÄ-Faktor eingeben (höchstens 4 Nachkommastellen).');
        $units = self::units($input);
        if ($units > self::FEES[$type]['max'] * 100) throw new Problem('Der Faktor überschreitet den gewählten Gebührenrahmen.');
        return self::text($units);
    }
    /** Faktor in Zehntausendsteln („2.3“ → 23000). */
    public static function units(string $factor): int {
        [$whole, $fraction] = array_pad(explode('.', $factor, 2), 2, '');
        return (int)$whole * 10000 + (int)str_pad(substr($fraction, 0, 4), 4, '0');
    }
    /** Zehntausendstel → Text ohne überflüssige Nullen (23000 → „2.3“, 10000 → „1“). */
    public static function text(int $units): string {
        return rtrim(rtrim(number_format($units / 10000, 4, '.', ''), '0'), '.');
    }
    /**
     * GOÄ-Rechner: Faktor, dessen Gebühr (Einfachsatz × Faktor, auf Cent gerundet) genau dem gewünschten
     * Endbetrag entspricht. Es werden so wenige Nachkommastellen wie nötig verwendet: zuerst 2, dann 3, dann 4.
     * Geht es auch mit 4 Stellen nicht genau (sehr hoher Einfachsatz), gilt der nächstliegende Betrag.
     * Mindestens 1,0-fach.
     */
    public static function bestFactor(int $baseCents, int $targetCents, string $type): array {
        if (!isset(self::FEES[$type])) throw new Problem('Ungültiger Gebührenrahmen.');
        if ($baseCents <= 0 || $targetCents <= 0) throw new Problem('Einfachsatz und Endbetrag müssen größer als 0 sein.');
        $ideal = $targetCents * 10000 / $baseCents;               // exakter Faktor (Zehntausendstel, gebrochen)
        $best = null;
        foreach ([100, 10, 1] as $step) {                          // 2, 3, 4 Nachkommastellen
            $center = (int)(round($ideal / $step) * $step);
            $exact = null;
            for ($u = $center - 4 * $step; $u <= $center + 4 * $step; $u += $step) {
                $unit = max(10000, $u);
                $price = self::feeUnits($baseCents, $unit);
                if ($price === $targetCents && ($exact === null || abs($unit - $ideal) < abs($exact[0] - $ideal))) $exact = [$unit, $price];
                if ($best === null || abs($price - $targetCents) < abs($best[1] - $targetCents)
                    || (abs($price - $targetCents) === abs($best[1] - $targetCents) && abs($unit - $ideal) < abs($best[0] - $ideal))) $best = [$unit, $price];
            }
            if ($exact !== null) { $best = $exact; break; }
        }
        [$units, $price] = $best;
        $max = self::FEES[$type]['max'] * 100;
        return ['factor' => self::text($units), 'price_cents' => $price, 'exact' => $price === $targetCents,
            'over_threshold' => $units > self::FEES[$type]['threshold'] * 100, 'over_max' => $units > $max, 'max_price_cents' => self::feeUnits($baseCents, $max)];
    }
    /** Gebühr = Einfachsatz × Faktor (Zehntausendstel), kaufmännisch auf Cent gerundet. */
    public static function feeUnits(int $baseCents, int $units): int {
        return intdiv($baseCents * $units + 5000, 10000);
    }
    /** Wie feeUnits, mit Faktor in Hundertsteln (bisherige Schreibweise). */
    public static function fee(int $baseCents, int $hundredths): int {
        return self::feeUnits($baseCents, $hundredths * 100);
    }
    public static function needsReason(array $service): bool {
        return ($service['goae_code'] ?? '') !== '' && self::units((string)$service['factor']) > self::FEES[$service['fee_type']]['threshold'] * 100;
    }
    public static function ids(mixed $ids): array {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100) throw new Problem('Ungültige Leistungsauswahl.');
        foreach ($ids as $id) if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new Problem('Ungültige Leistung.');
        if (count(array_unique($ids)) !== count($ids)) throw new Problem('Eine Auswahl wurde mehrfach übergeben.');
        sort($ids); return $ids;
    }
}
