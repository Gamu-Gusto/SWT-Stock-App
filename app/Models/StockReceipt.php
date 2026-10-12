<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class StockReceipt extends Model
{
    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const REVERSED = 'reversed';

    protected $fillable = ['receipt_no', 'supplier_id', 'supplier_reference', 'delivery_date', 'status', 'notes', 'total_cost', 'total_quantity', 'idempotency_key', 'created_by'];

    protected $casts = [
        'delivery_date' => 'date',
        'total_cost' => 'decimal:2',
        'total_quantity' => 'integer',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        // Explicit order: PostgreSQL does not return rows in insertion order.
        return $this->hasMany(StockReceiptItem::class)->orderBy('stock_receipt_items.id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->where('collection', 'documents');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
