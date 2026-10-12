<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic using integer cents. Never uses floating point for calculations.
 * Database columns are DECIMAL(…,2); values are exchanged as strings like "1599.00".
 */
final class Money
{
    /** Parse a decimal amount ("1 599,50" is NOT accepted; use "1599.50") into integer cents, rounding half-up beyond 2dp. */
    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }
        if (is_int($amount)) {
            return $amount * 100;
        }
        if (is_float($amount)) {
            // Floats only arrive from loosely typed input; format to a fixed string before exact parsing.
            $amount = number_format($amount, 4, '.', '');
        }
        $amount = trim(str_replace([' ', 'R'], '', $amount));
        if (! preg_match('/^(-)?(\d*)(?:\.(\d*))?$/', $amount, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }
        $negative = $m[1] === '-';
        $whole = $m[2] === '' ? 0 : (int) $m[2];
        $fraction = str_pad($m[3] ?? '', 3, '0');
        $cents = $whole * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function normalize(string|int|float|null $amount): string
    {
        return self::fromCents(self::toCents($amount));
    }

    /** South African display format: "R 13 500,00". */
    public static function format(string|int|float|null $amount): string
    {
        $cents = self::toCents($amount);
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.'R '.number_format(intdiv($abs, 100), 0, '', ' ').','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function add(string|int|float|null ...$amounts): string
    {
        return self::fromCents(array_sum(array_map(self::toCents(...), $amounts)));
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::fromCents(self::toCents($a) - self::toCents($b));
    }

    public static function mul(string|int|float|null $amount, int $quantity): string
    {
        return self::fromCents(self::toCents($amount) * $quantity);
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return self::toCents($a) <=> self::toCents($b);
    }

    /** Integer division of cents rounding half away from zero. */
    public static function divRound(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }
        $sign = (($numerator < 0) xor ($denominator < 0)) ? -1 : 1;
        $n = abs($numerator);
        $d = abs($denominator);
        $q = intdiv($n, $d);
        if (($n % $d) * 2 >= $d) {
            $q++;
        }

        return $sign * $q;
    }

    /** VAT rate "15.00" → 1500 basis points. */
    public static function rateToBasisPoints(string|int|float|null $rate): int
    {
        return self::toCents($rate);
    }

    /**
     * Calculate VAT for a total.
     *
     * @return array{vat:int, net:int, gross:int} amounts in cents
     */
    public static function vat(int $amountCents, string|int|float|null $rate, bool $inclusive): array
    {
        $bp = self::rateToBasisPoints($rate);
        if ($bp <= 0) {
            return ['vat' => 0, 'net' => $amountCents, 'gross' => $amountCents];
        }
        if ($inclusive) {
            $vat = self::divRound($amountCents * $bp, 10000 + $bp);

            return ['vat' => $vat, 'net' => $amountCents - $vat, 'gross' => $amountCents];
        }
        $vat = self::divRound($amountCents * $bp, 10000);

        return ['vat' => $vat, 'net' => $amountCents, 'gross' => $amountCents + $vat];
    }
}
