<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdjustmentRequest extends Model
{
    public const TYPES = ['manual_adjustment', 'damaged', 'write_off', 'supplier_return'];

    protected $fillable = ['adjustment_no', 'type', 'product_id', 'tyre_unit_id', 'location_id', 'supplier_id', 'quantity_change', 'reason', 'status', 'idempotency_key', 'requested_by'];

    protected $casts = ['quantity_change' => 'integer', 'decided_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(TyreUnit::class, 'tyre_unit_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
