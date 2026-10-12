<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLotAllocation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['stock_lot_id', 'stock_movement_id', 'quantity', 'unit_cost'];

    protected $casts = ['unit_cost' => 'decimal:2', 'quantity' => 'integer'];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
