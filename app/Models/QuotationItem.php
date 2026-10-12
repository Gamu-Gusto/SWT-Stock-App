<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['quotation_id', 'item_type', 'product_id', 'tyre_unit_id', 'service_code', 'description', 'size_display', 'quantity', 'unit_price', 'discount', 'line_total'];

    protected $casts = ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'line_total' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(TyreUnit::class, 'tyre_unit_id');
    }
}
