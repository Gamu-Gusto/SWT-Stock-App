<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TyreSpec extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'product_id';

    protected $fillable = ['product_id', 'width', 'profile', 'rim', 'size_display', 'size_key', 'load_index', 'speed_rating', 'tyre_type', 'run_flat'];

    protected $casts = ['width' => 'integer', 'profile' => 'integer', 'run_flat' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
