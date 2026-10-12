<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReceiptItem extends Model
{
    protected $fillable = ['stock_receipt_id', 'product_id', 'location_id', 'quantity', 'unit_cost', 'line_total', 'unit_details'];

    protected $casts = ['quantity' => 'integer', 'unit_cost' => 'decimal:2', 'line_total' => 'decimal:2', 'unit_details' => 'array'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockReceipt::class, 'stock_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }
}
