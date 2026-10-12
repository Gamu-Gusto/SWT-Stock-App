<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    protected $fillable = [
        'quotation_no', 'status', 'customer_name', 'customer_phone', 'customer_email', 'vehicle', 'valid_until',
        'subtotal', 'discount_total', 'vat_registered', 'prices_include_vat', 'vat_rate', 'vat_amount', 'total',
        'notes', 'idempotency_key', 'user_id',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'vat_registered' => 'boolean',
        'prices_include_vat' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
