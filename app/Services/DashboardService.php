<?php

namespace App\Services;

use App\Models\AdjustmentRequest;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard figures. Everything is calculated live from the database.
 */
class DashboardService
{
    public function __construct(protected ValuationService $valuation) {}

    public function build(User $user): array
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $active = Product::query()->where('status', Product::STATUS_ACTIVE);

        $stock = (clone $active)->selectRaw('
            COUNT(*) as variants,
            COALESCE(SUM(qty_on_hand + qty_unsellable), 0) as physical,
            COALESCE(SUM(qty_on_hand - qty_reserved), 0) as available,
            COALESCE(SUM(qty_reserved), 0) as reserved,
            COALESCE(SUM(CASE WHEN qty_on_hand - qty_reserved <= 0 THEN 1 ELSE 0 END), 0) as out_of_stock,
            COALESCE(SUM(CASE WHEN qty_on_hand - qty_reserved > 0 AND min_stock_level > 0 AND qty_on_hand - qty_reserved <= min_stock_level THEN 1 ELSE 0 END), 0) as low_stock
        ')->first();

        $soldQty = fn (CarbonImmutable $from) => (int) DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.item_type', 'product')->whereIn('sales.status', ['completed', 'partially_returned', 'returned'])
            ->where('sales.completed_at', '>=', $from)->sum('sale_items.quantity');

        $stats = [
            'total_physical' => (int) $stock->physical,
            'total_variants' => (int) $stock->variants,
            'total_available' => (int) $stock->available,
            'total_reserved' => (int) $stock->reserved,
            'out_of_stock_variants' => (int) $stock->out_of_stock,
            'low_stock_variants' => (int) $stock->low_stock,
            'received_today' => (int) StockMovement::query()->where('type', StockMovement::RECEIVED)->where('created_at', '>=', $today)->sum('quantity_change'),
            'sold_today' => $soldQty($today),
            'sold_month' => $soldQty($monthStart),
            'pending_stocktakes' => Stocktake::query()->whereIn('status', [Stocktake::DRAFT, Stocktake::IN_PROGRESS])->count(),
            'pending_approvals' => Stocktake::query()->whereIn('status', [Stocktake::SUBMITTED, Stocktake::UNDER_REVIEW])->count()
                + AdjustmentRequest::query()->where('status', 'pending')->count(),
            'active_reservations' => Reservation::query()->where('status', Reservation::ACTIVE)->count(),
        ];

        $data = [
            'stats' => $stats,
            'charts' => [
                'sales_over_time' => $this->salesOverTime($today->subDays(29), $user->hasPermission('dashboard.financial')),
                'stock_by_brand' => $this->stockByBrand(),
                'stock_by_size' => $this->stockBySize(),
                'new_vs_used' => $this->newVsUsed(),
                'movement_trend' => $this->movementTrend($today->subDays(29)),
            ],
            'my_stocktakes' => Stocktake::query()->whereIn('status', [Stocktake::IN_PROGRESS])
                ->whereHas('assignees', fn ($q) => $q->whereKey($user->id))
                ->get(['id', 'stocktake_no', 'title', 'status', 'started_at']),
            'financial' => null,
        ];

        if ($user->hasPermission('dashboard.financial')) {
            $data['financial'] = [
                'revenue_today' => $this->revenue($today),
                'revenue_month' => $this->revenue($monthStart),
                'gross_profit_month' => $user->hasPermission('financials.view') ? $this->grossProfit($monthStart) : null,
                'inventory_cost_value' => $user->hasPermission('financials.view') ? $this->valuation->totalValue() : null,
                'top_sizes' => $this->topSold('sale_items.size_display', $today->subDays(29)),
                'top_brands' => $this->topSold('sale_items.brand_name', $today->subDays(29)),
            ];
        }

        return $data;
    }

    protected function revenue(CarbonImmutable $from): string
    {
        $cents = DB::table('sales')->whereIn('status', ['completed', 'partially_returned', 'returned'])
            ->where('completed_at', '>=', $from)->sum('total');
        $refunds = DB::table('sale_returns')->where('created_at', '>=', $from)->sum('refund_amount');

        return Money::sub(Money::normalize((string) $cents), Money::normalize((string) $refunds));
    }

