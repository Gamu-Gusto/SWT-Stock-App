<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\InventoryLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\TyreUnit;
use App\Models\User;
use App\Support\Audit;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Moves stock between storage locations. The source decrease and destination increase happen in
 * one transaction; FIFO cost layers are unaffected because the stock never leaves the business.
 */
class TransferService
{
    public function __construct(protected StockLedger $ledger) {}

    /** @param list<array{product_id?:int, tyre_unit_id?:int, quantity?:int}> $items */
    public function transfer(int $fromId, int $toId, array $items, ?string $notes, User $user, ?string $idempotencyKey = null): StockTransfer
    {
        if ($fromId === $toId) {
            throw new InventoryException('Source and destination locations must be different.', 422, 'same_location');
        }
        if ($idempotencyKey && ($existing = StockTransfer::query()->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing->load('items');
        }

        return DB::transaction(function () use ($fromId, $toId, $items, $notes, $user, $idempotencyKey) {
            $from = InventoryLocation::query()->findOrFail($fromId);
            $to = InventoryLocation::query()->findOrFail($toId);
            if (! $to->is_active) {
                throw new InventoryException("{$to->name} is inactive.", 422, 'inactive_location');
            }

            $units = TyreUnit::query()->whereIn('id', array_filter(array_column($items, 'tyre_unit_id')))->get()->keyBy('id');
            $productIds = array_merge(array_filter(array_column($items, 'product_id')), $units->pluck('product_id')->all());
            $products = $this->ledger->lockProducts($productIds);

            $transfer = StockTransfer::query()->create([
                'transfer_no' => DocumentNumber::next('TRF'),
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'status' => 'completed',
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
                'user_id' => $user->id,
            ]);
            $ctx = ['reference' => $transfer, 'reference_no' => $transfer->transfer_no, 'reason' => $notes ?: "Transfer {$from->name} → {$to->name}", 'user_id' => $user->id];

            foreach ($items as $line) {
                if (! empty($line['tyre_unit_id'])) {
                    $unit = $units[$line['tyre_unit_id']] ?? throw new InventoryException('Tyre unit not found.', 404, 'not_found');
                    if ($unit->location_id !== $from->id || ! $unit->isPhysical()) {
                        throw new InventoryException("Used tyre {$unit->unit_code} is not in stock at {$from->name}.", 409, 'unit_not_here');
                    }
                    if ($unit->status === TyreUnit::RESERVED && ! $to->is_sellable) {
                        throw new InventoryException("Used tyre {$unit->unit_code} is reserved and cannot be moved to a non-sellable location.", 409, 'unit_reserved');
                    }
                    $this->ledger->moveUnit($products[$unit->product_id], $unit, $unit->status, $to, StockMovement::TRANSFER, $ctx);
                    $transfer->items()->create(['product_id' => $unit->product_id, 'tyre_unit_id' => $unit->id, 'quantity' => 1]);

                    continue;
                }
                $qty = (int) ($line['quantity'] ?? 0);
                if ($qty < 1) {
                    throw new InventoryException('Transfer quantity must be at least 1.', 422, 'invalid_quantity');
                }
                $product = $products[$line['product_id']];
                $this->ledger->adjust($product, $from, -$qty, StockMovement::TRANSFER, $ctx + ['costing' => 'none', 'to_location_id' => $to->id]);
                $this->ledger->adjust($product, $to, $qty, StockMovement::TRANSFER, $ctx + ['costing' => 'none', 'from_location_id' => $from->id]);
                $transfer->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
            }
            Audit::log('transfer.completed', $transfer, "Transfer {$transfer->transfer_no}: {$from->name} → {$to->name}");

            return $transfer->load('items');
        });
    }
}
