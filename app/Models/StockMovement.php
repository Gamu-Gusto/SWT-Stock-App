<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Immutable inventory ledger entry. Created exclusively by App\Services\Inventory\StockLedger.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const OPENING_BALANCE = 'opening_balance';

    public const RECEIVED = 'stock_received';

    public const RECEIPT_REVERSAL = 'receipt_reversal';

    public const SALE = 'sale';

    public const SALE_VOID = 'sale_void';

    public const CUSTOMER_RETURN = 'customer_return';

    public const SUPPLIER_RETURN = 'supplier_return';

    public const MANUAL_ADJUSTMENT = 'manual_adjustment';

    public const STOCKTAKE_ADJUSTMENT = 'stocktake_adjustment';

    public const TRANSFER = 'stock_transfer';

    public const DAMAGED = 'damaged_stock';

    public const WRITE_OFF = 'write_off';

    public const TYPES = [
        self::OPENING_BALANCE => 'Opening Balance',
        self::RECEIVED => 'Stock Received',
        self::RECEIPT_REVERSAL => 'Receipt Reversal',
        self::SALE => 'Sale',
        self::SALE_VOID => 'Sale Void',
        self::CUSTOMER_RETURN => 'Customer Return',
        self::SUPPLIER_RETURN => 'Supplier Return',
        self::MANUAL_ADJUSTMENT => 'Manual Adjustment',
        self::STOCKTAKE_ADJUSTMENT => 'Stocktake Adjustment',
        self::TRANSFER => 'Stock Transfer',
        self::DAMAGED => 'Damaged Stock',
        self::WRITE_OFF => 'Write Off',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'quantity_change' => 'integer',
        'previous_qty' => 'integer',
        'resulting_qty' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are immutable and cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Stock movements are immutable and cannot be deleted.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(TyreUnit::class, 'tyre_unit_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'to_location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StockLotAllocation::class);
    }

    public function movementNo(): string
    {
        return 'MV-'.str_pad((string) $this->id, 7, '0', STR_PAD_LEFT);
    }
}
