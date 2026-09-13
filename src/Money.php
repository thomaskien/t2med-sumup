<?php
declare(strict_types=1);
namespace KienzleSumup;

final class Money {
    public static function cents(mixed $input, int $max): int {
        if (!is_string($input) || !preg_match('/^(0|[1-9][0-9]{0,6})(?:[,.]([0-9]{1,2}))?$/D', trim($input), $m)) {
            throw new Problem('Bitte einen Betrag mit höchstens zwei Nachkommastellen eingeben.');
        }
        $cents = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        if ($cents < 1 || $cents > $max) throw new Problem('Der Betrag liegt außerhalb des erlaubten Bereichs.');
        return $cents;
    }
    public static function format(int $cents): string { return number_format($cents / 100, 2, ',', '.') . ' €'; }
}
