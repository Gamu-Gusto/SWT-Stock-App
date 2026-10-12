<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['sale_return_id', 'sale_item_id', 'product_id', 'tyre_unit_id', 'quantity', 'unit_cost', 'outcome', 'restock_location_id', 'inspection_notes'];

    protected $casts = ['quantity' => 'integer', 'unit_cost' => 'decimal:2', 'inspected_at' => 'datetime'];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(TyreUnit::class, 'tyre_unit_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
