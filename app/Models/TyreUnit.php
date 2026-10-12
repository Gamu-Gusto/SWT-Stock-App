<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An individually identified (used) tyre.
 */
class TyreUnit extends Model
{
    public const PENDING = 'pending_inspection';

    public const AVAILABLE = 'available';

    public const RESERVED = 'reserved';

    public const SOLD = 'sold';

    public const REJECTED = 'rejected';

    public const WRITTEN_OFF = 'written_off';

    public const RETURNED_TO_SUPPLIER = 'returned_to_supplier';

    public const MISSING = 'missing';

    /** Statuses meaning the tyre is physically on the premises. */
    public const PHYSICAL = [self::PENDING, self::AVAILABLE, self::RESERVED, self::REJECTED];

    /** Physically present AND eligible to be sold or reserved (when in a sellable location). */
    public const SELLABLE = [self::AVAILABLE, self::RESERVED];

    // status/location_id are only changed through StockLedger and the inventory services.
    protected $fillable = [
        'unit_code', 'product_id', 'tread_depth_mm', 'grade', 'dot_code', 'defects', 'inspection_notes',
        'cost_price', 'selling_price', 'supplier_id', 'created_by',
    ];

    protected $casts = [
        'tread_depth_mm' => 'decimal:1',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'inspected_at' => 'datetime',
        'received_at' => 'datetime',
        'sold_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->where('collection', 'images');
    }

    public function isPhysical(): bool
    {
        return in_array($this->status, self::PHYSICAL, true);
    }
}
