<?php

namespace App\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockMovement */
class MovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'movement_no' => $this->movementNo(),
            'uuid' => $this->uuid,
            'type' => $this->type,
            'type_label' => StockMovement::TYPES[$this->type] ?? $this->type,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id, 'sku' => $this->product->sku, 'display_name' => $this->product->displayName(), 'condition' => $this->product->condition,
            ]),
            'unit_code' => $this->whenLoaded('unit', fn () => $this->unit?->unit_code),
            'location' => $this->whenLoaded('location', fn () => $this->location?->name),
            'from_location' => $this->whenLoaded('fromLocation', fn () => $this->fromLocation?->name),
            'to_location' => $this->whenLoaded('toLocation', fn () => $this->toLocation?->name),
            'quantity_change' => $this->quantity_change,
            'previous_qty' => $this->previous_qty,
            'resulting_qty' => $this->resulting_qty,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'reference_no' => $this->reference_no,
            'reason' => $this->reason,
            'user' => $this->whenLoaded('user', fn () => $this->user?->name),
            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
        if ($request->user()?->hasPermission('financials.view')) {
            $data['unit_cost'] = $this->unit_cost !== null ? (string) $this->unit_cost : null;
            $data['total_cost'] = $this->total_cost !== null ? (string) $this->total_cost : null;
        }

        return $data;
    }
}
