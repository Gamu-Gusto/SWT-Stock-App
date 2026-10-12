<?php

namespace App\Models;

use App\Exceptions\InventoryException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InventoryLocation extends Model
{
    protected $fillable = ['code', 'name', 'type', 'is_sellable', 'is_active', 'sort_order', 'notes'];

    protected $casts = ['is_sellable' => 'boolean', 'is_active' => 'boolean'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** First active non-sellable location of the given type (quarantine / damaged). */
    public static function holding(string $type): self
    {
        $location = static::query()->where('type', $type)->where('is_sellable', false)->where('is_active', true)->orderBy('id')->first();
        if (! $location) {
            throw new InventoryException("No active '{$type}' location is configured. Create one under Settings → Locations.");
        }

        return $location;
    }
}
