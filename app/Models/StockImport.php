<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockImport extends Model
{
    protected $fillable = ['import_no', 'original_name', 'path', 'status', 'headers', 'mapping', 'duplicate_mode', 'summary', 'user_id'];

    protected $casts = ['headers' => 'array', 'mapping' => 'array', 'summary' => 'array', 'completed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
