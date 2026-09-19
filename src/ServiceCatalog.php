<?php
declare(strict_types=1);
namespace KienzleSumup;

final class ServiceCatalog {
    public const FEES = ['standard' => ['threshold' => 230, 'max' => 350], 'technical' => ['threshold' => 180, 'max' => 250], 'lab' => ['threshold' => 115, 'max' => 130]];
    public static function factor(string $input, string $type): string {
        if (!isset(self::FEES[$type])) throw new Problem('Ungültiger Gebührenrahmen.');
        $input = str_replace(',', '.', $input);
        if (!preg_match('/^([1-9])(?:\.(\d{1,2}))?$/D', $input, $m)) throw new Problem('Bitte einen gültigen GOÄ-Faktor eingeben.');
        $hundredths = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        if ($hundredths > self::FEES[$type]['max']) throw new Problem('Der Faktor überschreitet den gewählten Gebührenrahmen.');
        return rtrim(rtrim(number_format($hundredths / 100, 2, '.', ''), '0'), '.');
    }
    public static function needsReason(array $service): bool {
        return ($service['goae_code'] ?? '') !== '' && (int)round((float)$service['factor'] * 100) > self::FEES[$service['fee_type']]['threshold'];
    }
    public static function ids(mixed $ids): array {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100) throw new Problem('Ungültige Leistungsauswahl.');
        foreach ($ids as $id) if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new Problem('Ungültige Leistung.');
        if (count(array_unique($ids)) !== count($ids)) throw new Problem('Eine Auswahl wurde mehrfach übergeben.');
        sort($ids); return $ids;
    }
}
