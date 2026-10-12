<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    public const DRAFT = 'draft';

    public const COMPLETED = 'completed';

    public const VOIDED = 'voided';

    public const RETURNED = 'returned';

    public const PARTIALLY_RETURNED = 'partially_returned';

    public const PAYMENT_METHODS = ['cash', 'card', 'eft'];

    protected $fillable = [
        'sale_no', 'status', 'customer_name', 'customer_phone', 'customer_email', 'vehicle', 'subtotal',
        'discount_total', 'vat_registered', 'prices_include_vat', 'vat_rate', 'vat_amount', 'total', 'cost_total',
        'payment_method', 'payment_status', 'amount_paid', 'payment_reference', 'notes', 'idempotency_key',
        'user_id', 'reservation_id', 'quotation_id',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'cost_total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'vat_registered' => 'boolean',
        'prices_include_vat' => 'boolean',
        'completed_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        // Explicit order: PostgreSQL does not return rows in insertion order.
        return $this->hasMany(SaleItem::class)->orderBy('sale_items.id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
