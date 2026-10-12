<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIFO cost layer for quantity-tracked stock.
 */
class StockLot extends Model
{
    protected $fillable = ['product_id', 'source_type', 'source_id', 'stock_receipt_item_id', 'supplier_id', 'unit_cost', 'qty_received', 'qty_remaining', 'received_at'];

    protected $casts = ['unit_cost' => 'decimal:2', 'qty_received' => 'integer', 'qty_remaining' => 'integer', 'received_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
