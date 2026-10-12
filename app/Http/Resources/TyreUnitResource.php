<?php

namespace App\Http\Resources;

use App\Models\TyreUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TyreUnit */
class TyreUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'unit_code' => $this->unit_code,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'sku' => $this->product->sku, 'display_name' => $this->product->displayName()]),
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['id' => $this->location->id, 'name' => $this->location->name, 'is_sellable' => $this->location->is_sellable] : null),
            'location_id' => $this->location_id,
            'tread_depth_mm' => $this->tread_depth_mm !== null ? (string) $this->tread_depth_mm : null,
            'grade' => $this->grade,
            'dot_code' => $this->dot_code,
            'defects' => $this->defects,
            'inspection_notes' => $this->inspection_notes,
            'selling_price' => (string) $this->selling_price,
            'status' => $this->status,
            'is_sellable' => $this->status === TyreUnit::AVAILABLE && (bool) $this->location?->is_sellable,
            'inspected_by' => $this->whenLoaded('inspector', fn () => $this->inspector?->name),
            'inspected_at' => $this->inspected_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'sold_at' => $this->sold_at?->toIso8601String(),
            'images' => $this->whenLoaded('images', fn () => MediaResource::collection($this->images)),
        ];
        if ($request->user()?->hasPermission('financials.view')) {
            $data['cost_price'] = (string) $this->cost_price;
        }

        return $data;
    }
}
