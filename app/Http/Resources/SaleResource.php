<?php

namespace App\Http\Resources;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sale */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $financial = (bool) $request->user()?->hasPermission('financials.view');

        $data = [
            'id' => $this->id,
            'sale_no' => $this->sale_no,
            'status' => $this->status,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_email' => $this->customer_email,
            'vehicle' => $this->vehicle,
            'subtotal' => (string) $this->subtotal,
            'discount_total' => (string) $this->discount_total,
            'vat_registered' => $this->vat_registered,
            'prices_include_vat' => $this->prices_include_vat,
            'vat_rate' => (string) $this->vat_rate,
            'vat_amount' => (string) $this->vat_amount,
            'total' => (string) $this->total,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'amount_paid' => (string) $this->amount_paid,
            'payment_reference' => $this->payment_reference,
            'notes' => $this->notes,
            'reservation_id' => $this->reservation_id,
            'quotation_id' => $this->quotation_id,
            'user' => $this->whenLoaded('user', fn () => $this->user?->name),
            'user_id' => $this->user_id,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'voided_by' => $this->whenLoaded('voider', fn () => $this->voider?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => array_filter([
                'id' => $i->id,
                'item_type' => $i->item_type,
                'product_id' => $i->product_id,
                'tyre_unit_id' => $i->tyre_unit_id,
                'service_code' => $i->service_code,
                'description' => $i->description,
                'sku' => $i->sku,
                'size_display' => $i->size_display,
                'condition' => $i->condition,
                'brand_name' => $i->brand_name,
                'location' => $i->location?->name,
                'quantity' => $i->quantity,
                'returned_qty' => $i->returned_qty,
                'unit_price' => (string) $i->unit_price,
                'discount' => (string) $i->discount,
                'line_total' => (string) $i->line_total,
                'cost_total' => $financial ? (string) $i->cost_total : null,
            ], fn ($v) => $v !== null))),
            'returns' => $this->whenLoaded('returns', fn () => $this->returns->map(fn ($r) => [
                'id' => $r->id, 'return_no' => $r->return_no, 'status' => $r->status, 'reason' => $r->reason,
                'refund_amount' => (string) $r->refund_amount, 'created_at' => $r->created_at?->toIso8601String(),
                'items' => $r->items->map(fn ($ri) => [
                    'id' => $ri->id, 'sale_item_id' => $ri->sale_item_id, 'quantity' => $ri->quantity, 'outcome' => $ri->outcome,
                    'inspection_notes' => $ri->inspection_notes, 'inspected_at' => $ri->inspected_at?->toIso8601String(),
                ]),
            ])),
        ];
        if ($financial) {
            $data['cost_total'] = (string) $this->cost_total;
        }

        return $data;
    }
}
