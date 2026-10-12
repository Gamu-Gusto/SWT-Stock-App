<?php

namespace App\Services;

use App\Support\AppSettings;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Totals for sales and quotations using exact cent arithmetic.
 *
 * Line total = unit price × quantity − line discount. When the business is VAT registered, VAT is
 * extracted from (prices include VAT) or added to (prices exclude VAT) the discounted total.
 */
class PricingCalculator
{
    public function __construct(protected AppSettings $settings) {}

    /**
     * @param  list<array{unit_price:string|int|float, quantity:int, discount?:string|int|float|null}>  $lines
     * @return array{lines:list<array{gross:string, discount:string, line_total:string}>, subtotal:string, discount_total:string, vat_registered:bool, prices_include_vat:bool, vat_rate:string, vat_amount:string, total:string}
     */
    public function calculate(array $lines): array
    {
        $subtotal = 0;
        $discounts = 0;
        $out = [];
        foreach ($lines as $line) {
            $qty = (int) $line['quantity'];
            if ($qty < 1) {
                throw new InvalidArgumentException('Quantity must be at least 1.');
            }
            $gross = Money::toCents($line['unit_price']) * $qty;
            $discount = Money::toCents($line['discount'] ?? 0);
            if ($discount < 0 || $discount > $gross) {
                throw new InvalidArgumentException('A line discount cannot be negative or exceed the line amount.');
            }
            $subtotal += $gross;
            $discounts += $discount;
            $out[] = ['gross' => Money::fromCents($gross), 'discount' => Money::fromCents($discount), 'line_total' => Money::fromCents($gross - $discount)];
        }

        $registered = (bool) $this->settings->get('vat_registered');
        $inclusive = (bool) $this->settings->get('prices_include_vat');
        $rate = $registered ? (string) $this->settings->get('vat_rate') : '0.00';
        $vat = Money::vat($subtotal - $discounts, $rate, $inclusive);

        return [
            'lines' => $out,
            'subtotal' => Money::fromCents($subtotal),
            'discount_total' => Money::fromCents($discounts),
            'vat_registered' => $registered,
            'prices_include_vat' => $inclusive,
            'vat_rate' => Money::normalize($rate),
            'vat_amount' => Money::fromCents($vat['vat']),
            'total' => Money::fromCents($vat['gross']),
        ];
    }
}
