<?php

namespace Tests\Unit;

use App\Support\Euro;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EuroTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_euro_conversion_keeps_cents_exact_and_formats_two_decimals(string $input, int $cents, string $formatted): void
    {
        $this->assertSame($cents, Euro::toCents($input));
        $this->assertSame($formatted, Euro::fromCents($cents));
    }

    public static function validAmounts(): array
    {
        return [
            ['0.00', 0, '0.00'], ['0.10', 10, '0.10'], ['79,90', 7990, '79.90'],
            ['1.2', 120, '1.20'], ['42', 4200, '42.00'], ['999999999.99', 99999999999, '999999999.99'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_euro_rejects_invalid_prices_before_conversion(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Euro::toCents($amount);
    }

    public static function invalidAmounts(): array
    {
        return [['-1'], ['1e2'], ['1.000,00'], ['1,000'], ['0.001'], [' 1.00'], ["1.00\n"], ['1000000000.00'], ['']];
    }

    public function test_negative_cents_cannot_be_formatted_as_a_price(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Euro::fromCents(-1);
    }
}