    /** Estimated gross profit: net sales (excl. VAT) − FIFO cost of tyres sold, for sales in the period. */
    protected function grossProfit(CarbonImmutable $from): string
    {
        $row = DB::table('sales')->whereIn('status', ['completed', 'partially_returned', 'returned'])
            ->where('completed_at', '>=', $from)
            ->selectRaw('COALESCE(SUM(total),0) as total, COALESCE(SUM(vat_amount),0) as vat, COALESCE(SUM(cost_total),0) as cost')->first();

        return Money::sub(Money::sub((string) $row->total, (string) $row->vat), (string) $row->cost);
    }

    protected function salesOverTime(CarbonImmutable $from, bool $withRevenue): array
    {
        $rows = DB::table('sales')->whereIn('status', ['completed', 'partially_returned', 'returned'])->where('completed_at', '>=', $from)
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as sales, COALESCE(SUM(total),0) as revenue')->groupBy('day')->get()->keyBy('day');
        $qty = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sale_items.item_type', 'product')
            ->whereIn('sales.status', ['completed', 'partially_returned', 'returned'])->where('sales.completed_at', '>=', $from)
            ->selectRaw('DATE(sales.completed_at) as day, SUM(sale_items.quantity) as qty')->groupBy('day')->pluck('qty', 'day');

        $out = [];
        for ($d = $from; $d->lte(CarbonImmutable::today()); $d = $d->addDay()) {
            $key = $d->toDateString();
            $out[] = [
                'date' => $key,
                'tyres' => (int) ($qty[$key] ?? 0),
                'sales' => (int) ($rows[$key]->sales ?? 0),
                'revenue' => $withRevenue ? Money::normalize((string) ($rows[$key]->revenue ?? 0)) : null,
            ];
        }

        return $out;
    }

    protected function stockByBrand(): array
    {
        return DB::table('products')->join('brands', 'brands.id', '=', 'products.brand_id')->where('products.status', 'active')
            ->selectRaw('brands.name as label, SUM(products.qty_on_hand + products.qty_unsellable) as physical, SUM(products.qty_on_hand - products.qty_reserved) as available')
            ->groupBy('brands.name')->havingRaw('SUM(products.qty_on_hand + products.qty_unsellable) > 0')->orderByDesc('physical')->limit(12)->get()
            ->map(fn ($r) => ['label' => $r->label, 'physical' => (int) $r->physical, 'available' => (int) $r->available])->all();
    }

    protected function stockBySize(): array
    {
        return DB::table('products')->join('tyre_specs', 'tyre_specs.product_id', '=', 'products.id')->where('products.status', 'active')
            ->selectRaw('tyre_specs.size_display as label, SUM(products.qty_on_hand - products.qty_reserved) as available, SUM(products.qty_on_hand + products.qty_unsellable) as physical')
            ->groupBy('tyre_specs.size_display')->havingRaw('SUM(products.qty_on_hand + products.qty_unsellable) > 0')->orderByDesc('physical')->limit(12)->get()
            ->map(fn ($r) => ['label' => $r->label, 'physical' => (int) $r->physical, 'available' => (int) $r->available])->all();
    }

    protected function newVsUsed(): array
    {
        return DB::table('products')->where('status', 'active')
            ->selectRaw('`condition` as label, SUM(qty_on_hand + qty_unsellable) as physical')->groupBy('condition')->get()
            ->map(fn ($r) => ['label' => ucfirst($r->label), 'physical' => (int) $r->physical])->all();
    }

    protected function movementTrend(CarbonImmutable $from): array
    {
        $rows = DB::table('stock_movements')->where('created_at', '>=', $from)->where('type', '!=', StockMovement::TRANSFER)
            ->selectRaw('DATE(created_at) as day, SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END) as stock_in, SUM(CASE WHEN quantity_change < 0 THEN -quantity_change ELSE 0 END) as stock_out')
            ->groupBy('day')->get()->keyBy('day');
        $out = [];
        for ($d = $from; $d->lte(CarbonImmutable::today()); $d = $d->addDay()) {
            $key = $d->toDateString();
            $out[] = ['date' => $key, 'in' => (int) ($rows[$key]->stock_in ?? 0), 'out' => (int) ($rows[$key]->stock_out ?? 0)];
        }

        return $out;
    }

    protected function topSold(string $column, CarbonImmutable $from): array
    {
        return DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sale_items.item_type', 'product')
            ->whereIn('sales.status', ['completed', 'partially_returned', 'returned'])->where('sales.completed_at', '>=', $from)
            ->whereNotNull($column)
            ->selectRaw("{$column} as label, SUM(sale_items.quantity) as qty")->groupBy($column)->orderByDesc('qty')->limit(5)->get()
            ->map(fn ($r) => ['label' => $r->label, 'qty' => (int) $r->qty])->all();
    }
}
