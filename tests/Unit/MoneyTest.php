<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_amounts_are_parsed_exactly_without_floating_point_errors(): void
    {
        $this->assertSame(159999, Money::toCents('1599.99'));
        $this->assertSame(10, Money::toCents('0.1'));
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('5400.00', Money::mul('1350.00', 4));
        $this->assertSame('-1.50', Money::sub('1.00', '2.50'));
        $this->assertSame(1, Money::toCents('0.005'));
        $this->assertSame('1234567.89', Money::normalize('1234567.89'));
    }

    public function test_vat_inclusive_extraction_at_fifteen_percent(): void
    {
        // R 1 150.00 incl. 15% VAT = R 1 000.00 net + R 150.00 VAT
        $this->assertSame(['vat' => 15000, 'net' => 100000, 'gross' => 115000], Money::vat(115000, '15.00', true));
        // R 5 400.00 incl. VAT → VAT = 5400 × 15/115 = 704.35 (rounded half up)
        $this->assertSame(70435, Money::vat(540000, '15', true)['vat']);
    }

    public function test_vat_exclusive_addition_and_zero_rate(): void
    {
        $this->assertSame(['vat' => 15000, 'net' => 100000, 'gross' => 115000], Money::vat(100000, '15.00', false));
        $this->assertSame(['vat' => 0, 'net' => 100000, 'gross' => 100000], Money::vat(100000, '0', true));
    }

    public function test_rounding_is_half_away_from_zero(): void
    {
        $this->assertSame(3, Money::divRound(5, 2));
        $this->assertSame(-3, Money::divRound(-5, 2));
        $this->assertSame(2, Money::divRound(7, 4));
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::toCents('12,50abc');
    }
}
