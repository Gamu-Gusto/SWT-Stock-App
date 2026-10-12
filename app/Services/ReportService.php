<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Money;
use App\Support\TyreSize;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Management reports. Each report returns typed columns + rows so the same data drives the on-screen
 * table, the printable layout and CSV/XLSX exports. Cost columns are only included for users with
 * `financials.view`.
 */
class ReportService
{
    /** key => [title, permission, description] */
    public const REPORTS = [
        'current_stock' => ['Current stock', 'reports.operational', 'Every tyre with physical, reserved and available quantities.'],
        'stock_by_size' => ['Stock by size', 'reports.operational', 'Available tyres grouped by tyre size.'],
        'stock_by_brand' => ['Stock by brand', 'reports.operational', 'Available tyres grouped by brand.'],
        'low_stock' => ['Low stock', 'reports.operational', 'Tyres at or below their minimum available quantity.'],
        'out_of_stock' => ['Out of stock', 'reports.operational', 'Tyres with no available quantity.'],
        'stock_movements' => ['Stock movements', 'reports.operational', 'All inventory increases and decreases in a period.'],
        'sales' => ['Sales', 'reports.sales', 'Sales by date, employee, brand or size with quantities and revenue.'],
        'stocktake_variances' => ['Stocktake variances', 'reports.operational', 'Shortages and excess stock found during stocktakes, with approvals.'],
        'inventory_valuation' => ['Inventory valuation', 'reports.financial', 'Cost value of stock on hand (FIFO for new tyres, specific cost for used tyres).'],
        'slow_moving' => ['Slow-moving stock', 'reports.operational', 'Tyres in stock with no sales for a chosen number of days.'],
    ];

    public function __construct(protected ValuationService $valuation, protected AppSettings $settings) {}

    /** @return list<array{key:string,title:string,description:string}> */
    public function available(User $user): array
    {
        $out = [];
        foreach (self::REPORTS as $key => [$title, $permission, $description]) {
            if ($user->hasPermission($permission)) {
                $out[] = compact('key', 'title', 'description');
            }
        }

        return $out;
    }

    /** @return array{key:string,title:string,columns:list<array{key:string,label:string,type:string}>,rows:list<array<string,mixed>>,summary:array<string,mixed>,filters:array,generated_at:string} */
    public function run(string $key, array $filters, User $user): array
    {
        if (! isset(self::REPORTS[$key])) {
            throw new InvalidArgumentException('Unknown report.');
        }
        [$title, $permission] = self::REPORTS[$key];
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException('You do not have permission to view this report.');
        }
        $financial = $user->hasPermission('financials.view');
        $filters = $this->normaliseFilters($filters);
        $result = $this->{'report'.str_replace('_', '', ucwords($key, '_'))}($filters, $financial);

