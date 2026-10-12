<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    protected $fillable = ['name', 'is_active', 'is_preferred', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'is_preferred' => 'boolean', 'archived_at' => 'datetime'];

    public function models(): HasMany
    {
        return $this->hasMany(TyreModel::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
