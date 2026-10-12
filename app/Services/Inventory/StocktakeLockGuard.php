<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Support\AppSettings;

/**
 * Stocktake locking policy.
 *
 * While a stocktake is IN PROGRESS, every product/location inside its scope is "under count".
 *  - mode "block" (default): sales, receipts, transfers and adjustments touching that stock are rejected
 *    with a clear message naming the stocktake. Counting is then guaranteed to compare like with like.
 *  - mode "track": transactions are allowed. Each count records the live system quantity at the moment
 *    of counting (system_qty_at_count) so variances remain correct, and the review screen lists the
 *    movements that happened during the count.
 * Approved stocktake adjustments themselves are always allowed.
 */
class StocktakeLockGuard
{
    public function __construct(protected AppSettings $settings) {}

    public function assertAllowed(Product $product, InventoryLocation $location, string $movementType): void
    {
        if ($movementType === StockMovement::STOCKTAKE_ADJUSTMENT || $this->settings->get('stocktake_lock_mode') !== 'block') {
            return;
        }
        if ($stocktake = $this->blockingStocktake($product, $location)) {
            throw new InventoryException(
                "{$product->displayName()} at {$location->name} is locked by stocktake {$stocktake->stocktake_no} ({$stocktake->title}), which is in progress. Complete or submit the count first.",
                423,
                'stocktake_locked',
                ['stocktake_id' => $stocktake->id, 'stocktake_no' => $stocktake->stocktake_no],
            );
        }
    }

    public function blockingStocktake(Product $product, InventoryLocation $location): ?Stocktake
    {
        $active = Stocktake::query()->where('status', Stocktake::IN_PROGRESS)->get();
        foreach ($active as $stocktake) {
            $hasItem = $stocktake->items()->where('product_id', $product->id)->where('location_id', $location->id)->exists();
            if ($hasItem || StocktakeScope::matches($stocktake, $product, $location)) {
                return $stocktake;
            }
        }

        return null;
    }
}
