<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    public const TYRES = 'tyres';

    protected $fillable = ['slug', 'name', 'has_tyre_specs', 'is_active'];

    protected $casts = ['has_tyre_specs' => 'boolean', 'is_active' => 'boolean'];
}
