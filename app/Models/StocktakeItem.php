<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StocktakeItem extends Model
{
    protected $fillable = [
        'stocktake_id', 'product_id', 'location_id', 'tyre_unit_id', 'line_key', 'snapshot_qty', 'unit_cost', 'is_unexpected', 'status',
    ];

    protected $casts = [
        'snapshot_qty' => 'integer',
        'system_qty_at_count' => 'integer',
        'counted_qty' => 'integer',
        'variance' => 'integer',
        'unit_cost' => 'decimal:2',
        'cost_impact' => 'decimal:2',
        'count_version' => 'integer',
        'is_unexpected' => 'boolean',
        'counted_at' => 'datetime',
    ];

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(TyreUnit::class, 'tyre_unit_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function counts(): HasMany
    {
        return $this->hasMany(StocktakeCount::class);
    }

    public static function lineKey(int $productId, int $locationId, ?int $unitId = null): string
    {
        return $productId.':'.$locationId.($unitId ? ':'.$unitId : '');
    }
}
