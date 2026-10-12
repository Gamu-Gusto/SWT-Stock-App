<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Support\Money;
use App\Support\TyreSize;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-side tyre search. Understands free text such as "205/55 R16", "205 55 16", "R16",
 * "Falken 205/55R16" or "used 205/55 R16" and combines it with structured filters.
 */
class TyreSearchService
{
    public const SORTS = ['relevance', 'size', 'brand', 'price_asc', 'price_desc', 'available_desc', 'updated'];

    /**
     * @return array{width:?int, profile:?int, rim:?string, condition:?string, terms:list<string>}
     */
    public function parseQuery(?string $q): array
    {
        $parsed = TyreSize::extract((string) $q);
        $condition = null;
        $terms = [];
        foreach (preg_split('/\s+/', $parsed['rest'], -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $lower = strtolower($token);
            if (in_array($lower, ['new', 'nuwe'], true)) {
                $condition = 'new';
            } elseif (in_array($lower, ['used', 'second', 'secondhand', '2nd', 'tweedehands'], true)) {
                $condition = 'used';
            } elseif (! in_array($lower, ['tyre', 'tyres', 'tire', 'tires', 'x'], true)) {
                $terms[] = $token;
            }
        }

        return ['width' => $parsed['width'], 'profile' => $parsed['profile'], 'rim' => $parsed['rim'], 'condition' => $condition, 'terms' => $terms];
    }

    public function query(array $filters): Builder
    {
        $parsed = $this->parseQuery($filters['q'] ?? null);
        $width = $filters['width'] ?? $parsed['width'];
        $profile = $filters['profile'] ?? $parsed['profile'];
        $rim = isset($filters['rim']) && $filters['rim'] !== '' ? TyreSize::rimText($filters['rim']) : $parsed['rim'];
        $condition = $filters['condition'] ?? $parsed['condition'];

        $q = Product::query()
            ->select('products.*')
            ->leftJoin('tyre_specs', 'tyre_specs.product_id', '=', 'products.id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->with(['brand', 'tyreModel', 'spec', 'balances' => fn ($b) => $b->where('qty_on_hand', '>', 0)->with('location')]);

        if (empty($filters['include_archived'])) {
            $q->where('products.status', Product::STATUS_ACTIVE);
        }
        if ($width) {
            $q->where('tyre_specs.width', (int) $width);
        }
        if ($profile) {
            $q->where('tyre_specs.profile', (int) $profile);
        }
        if ($rim) {
            $q->where('tyre_specs.rim', $rim);
        }
        if ($condition) {
            $q->where('products.condition', $condition);
        }
        if (! empty($filters['brand_id'])) {
            $q->whereIn('products.brand_id', (array) $filters['brand_id']);
        }
        if (! empty($filters['tyre_model_id'])) {
            $q->where('products.tyre_model_id', $filters['tyre_model_id']);
        }
        if (! empty($filters['tyre_type'])) {
            $q->where('tyre_specs.tyre_type', $filters['tyre_type']);
        }
        if (! empty($filters['supplier_id'])) {
            $q->where('products.default_supplier_id', $filters['supplier_id']);
        }
        if (! empty($filters['location_id'])) {
            $q->whereExists(fn ($s) => $s->from('stock_balances')->whereColumn('stock_balances.product_id', 'products.id')
                ->where('stock_balances.location_id', $filters['location_id'])->where('stock_balances.qty_on_hand', '>', 0));
        }
        if (isset($filters['min_available']) && $filters['min_available'] !== '') {
            $q->whereRaw('(products.qty_on_hand - products.qty_reserved) >= ?', [(int) $filters['min_available']]);
        }
        if (! empty($filters['in_stock'])) {
            $q->whereRaw('products.qty_on_hand > products.qty_reserved');
        }
        if (! empty($filters['stock_status'])) {
            match ($filters['stock_status']) {
                'out_of_stock' => $q->whereRaw('products.qty_on_hand <= products.qty_reserved'),
                'low_stock' => $q->whereRaw('products.qty_on_hand > products.qty_reserved')
                    ->where('products.min_stock_level', '>', 0)
                    ->whereRaw('(products.qty_on_hand - products.qty_reserved) <= products.min_stock_level'),
                'in_stock' => $q->whereRaw('(products.qty_on_hand - products.qty_reserved) > products.min_stock_level'),
                default => null,
            };
        }
        if (isset($filters['price_min']) && $filters['price_min'] !== '') {
            $q->where('products.selling_price', '>=', Money::normalize($filters['price_min']));
        }
        if (isset($filters['price_max']) && $filters['price_max'] !== '') {
            $q->where('products.selling_price', '<=', Money::normalize($filters['price_max']));
        }

        // Every remaining word must match brand, model, name, SKU, load/speed or a used-tyre code.
        foreach ($parsed['terms'] as $term) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $loadSpeed = preg_match('/^(\d{2,3}(?:\/\d{2,3})?)([A-Z]{1,2})$/i', $term, $ls) ? [$ls[1], strtoupper($ls[2])] : null;
            $q->where(function (Builder $w) use ($like, $term, $loadSpeed) {
                $w->where('brands.name', 'like', $like)
                    ->orWhere('products.name', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)
                    ->orWhere('products.description', 'like', $like)
                    ->orWhereHas('tyreModel', fn ($m) => $m->where('name', 'like', $like))
                    ->when($loadSpeed, fn ($w) => $w->orWhere(fn ($s) => $s->where('tyre_specs.load_index', $loadSpeed[0])->where('tyre_specs.speed_rating', $loadSpeed[1])))
                    ->orWhereExists(fn ($u) => $u->from('tyre_units')->whereColumn('tyre_units.product_id', 'products.id')->where('tyre_units.unit_code', $term));
            });
        }

        $this->applySort($q, $filters['sort'] ?? 'relevance');

        return $q;
    }

    public function search(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($filters)->paginate(min(max($perPage, 1), 100))->withQueryString();
    }

    protected function applySort(Builder $q, string $sort): void
    {
        match ($sort) {
            'size' => $q->orderBy('tyre_specs.rim')->orderBy('tyre_specs.width')->orderBy('tyre_specs.profile'),
            'brand' => $q->orderBy('brands.name')->orderBy('products.name'),
            'price_asc' => $q->orderBy('products.selling_price'),
            'price_desc' => $q->orderByDesc('products.selling_price'),
            'available_desc' => $q->orderByRaw('(products.qty_on_hand - products.qty_reserved) DESC'),
            'updated' => $q->orderByDesc('products.updated_at'),
            // In stock first, then preferred brands (e.g. Falken), brand, new before used.
            default => $q->orderByRaw('CASE WHEN products.qty_on_hand > products.qty_reserved THEN 0 ELSE 1 END')
                ->orderByDesc('brands.is_preferred')->orderBy('brands.name')->orderBy('products.condition')->orderBy('tyre_specs.size_key'),
        };
        $q->orderBy('products.id');
    }
}
