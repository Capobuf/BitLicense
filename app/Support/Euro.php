<?php

namespace App\Support;

use InvalidArgumentException;

class Euro
{
    public const PATTERN = '/^\d{1,9}(?:[.,]\d{1,2})?$/D';

    public static function toCents(string $amount): int
    {
        if (! preg_match(self::PATTERN, $amount)) {
            throw new InvalidArgumentException('Inserisci un importo non negativo, senza separatori delle migliaia e con al massimo due decimali.');
        }

        [$units, $decimals] = array_pad(explode('.', str_replace(',', '.', $amount)), 2, '');

        return ((int) $units * 100) + (int) str_pad($decimals, 2, '0');
    }

    public static function fromCents(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('L’importo non può essere negativo.');
        }

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
