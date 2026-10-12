<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    protected $fillable = ['reservation_no', 'status', 'customer_name', 'customer_phone', 'notes', 'expires_at', 'idempotency_key', 'user_id', 'quotation_id'];

    protected $casts = ['expires_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function items(): HasMany
    {
        // Explicit order: PostgreSQL does not return rows in insertion order.
        return $this->hasMany(ReservationItem::class)->orderBy('reservation_items.id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
