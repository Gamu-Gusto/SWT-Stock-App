<?php

namespace App\Services\Inventory;

use App\Models\Brand;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Stocktake;
use Illuminate\Database\Eloquent\Builder;

/**
 * Translates a stocktake's type + scope into product / location filters.
 *
 * scope keys: brand_ids (int[]), width, profile, rim (size), location_id (int|null)
 */
final class StocktakeScope
{
    public static function productQuery(Stocktake $stocktake): Builder
    {
        $scope = $stocktake->scope ?? [];
        $q = Product::query()->where('status', Product::STATUS_ACTIVE);

        return match ($stocktake->type) {
            'brand' => $q->whereIn('brand_id', $scope['brand_ids'] ?? [0]),
            'size' => $q->whereHas('spec', function (Builder $s) use ($scope) {
                if (! empty($scope['width'])) {
                    $s->where('width', $scope['width']);
                }
                if (! empty($scope['profile'])) {
                    $s->where('profile', $scope['profile']);
                }
                if (! empty($scope['rim'])) {
                    $s->where('rim', $scope['rim']);
                }
            }),
            'used' => $q->where('condition', 'used'),
            default => $q,
        };
    }

    public static function locationId(Stocktake $stocktake): ?int
    {
        $id = $stocktake->scope['location_id'] ?? null;

        return $id ? (int) $id : null;
    }

    public static function matches(Stocktake $stocktake, Product $product, InventoryLocation $location): bool
    {
        $locationId = self::locationId($stocktake);
        if ($locationId && $locationId !== $location->id) {
            return false;
        }
        $scope = $stocktake->scope ?? [];

        return match ($stocktake->type) {
            'brand' => in_array($product->brand_id, array_map('intval', $scope['brand_ids'] ?? []), true),
            'size' => self::sizeMatches($product, $scope),
            'used' => $product->condition === 'used',
            default => true,
        };
    }

    public static function describe(Stocktake $stocktake): string
    {
        $scope = $stocktake->scope ?? [];
        $parts = [match ($stocktake->type) {
            'full' => 'Full inventory',
            'brand' => 'Brands: '.Brand::query()->whereIn('id', $scope['brand_ids'] ?? [])->pluck('name')->implode(', '),
            'size' => 'Size: '.implode('/', array_filter([$scope['width'] ?? null, $scope['profile'] ?? null])).(! empty($scope['rim']) ? ' R'.$scope['rim'] : ''),
            'location' => 'Location stocktake',
            'used' => 'Used tyres',
            default => ucfirst($stocktake->type),
        }];
        if ($id = self::locationId($stocktake)) {
            $parts[] = 'at '.(InventoryLocation::query()->find($id)?->name ?? 'unknown location');
        }

        return implode(' ', $parts);
    }

    private static function sizeMatches(Product $product, array $scope): bool
    {
        $spec = $product->spec;
        if (! $spec) {
            return false;
        }
        if (! empty($scope['width']) && (int) $scope['width'] !== $spec->width) {
            return false;
        }
        if (! empty($scope['profile']) && (int) $scope['profile'] !== (int) $spec->profile) {
            return false;
        }
        if (! empty($scope['rim']) && (float) $scope['rim'] !== (float) $spec->rim) {
            return false;
        }

        return true;
    }
}
