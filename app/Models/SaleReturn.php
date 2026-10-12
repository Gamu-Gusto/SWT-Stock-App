<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    protected $fillable = ['return_no', 'sale_id', 'reason', 'status', 'refund_amount', 'idempotency_key', 'created_by'];

    protected $casts = ['refund_amount' => 'decimal:2'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        // Explicit order: PostgreSQL does not return rows in insertion order.
        return $this->hasMany(SaleReturnItem::class)->orderBy('sale_return_items.id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
