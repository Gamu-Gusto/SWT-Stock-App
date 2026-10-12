<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\TyreUnit;
use App\Support\TyreSize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $financial = (bool) $request->user()?->hasPermission('financials.view');
        $spec = $this->relationLoaded('spec') ? $this->spec : null;

        $data = [
            'id' => $this->id,
            'sku' => $this->sku,
            'brand' => $this->whenLoaded('brand', fn () => ['id' => $this->brand->id, 'name' => $this->brand->name]),
            'brand_id' => $this->brand_id,
            'tyre_model_id' => $this->tyre_model_id,
            'model' => $this->relationLoaded('tyreModel') ? ($this->tyreModel?->name ?? $this->name) : $this->name,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'description' => $this->description,
            'condition' => $this->condition,
            'tracking' => $this->tracking,
            'spec' => $spec ? [
                'width' => $spec->width,
                'profile' => $spec->profile,
                'rim' => TyreSize::rimText($spec->rim),
                'size' => $spec->size_display,
                'load_index' => $spec->load_index,
                'speed_rating' => $spec->speed_rating,
                'tyre_type' => $spec->tyre_type,
                'run_flat' => $spec->run_flat,
            ] : null,
            'selling_price' => (string) $this->selling_price,
            'promo_price' => $this->promo_price !== null ? (string) $this->promo_price : null,
            'effective_price' => $this->effectivePrice(),
            'min_selling_price' => $this->min_selling_price !== null ? (string) $this->min_selling_price : null,
            'fitting_fee' => $this->fitting_fee !== null ? (string) $this->fitting_fee : null,
            'balancing_fee' => $this->balancing_fee !== null ? (string) $this->balancing_fee : null,
            'alignment_fee' => $this->alignment_fee !== null ? (string) $this->alignment_fee : null,
            'qty_on_hand' => $this->qty_on_hand,
            'qty_physical' => $this->qty_on_hand + $this->qty_unsellable,
            'qty_unsellable' => $this->qty_unsellable,
            'qty_reserved' => $this->qty_reserved,
            'qty_available' => $this->availableQty(),
            'stock_status' => $this->stockStatus(),
            'min_stock_level' => $this->min_stock_level,
            'reorder_qty' => $this->reorder_qty,
            'default_location_id' => $this->default_location_id,
            'default_supplier_id' => $this->default_supplier_id,
            'locations' => $this->whenLoaded('balances', fn () => $this->balances->filter(fn ($b) => $b->qty_on_hand > 0)->map(fn ($b) => [
                'location_id' => $b->location_id,
                'name' => $b->location?->name,
                'is_sellable' => (bool) $b->location?->is_sellable,
                'qty' => $b->qty_on_hand,
            ])->values()),
            'is_public' => $this->is_public,
            'status' => $this->status,
            'last_received_at' => $this->last_received_at?->toIso8601String(),
            'last_sold_at' => $this->last_sold_at?->toIso8601String(),
            'last_movement_at' => $this->last_movement_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->isUnitTracked() && $this->resource->getAttribute('unit_price_min') === null) {
            $prices = TyreUnit::query()->where('product_id', $this->id)->where('status', TyreUnit::AVAILABLE)
                ->selectRaw('MIN(selling_price) as min_price, MAX(selling_price) as max_price')->first();
            $data['unit_price_range'] = $prices && $prices->min_price !== null ? [(string) $prices->min_price, (string) $prices->max_price] : null;
        }

        if ($financial) {
            $data['cost_price'] = (string) $this->cost_price;
        }

        return $data;
    }
}
