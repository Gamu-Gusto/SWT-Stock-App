<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Services\NotificationService;
use App\Support\AppSettings;

/**
 * Raises / resolves low-stock and out-of-stock notifications when a product's available quantity changes.
 */
class StockAlertService
{
    public function __construct(protected NotificationService $notifications, protected AppSettings $settings) {}

    public function evaluate(Product $product, int $availableBefore): void
    {
        if ($product->status !== Product::STATUS_ACTIVE || ! $this->settings->get('notify_low_stock')) {
            return;
        }
        $available = $product->availableQty();
        $status = $product->stockStatus();
        $statusBefore = Product::statusFor($availableBefore, (int) $product->min_stock_level);
        $name = $product->displayName().($product->condition === 'used' ? ' (used)' : '');
        $link = "/inventory/{$product->id}";

        if ($status === 'in_stock') {
            $this->notifications->resolve("low_stock:{$product->id}");
            $this->notifications->resolve("out_of_stock:{$product->id}");

            return;
        }
        if ($status === $statusBefore) {
            return;
        }
        if ($status === 'out_of_stock') {
            $this->notifications->resolve("low_stock:{$product->id}");
            $this->notifications->raise("out_of_stock:{$product->id}", [
                'type' => 'out_of_stock',
                'severity' => 'danger',
                'title' => "Out of stock: {$name}",
                'message' => 'No tyres available to sell'.($product->reorder_qty ? ". Suggested reorder: {$product->reorder_qty}." : '.'),
                'link' => $link,
                'permission' => 'inventory.view',
            ], email: true);

            return;
        }
        $this->notifications->resolve("out_of_stock:{$product->id}");
        $this->notifications->raise("low_stock:{$product->id}", [
            'type' => 'low_stock',
            'severity' => 'warning',
            'title' => "Low stock: {$name}",
            'message' => "{$available} available (minimum {$product->min_stock_level})".($product->reorder_qty ? ", suggested reorder {$product->reorder_qty}." : '.'),
            'link' => $link,
            'permission' => 'inventory.view',
        ], email: true);
    }
}
