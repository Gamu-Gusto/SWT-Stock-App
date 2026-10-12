<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeCount extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['stocktake_item_id', 'counted_qty', 'system_qty_at_count', 'counted_by', 'note'];

    protected $casts = ['counted_qty' => 'integer', 'system_qty_at_count' => 'integer'];

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