        return $result + [
            'key' => $key,
            'title' => $title,
            'filters' => $filters,
            'generated_at' => now()->toIso8601String(),
            'summary' => [],
        ];
    }

    // ---------------------------------------------------------------- stock reports

    protected function reportCurrentStock(array $f, bool $financial): array
    {
        $rows = $this->productBase($f)
            ->selectRaw('products.id, products.sku, brands.name as brand, COALESCE(tyre_models.name, products.name) as model, tyre_specs.size_display as size, products.condition as item_condition,
                (products.qty_on_hand + products.qty_unsellable) as physical, products.qty_reserved as reserved, (products.qty_on_hand - products.qty_reserved) as available,
                products.selling_price, products.cost_price, products.min_stock_level')
            ->orderBy('brands.name')->orderBy('tyre_specs.size_key')->get();
        $locations = $this->locationSummary($rows->pluck('id')->all(), $f['location_id'] ?? null);
        $values = $financial ? $this->valuation->byProduct() : [];

        $out = $rows->map(function ($r) use ($locations, $values, $financial) {
            $row = [
                'sku' => $r->sku, 'brand' => $r->brand, 'model' => $r->model, 'size' => $r->size, 'condition' => ucfirst($r->item_condition),
                'physical' => (int) $r->physical, 'reserved' => (int) $r->reserved, 'available' => max(0, (int) $r->available),
                'selling_price' => Money::normalize($r->selling_price), 'locations' => $locations[$r->id] ?? '',
            ];
            if ($financial) {
                $row['cost_value'] = $values[$r->id]['value'] ?? '0.00';
            }

            return $row;
        })->all();

        $columns = [
            ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'], ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'],
            ['key' => 'model', 'label' => 'Model', 'type' => 'text'], ['key' => 'size', 'label' => 'Size', 'type' => 'text'],
            ['key' => 'condition', 'label' => 'Condition', 'type' => 'text'], ['key' => 'physical', 'label' => 'Physical', 'type' => 'int'],
            ['key' => 'reserved', 'label' => 'Reserved', 'type' => 'int'], ['key' => 'available', 'label' => 'Available', 'type' => 'int'],
            ['key' => 'selling_price', 'label' => 'Selling price', 'type' => 'money'], ['key' => 'locations', 'label' => 'Location(s)', 'type' => 'text'],
        ];
        if ($financial) {
            $columns[] = ['key' => 'cost_value', 'label' => 'Cost value', 'type' => 'money'];
        }

        return ['columns' => $columns, 'rows' => $out, 'summary' => [
            'Tyre records' => count($out),
            'Physical tyres' => array_sum(array_column($out, 'physical')),
            'Available tyres' => array_sum(array_column($out, 'available')),
        ] + ($financial ? ['Cost value' => Money::format(Money::add('0', ...array_column($out, 'cost_value')))] : [])];
    }

    protected function reportStockBySize(array $f, bool $financial): array
    {
        $rows = $this->productBase($f)
            ->selectRaw('tyre_specs.size_display as size, tyre_specs.size_key, COUNT(*) as variants, SUM(products.qty_on_hand + products.qty_unsellable) as physical,
                SUM(products.qty_reserved) as reserved, SUM(products.qty_on_hand - products.qty_reserved) as available,
                SUM(CASE WHEN products.condition = \'new\' THEN products.qty_on_hand - products.qty_reserved ELSE 0 END) as new_available,
                SUM(CASE WHEN products.condition = \'used\' THEN products.qty_on_hand - products.qty_reserved ELSE 0 END) as used_available')
            ->groupBy('tyre_specs.size_display', 'tyre_specs.size_key')->orderBy('tyre_specs.size_key')->get()
            ->map(fn ($r) => ['size' => $r->size, 'variants' => (int) $r->variants, 'physical' => (int) $r->physical, 'reserved' => (int) $r->reserved,
                'available' => (int) $r->available, 'new_available' => (int) $r->new_available, 'used_available' => (int) $r->used_available])->all();

        return ['columns' => [
            ['key' => 'size', 'label' => 'Size', 'type' => 'text'], ['key' => 'variants', 'label' => 'Variants', 'type' => 'int'],
            ['key' => 'physical', 'label' => 'Physical', 'type' => 'int'], ['key' => 'reserved', 'label' => 'Reserved', 'type' => 'int'],
            ['key' => 'available', 'label' => 'Available', 'type' => 'int'], ['key' => 'new_available', 'label' => 'New available', 'type' => 'int'],
            ['key' => 'used_available', 'label' => 'Used available', 'type' => 'int'],
        ], 'rows' => $rows, 'summary' => ['Sizes' => count($rows), 'Available tyres' => array_sum(array_column($rows, 'available'))]];
    }

    protected function reportStockByBrand(array $f, bool $financial): array
    {
        $rows = $this->productBase($f)
            ->selectRaw('brands.name as brand, COUNT(*) as variants, SUM(products.qty_on_hand + products.qty_unsellable) as physical,
                SUM(products.qty_reserved) as reserved, SUM(products.qty_on_hand - products.qty_reserved) as available')
            ->groupBy('brands.name')->orderBy('brands.name')->get()
            ->map(fn ($r) => ['brand' => $r->brand, 'variants' => (int) $r->variants, 'physical' => (int) $r->physical,
                'reserved' => (int) $r->reserved, 'available' => (int) $r->available])->all();

        return ['columns' => [
            ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'], ['key' => 'variants', 'label' => 'Variants', 'type' => 'int'],
            ['key' => 'physical', 'label' => 'Physical', 'type' => 'int'], ['key' => 'reserved', 'label' => 'Reserved', 'type' => 'int'],
            ['key' => 'available', 'label' => 'Available', 'type' => 'int'],
        ], 'rows' => $rows, 'summary' => ['Brands' => count($rows), 'Available tyres' => array_sum(array_column($rows, 'available'))]];
    }

    protected function reportLowStock(array $f, bool $financial): array
    {
        return $this->thresholdReport($f, true);
    }

    protected function reportOutOfStock(array $f, bool $financial): array
    {
        return $this->thresholdReport($f, false);
    }

    protected function thresholdReport(array $f, bool $low): array
    {
        $q = $this->productBase($f);
        $low
            ? $q->where('products.min_stock_level', '>', 0)->whereRaw('products.qty_on_hand - products.qty_reserved > 0')->whereRaw('products.qty_on_hand - products.qty_reserved <= products.min_stock_level')
            : $q->whereRaw('products.qty_on_hand - products.qty_reserved <= 0');
        $rows = $q->leftJoin('suppliers', 'suppliers.id', '=', 'products.default_supplier_id')
            ->selectRaw('products.sku, brands.name as brand, COALESCE(tyre_models.name, products.name) as model, tyre_specs.size_display as size, products.condition as item_condition,
                (products.qty_on_hand + products.qty_unsellable) as physical, products.qty_reserved as reserved, (products.qty_on_hand - products.qty_reserved) as available,
                products.min_stock_level, products.reorder_qty, suppliers.company_name as supplier, products.last_sold_at')
            ->orderBy('brands.name')->orderBy('tyre_specs.size_key')->get()
            ->map(fn ($r) => ['sku' => $r->sku, 'brand' => $r->brand, 'model' => $r->model, 'size' => $r->size, 'condition' => ucfirst($r->item_condition),
                'physical' => (int) $r->physical, 'reserved' => (int) $r->reserved, 'available' => max(0, (int) $r->available),
                'min_stock_level' => (int) $r->min_stock_level, 'reorder_qty' => (int) $r->reorder_qty, 'supplier' => $r->supplier, 'last_sold_at' => $r->last_sold_at])->all();

        return ['columns' => [
            ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'], ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'],
            ['key' => 'model', 'label' => 'Model', 'type' => 'text'], ['key' => 'size', 'label' => 'Size', 'type' => 'text'],
            ['key' => 'condition', 'label' => 'Condition', 'type' => 'text'], ['key' => 'physical', 'label' => 'Physical', 'type' => 'int'],
            ['key' => 'reserved', 'label' => 'Reserved', 'type' => 'int'], ['key' => 'available', 'label' => 'Available', 'type' => 'int'],
            ['key' => 'min_stock_level', 'label' => 'Minimum', 'type' => 'int'], ['key' => 'reorder_qty', 'label' => 'Reorder qty', 'type' => 'int'],
            ['key' => 'supplier', 'label' => 'Supplier', 'type' => 'text'], ['key' => 'last_sold_at', 'label' => 'Last sold', 'type' => 'date'],
        ], 'rows' => $rows, 'summary' => ['Tyre records' => count($rows)]];
    }

    protected function reportStockMovements(array $f, bool $financial): array
    {
        $q = DB::table('stock_movements as m')
            ->join('products', 'products.id', '=', 'm.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('tyre_specs', 'tyre_specs.product_id', '=', 'products.id')
            ->join('inventory_locations as l', 'l.id', '=', 'm.location_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('tyre_units as tu', 'tu.id', '=', 'm.tyre_unit_id')
            ->whereBetween('m.created_at', [$f['date_from'].' 00:00:00', $f['date_to'].' 23:59:59']);
        $this->applyProductFilters($q, $f);
        if (! empty($f['user_id'])) {
            $q->where('m.user_id', $f['user_id']);
        }
        if (! empty($f['location_id'])) {
            $q->where('m.location_id', $f['location_id']);
        }
        if (! empty($f['movement_type'])) {
            $q->where('m.type', $f['movement_type']);
        }
        $rows = $q->selectRaw('m.id, m.created_at, m.type, products.sku, brands.name as brand, tyre_specs.size_display as size, products.condition as item_condition, tu.unit_code,
                l.name as location, m.previous_qty, m.quantity_change, m.resulting_qty, m.reference_no, m.reason, u.name as employee, m.total_cost')
            ->orderBy('m.created_at')->orderBy('m.id')->limit(20000)->get()
            ->map(function ($r) use ($financial) {
                $row = ['movement' => 'MV-'.str_pad((string) $r->id, 7, '0', STR_PAD_LEFT), 'date' => $r->created_at, 'type' => StockMovement::TYPES[$r->type] ?? $r->type,
                    'sku' => $r->sku, 'brand' => $r->brand, 'size' => $r->size, 'condition' => ucfirst($r->item_condition), 'unit_code' => $r->unit_code,
                    'location' => $r->location, 'previous_qty' => (int) $r->previous_qty, 'change' => (int) $r->quantity_change, 'resulting_qty' => (int) $r->resulting_qty,
                    'reference' => $r->reference_no, 'reason' => $r->reason, 'employee' => $r->employee];
                if ($financial) {
                    $row['cost'] = $r->total_cost !== null ? Money::normalize($r->total_cost) : null;
                }

                return $row;
            })->all();

        $columns = [
            ['key' => 'movement', 'label' => 'Movement', 'type' => 'text'], ['key' => 'date', 'label' => 'Date', 'type' => 'datetime'],
            ['key' => 'type', 'label' => 'Type', 'type' => 'text'], ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'],
            ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'], ['key' => 'size', 'label' => 'Size', 'type' => 'text'],
            ['key' => 'condition', 'label' => 'Cond.', 'type' => 'text'], ['key' => 'unit_code', 'label' => 'Tyre unit', 'type' => 'text'],
            ['key' => 'location', 'label' => 'Location', 'type' => 'text'], ['key' => 'previous_qty', 'label' => 'Before', 'type' => 'int'],
            ['key' => 'change', 'label' => 'Change', 'type' => 'signed'], ['key' => 'resulting_qty', 'label' => 'After', 'type' => 'int'],
            ['key' => 'reference', 'label' => 'Reference', 'type' => 'text'], ['key' => 'reason', 'label' => 'Reason', 'type' => 'text'],
            ['key' => 'employee', 'label' => 'Employee', 'type' => 'text'],
        ];
        if ($financial) {
            $columns[] = ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'];
        }
        $in = array_sum(array_map(fn ($r) => max(0, $r['change']), $rows));
        $out = array_sum(array_map(fn ($r) => max(0, -$r['change']), $rows));

        return ['columns' => $columns, 'rows' => $rows, 'summary' => ['Movements' => count($rows), 'Tyres in' => $in, 'Tyres out' => $out]];
    }

    // ---------------------------------------------------------------- sales

    protected function reportSales(array $f, bool $financial): array
    {
        $groupBy = $f['group_by'] ?? 'date';
        $groups = [
            'date' => ['DATE(sales.completed_at)', 'Date', 'date'],
            'employee' => ['u.name', 'Employee', 'text'],
            'brand' => ['sale_items.brand_name', 'Brand', 'text'],
            'size' => ['sale_items.size_display', 'Size', 'text'],
            'condition' => ['sale_items.condition', 'Condition', 'text'],
        ];
        [$expr, $label, $type] = $groups[$groupBy] ?? $groups['date'];

        $q = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('users as u', 'u.id', '=', 'sales.user_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('tyre_specs', 'tyre_specs.product_id', '=', 'products.id')
            ->whereIn('sales.status', ['completed', 'partially_returned', 'returned'])
            ->whereBetween('sales.completed_at', [$f['date_from'].' 00:00:00', $f['date_to'].' 23:59:59']);
        if (! empty($f['user_id'])) {
            $q->where('sales.user_id', $f['user_id']);
        }
        if (! empty($f['brand_id'])) {
            $q->where('products.brand_id', $f['brand_id']);
        }
        if (! empty($f['condition'])) {
            $q->where('sale_items.condition', $f['condition']);
        }
        foreach (['width', 'profile', 'rim'] as $s) {
            if (! empty($f[$s])) {
                $q->where("tyre_specs.{$s}", $f[$s]);
            }
        }
        if (! empty($f['location_id'])) {
            $q->where('sale_items.location_id', $f['location_id']);
        }

        // Revenue excl. VAT is allocated to lines pro-rata to the sale's VAT.
        $rows = $q->selectRaw("{$expr} as grp,
                SUM(CASE WHEN sale_items.item_type = 'product' THEN sale_items.quantity ELSE 0 END) as tyres,
                SUM(CASE WHEN sale_items.item_type = 'product' THEN sale_items.returned_qty ELSE 0 END) as returned,
                COUNT(DISTINCT sales.id) as sales,
                SUM(sale_items.line_total) as revenue,
                SUM(sale_items.cost_total) as cost")
            ->groupByRaw($expr)->orderByRaw($groupBy === 'date' ? 'grp' : 'tyres DESC')->get()
            ->map(function ($r) use ($financial) {
                $row = ['group' => $r->grp ?? '—', 'sales' => (int) $r->sales, 'tyres' => (int) $r->tyres, 'returned' => (int) $r->returned, 'revenue' => Money::normalize((string) $r->revenue)];
                if ($financial) {
                    $row['cost'] = Money::normalize((string) $r->cost);
                    $row['gross_margin'] = Money::sub($row['revenue'], $row['cost']);
                }

                return $row;
            })->all();

        $columns = [
            ['key' => 'group', 'label' => $label, 'type' => $type], ['key' => 'sales', 'label' => 'Sales', 'type' => 'int'],
            ['key' => 'tyres', 'label' => 'Tyres sold', 'type' => 'int'], ['key' => 'returned', 'label' => 'Returned', 'type' => 'int'],
            ['key' => 'revenue', 'label' => 'Revenue (line totals)', 'type' => 'money'],
        ];
        if ($financial) {
            $columns[] = ['key' => 'cost', 'label' => 'Cost of tyres', 'type' => 'money'];
            $columns[] = ['key' => 'gross_margin', 'label' => 'Gross margin', 'type' => 'money'];
        }
        $summary = [
            'Tyres sold' => array_sum(array_column($rows, 'tyres')),
            'Total revenue' => Money::format(Money::add('0', ...array_column($rows, 'revenue'))),
        ];
        if ($groupBy !== 'brand' && $groupBy !== 'size' && $groupBy !== 'condition') {
            $summary['Sales'] = (int) DB::table('sales')->whereIn('status', ['completed', 'partially_returned', 'returned'])
                ->whereBetween('completed_at', [$f['date_from'].' 00:00:00', $f['date_to'].' 23:59:59'])
                ->when(! empty($f['user_id']), fn ($q) => $q->where('user_id', $f['user_id']))->count();
        }

        return ['columns' => $columns, 'rows' => $rows, 'summary' => $summary];
    }

    // ---------------------------------------------------------------- stocktakes / valuation / slow moving

    protected function reportStocktakeVariances(array $f, bool $financial): array
    {
        $q = DB::table('stocktake_items as i')->join('stocktakes as s', 's.id', '=', 'i.stocktake_id')
            ->join('products', 'products.id', '=', 'i.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('tyre_specs', 'tyre_specs.product_id', '=', 'products.id')
            ->join('inventory_locations as l', 'l.id', '=', 'i.location_id')
            ->leftJoin('tyre_units as tu', 'tu.id', '=', 'i.tyre_unit_id')
            ->leftJoin('users as c', 'c.id', '=', 'i.counted_by')
            ->leftJoin('users as a', 'a.id', '=', 's.approved_by')
            ->whereNotNull('i.variance')->where('i.variance', '!=', 0)
            ->whereBetween('s.created_at', [$f['date_from'].' 00:00:00', $f['date_to'].' 23:59:59']);
        $this->applyProductFilters($q, $f);
        if (! empty($f['location_id'])) {
            $q->where('i.location_id', $f['location_id']);
        }
        if (! empty($f['stocktake_id'])) {
            $q->where('s.id', $f['stocktake_id']);
        }
        $rows = $q->selectRaw('s.stocktake_no, s.status as stocktake_status, products.sku, brands.name as brand, tyre_specs.size_display as size, products.condition as item_condition, tu.unit_code,
                l.name as location, i.system_qty_at_count, i.counted_qty, i.variance, i.cost_impact, i.status, i.adjustment_reason, c.name as counted_by, a.name as approved_by, s.approved_at, s.adjustment_reference')
            ->orderBy('s.id')->orderBy('i.id')->get()
            ->map(function ($r) use ($financial) {
                $row = ['stocktake' => $r->stocktake_no, 'sku' => $r->sku, 'brand' => $r->brand, 'size' => $r->size, 'condition' => ucfirst($r->item_condition),
                    'unit_code' => $r->unit_code, 'location' => $r->location, 'system_qty' => (int) $r->system_qty_at_count, 'physical_qty' => (int) $r->counted_qty,
                    'difference' => (int) $r->variance, 'result' => (int) $r->variance < 0 ? 'Shortage' : 'Excess', 'decision' => ucfirst(str_replace('_', ' ', $r->status)),
                    'reason' => $r->adjustment_reason, 'counted_by' => $r->counted_by, 'approved_by' => $r->approved_by, 'approved_at' => $r->approved_at,
                    'adjustment_ref' => $r->status === 'approved' ? $r->adjustment_reference : null];
                if ($financial) {
                    $row['cost_impact'] = Money::normalize((string) $r->cost_impact);
                }

                return $row;
            })->all();

        $columns = [
            ['key' => 'stocktake', 'label' => 'Stocktake', 'type' => 'text'], ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'],
            ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'], ['key' => 'size', 'label' => 'Size', 'type' => 'text'],
            ['key' => 'unit_code', 'label' => 'Tyre unit', 'type' => 'text'], ['key' => 'location', 'label' => 'Location', 'type' => 'text'],
            ['key' => 'system_qty', 'label' => 'System qty', 'type' => 'int'], ['key' => 'physical_qty', 'label' => 'Physical qty', 'type' => 'int'],
            ['key' => 'difference', 'label' => 'Difference', 'type' => 'signed'], ['key' => 'result', 'label' => 'Result', 'type' => 'text'],
            ['key' => 'decision', 'label' => 'Decision', 'type' => 'text'], ['key' => 'reason', 'label' => 'Reason', 'type' => 'text'],
            ['key' => 'counted_by', 'label' => 'Counted by', 'type' => 'text'], ['key' => 'approved_by', 'label' => 'Approved by', 'type' => 'text'],
            ['key' => 'approved_at', 'label' => 'Approval date', 'type' => 'datetime'], ['key' => 'adjustment_ref', 'label' => 'Adjustment ref.', 'type' => 'text'],
        ];
        if ($financial) {
            $columns[] = ['key' => 'cost_impact', 'label' => 'Cost impact', 'type' => 'money'];
        }
        $summary = [
            'Lines with differences' => count($rows),
            'Shortage (tyres)' => array_sum(array_map(fn ($r) => min(0, $r['difference']), $rows)),
            'Excess (tyres)' => array_sum(array_map(fn ($r) => max(0, $r['difference']), $rows)),
        ];
        if ($financial) {
            $summary['Net cost impact'] = Money::format(Money::add('0', ...array_column($rows, 'cost_impact')));
        }

        return ['columns' => $columns, 'rows' => $rows, 'summary' => $summary];
    }

    protected function reportInventoryValuation(array $f, bool $financial): array
    {
        $values = $this->valuation->byProduct();
        $rows = $this->productBase($f)->whereIn('products.id', array_keys($values) ?: [0])
            ->selectRaw('products.id, products.sku, brands.name as brand, COALESCE(tyre_models.name, products.name) as model, tyre_specs.size_display as size, products.condition as item_condition, products.tracking, products.selling_price')
            ->orderBy('brands.name')->orderBy('tyre_specs.size_key')->get()
            ->map(function ($r) use ($values) {
                $qty = $values[$r->id]['qty'];
                $value = $values[$r->id]['value'];

                return ['sku' => $r->sku, 'brand' => $r->brand, 'model' => $r->model, 'size' => $r->size, 'condition' => ucfirst($r->item_condition),
                    'method' => $r->tracking === 'unit' ? 'Specific unit cost' : 'FIFO', 'quantity' => $qty,
                    'average_cost' => $qty ? Money::fromCents(Money::divRound(Money::toCents($value), $qty)) : '0.00', 'cost_value' => $value,
                    'retail_value' => $r->tracking === 'unit' ? null : Money::mul($r->selling_price, $qty)];
            })->filter(fn ($r) => $r['quantity'] > 0)->values()->all();

        return ['columns' => [
            ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'], ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'],
            ['key' => 'model', 'label' => 'Model', 'type' => 'text'], ['key' => 'size', 'label' => 'Size', 'type' => 'text'],
            ['key' => 'condition', 'label' => 'Condition', 'type' => 'text'], ['key' => 'method', 'label' => 'Method', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Qty', 'type' => 'int'], ['key' => 'average_cost', 'label' => 'Avg cost', 'type' => 'money'],
            ['key' => 'cost_value', 'label' => 'Cost value', 'type' => 'money'], ['key' => 'retail_value', 'label' => 'Retail value', 'type' => 'money'],
        ], 'rows' => $rows, 'summary' => [
            'Tyres valued' => array_sum(array_column($rows, 'quantity')),
            'Total cost value' => Money::format(Money::add('0', ...array_column($rows, 'cost_value'))),
            'Valuation method' => 'FIFO (new tyres); specific identification (used tyres)',
        ]];
    }

    protected function reportSlowMoving(array $f, bool $financial): array
    {
        $days = (int) ($f['days'] ?? $this->settings->get('slow_moving_days', 90));
        $cutoff = CarbonImmutable::now()->subDays($days);
        $rows = $this->productBase($f)
            ->whereRaw('(products.qty_on_hand + products.qty_unsellable) > 0')
            ->where(fn ($q) => $q->whereNull('products.last_sold_at')->orWhere('products.last_sold_at', '<', $cutoff))
            ->selectRaw('products.id, products.sku, brands.name as brand, COALESCE(tyre_models.name, products.name) as model, tyre_specs.size_display as size, products.condition as item_condition,
                (products.qty_on_hand + products.qty_unsellable) as physical, products.last_sold_at, products.last_received_at, products.created_at')
            ->orderByRaw('COALESCE(products.last_sold_at, products.created_at)')->get();
        $values = $financial ? $this->valuation->byProduct() : [];
        $out = $rows->map(function ($r) use ($values, $financial) {
            $since = CarbonImmutable::parse($r->last_sold_at ?? $r->created_at);
            $row = ['sku' => $r->sku, 'brand' => $r->brand, 'model' => $r->model, 'size' => $r->size, 'condition' => ucfirst($r->item_condition),
                'physical' => (int) $r->physical, 'last_sold_at' => $r->last_sold_at, 'last_received_at' => $r->last_received_at,
                'days_without_sale' => (int) $since->diffInDays(now())];
            if ($financial) {
                $row['cost_value'] = $values[$r->id]['value'] ?? '0.00';
            }

            return $row;
        })->all();

        $columns = [
            ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'], ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'],
            ['key' => 'model', 'label' => 'Model', 'type' => 'text'], ['key' => 'size', 'label' => 'Size', 'type' => 'text'],
            ['key' => 'condition', 'label' => 'Condition', 'type' => 'text'], ['key' => 'physical', 'label' => 'In stock', 'type' => 'int'],
            ['key' => 'last_sold_at', 'label' => 'Last sold', 'type' => 'date'], ['key' => 'last_received_at', 'label' => 'Last received', 'type' => 'date'],
            ['key' => 'days_without_sale', 'label' => 'Days without sale', 'type' => 'int'],
        ];
        if ($financial) {
            $columns[] = ['key' => 'cost_value', 'label' => 'Cost value', 'type' => 'money'];
        }

        return ['columns' => $columns, 'rows' => $out, 'summary' => ["No sales in {$days}+ days" => count($out), 'Tyres' => array_sum(array_column($out, 'physical'))]];
    }

    // ---------------------------------------------------------------- helpers

    protected function productBase(array $f): Builder
    {
        $q = DB::table('products')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('tyre_models', 'tyre_models.id', '=', 'products.tyre_model_id')
            ->leftJoin('tyre_specs', 'tyre_specs.product_id', '=', 'products.id')
            ->where('products.status', 'active');
        $this->applyProductFilters($q, $f);
        if (! empty($f['location_id'])) {
            $q->whereExists(fn ($s) => $s->from('stock_balances')->whereColumn('stock_balances.product_id', 'products.id')
                ->where('stock_balances.location_id', $f['location_id'])->where('stock_balances.qty_on_hand', '>', 0));
        }
        if (! empty($f['supplier_id'])) {
            $q->where(fn ($w) => $w->where('products.default_supplier_id', $f['supplier_id'])
                ->orWhereExists(fn ($s) => $s->from('stock_lots')->whereColumn('stock_lots.product_id', 'products.id')->where('stock_lots.supplier_id', $f['supplier_id'])));
        }

        return $q;
    }

    protected function applyProductFilters(Builder $q, array $f): void
    {
        if (! empty($f['brand_id'])) {
            $q->where('products.brand_id', $f['brand_id']);
        }
        if (! empty($f['condition'])) {
            $q->where('products.condition', $f['condition']);
        }
        foreach (['width', 'profile', 'rim'] as $s) {
            if (! empty($f[$s])) {
                $q->where("tyre_specs.{$s}", $f[$s]);
            }
        }
    }

    /** @return array<int,string> product id => "Rack A (4), Main Store (2)" */
    protected function locationSummary(array $productIds, ?int $locationId): array
    {
        if (! $productIds) {
            return [];
        }
        $names = InventoryLocation::query()->pluck('name', 'id');
        $out = [];
        foreach (DB::table('stock_balances')->whereIn('product_id', $productIds)->where('qty_on_hand', '>', 0)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))->orderByDesc('qty_on_hand')->get() as $b) {
            $out[$b->product_id][] = ($names[$b->location_id] ?? '?')." ({$b->qty_on_hand})";
        }

        return array_map(fn ($l) => implode(', ', $l), $out);
    }

    protected function normaliseFilters(array $f): array
    {
        $f = array_filter($f, fn ($v) => $v !== null && $v !== '');
        $f['date_to'] = isset($f['date_to']) ? CarbonImmutable::parse($f['date_to'])->toDateString() : CarbonImmutable::today()->toDateString();
        $f['date_from'] = isset($f['date_from']) ? CarbonImmutable::parse($f['date_from'])->toDateString() : CarbonImmutable::parse($f['date_to'])->subDays(29)->toDateString();
        if (! empty($f['size']) && ($size = TyreSize::extract($f['size']))) {
            foreach (['width', 'profile', 'rim'] as $k) {
                if ($size[$k] !== null && empty($f[$k])) {
                    $f[$k] = $size[$k];
                }
            }
        }
        foreach (['brand_id', 'user_id', 'location_id', 'supplier_id', 'width', 'profile', 'days', 'stocktake_id'] as $k) {
            if (isset($f[$k])) {
                $f[$k] = (int) $f[$k];
            }
        }

        return $f;
    }
}
