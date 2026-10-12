<?php

namespace App\Services;

use App\Models\TyreUnit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Inventory valuation.
 *
 * Method (documented in docs/INVENTORY_RULES.md):
 *  - New tyres (quantity tracked): FIFO. Value = Σ remaining quantity × unit cost of each cost layer (stock_lots).
 *  - Used tyres (individually tracked): specific identification. Value = Σ cost price of each tyre unit physically in stock.
 */
class ValuationService
{
    public function totalValue(): string
    {
        return Money::add($this->lotValueQuery()->value('value') ?? '0', $this->unitValueQuery()->value('value') ?? '0');
    }

    /** Per-product valuation rows. @return array<int, array{qty:int, value:string}> keyed by product id */
    public function byProduct(): array
    {
        $out = [];
        foreach (DB::table('stock_lots')->where('qty_remaining', '>', 0)
            ->selectRaw('product_id, SUM(qty_remaining) as qty, SUM(qty_remaining * unit_cost) as value')->groupBy('product_id')->get() as $r) {
            $out[$r->product_id] = ['qty' => (int) $r->qty, 'value' => Money::normalize((string) $r->value)];
        }
        foreach (DB::table('tyre_units')->whereIn('status', TyreUnit::PHYSICAL)
            ->selectRaw('product_id, COUNT(*) as qty, SUM(cost_price) as value')->groupBy('product_id')->get() as $r) {
            $out[$r->product_id] = ['qty' => (int) $r->qty, 'value' => Money::normalize((string) $r->value)];
        }

        return $out;
    }

    protected function lotValueQuery()
    {
        return DB::table('stock_lots')->where('qty_remaining', '>', 0)->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as value');
    }

    protected function unitValueQuery()
    {
        return DB::table('tyre_units')->whereIn('status', TyreUnit::PHYSICAL)->selectRaw('COALESCE(SUM(cost_price), 0) as value');
    }
}
