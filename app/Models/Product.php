<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Product extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    // Quantity columns are deliberately NOT fillable: only StockLedger may change them.
    protected $fillable = [
        'sku', 'category_id', 'brand_id', 'tyre_model_id', 'name', 'description', 'condition', 'tracking',
        'variant_key', 'cost_price', 'selling_price', 'promo_price', 'min_selling_price', 'fitting_fee',
        'balancing_fee', 'alignment_fee', 'min_stock_level', 'reorder_qty', 'default_location_id',
        'default_supplier_id', 'is_public', 'status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'promo_price' => 'decimal:2',
        'min_selling_price' => 'decimal:2',
        'fitting_fee' => 'decimal:2',
        'balancing_fee' => 'decimal:2',
        'alignment_fee' => 'decimal:2',
        'qty_on_hand' => 'integer',
        'qty_reserved' => 'integer',
        'qty_unsellable' => 'integer',
        'min_stock_level' => 'integer',
        'reorder_qty' => 'integer',
        'is_public' => 'boolean',
        'last_received_at' => 'datetime',
        'last_sold_at' => 'datetime',
        'last_movement_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function tyreModel(): BelongsTo
    {
        return $this->belongsTo(TyreModel::class);
    }

    public function spec(): HasOne
    {
        return $this->hasOne(TyreSpec::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(TyreUnit::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'default_location_id');
    }

    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->where('collection', 'images');
    }

    public function isUnitTracked(): bool
    {
        return $this->tracking === 'unit';
    }

    /** Available = physical sellable quantity minus active reservations (never negative). */
    public function availableQty(): int
    {
        return max(0, $this->qty_on_hand - $this->qty_reserved);
    }

    /** in_stock | low_stock | out_of_stock — based on AVAILABLE quantity. */
    public function stockStatus(): string
    {
        return self::statusFor($this->availableQty(), (int) $this->min_stock_level);
    }

    public static function statusFor(int $available, int $minLevel): string
    {
        if ($available <= 0) {
            return 'out_of_stock';
        }
        if ($minLevel > 0 && $available <= $minLevel) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    /** Effective price per tyre (a positive promotional price wins when it is lower). */
    public function effectivePrice(): string
    {
        $promo = $this->promo_price;
        if ($promo !== null && Money::cmp($promo, '0') > 0 && Money::cmp($promo, $this->selling_price) < 0) {
            return Money::normalize($promo);
        }

        return Money::normalize($this->selling_price);
    }

    public function displayName(): string
    {
        return implode(' ', array_filter([
            $this->brand?->name,
            $this->tyreModel?->name ?? $this->name,
            $this->spec?->size_display,
        ]));
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }
}
