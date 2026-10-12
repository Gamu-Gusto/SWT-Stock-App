<?php

/**
 * Concurrency test worker: boots the application in a separate PHP process, waits for a shared
 * start time, then attempts one inventory operation. Prints a JSON result line.
 *
 * Usage: php tests/Concurrency/worker.php <action:sale|reserve> <productId> <userId> <quantity> <startAtMicrotime>
 */

use App\Exceptions\InventoryException;
use App\Models\User;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\SalesService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $action, $productId, $userId, $quantity, $startAt] = $argv;
$user = User::query()->findOrFail((int) $userId);

// Barrier: both workers start at the same instant.
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    if ($action === 'sale') {
        $sale = app(SalesService::class)->create([
            'items' => [['product_id' => (int) $productId, 'quantity' => (int) $quantity]],
            'payment_method' => 'cash',
        ], $user);
        echo json_encode(['ok' => true, 'id' => $sale->id]), PHP_EOL;
    } else {
        $reservation = app(ReservationService::class)->create([
            'customer_name' => 'Concurrent customer', 'customer_phone' => '0820000000',
            'items' => [['product_id' => (int) $productId, 'quantity' => (int) $quantity]],
        ], $user);
        echo json_encode(['ok' => true, 'id' => $reservation->id]), PHP_EOL;
    }
} catch (InventoryException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode(), 'message' => $e->getMessage()]), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'error', 'message' => get_class($e).': '.$e->getMessage()]), PHP_EOL;
}
