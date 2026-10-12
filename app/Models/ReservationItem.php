<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['reservation_id', 'product_id', 'tyre_unit_id', 'quantity', 'fulfilled_qty', 'unit_price'];

    protected $casts = ['quantity' => 'integer', 'fulfilled_qty' => 'integer', 'unit_price' => 'decimal:2'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(TyreUnit::class, 'tyre_unit_id');
    }

    public function outstanding(): int
    {
        return max(0, $this->quantity - $this->fulfilled_qty);
    }
}
