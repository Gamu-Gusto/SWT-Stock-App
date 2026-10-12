<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ReservationItem;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockLotAllocation;
use App\Models\StockMovement;
use App\Models\TyreUnit;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single gateway for every physical inventory change.
 *
 * Rules enforced here:
 *  - callers must run inside a DB transaction and lock the affected products first (lockProducts);
 *  - location balances and product totals can never become negative;
 *  - reserved quantity can never exceed sellable physical quantity;
 *  - every change writes an immutable StockMovement with previous/resulting quantities;
 *  - FIFO cost layers (stock_lots) are created on increases and consumed on decreases;
 *  - the stocktake locking policy is applied to every movement.
 */
class StockLedger
{
    public function __construct(
        protected StocktakeLockGuard $lockGuard,
        protected StockAlertService $alerts,
    ) {}

    /**
     * Lock product rows (ascending id order to avoid deadlocks) and return fresh models keyed by id.
     *
     * @param  iterable<int>  $productIds
     * @return Collection<int, Product>
     */
    public function lockProducts(iterable $productIds): Collection
    {
        $this->assertInTransaction();
        $ids = collect($productIds)->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== $ids->count()) {
            throw new InventoryException('One or more products could not be found.', 404, 'not_found');
        }

        return $products;
    }

    public function lockProduct(int $productId): Product
    {
        return $this->lockProducts([$productId])->first();
    }

    /**
     * Change the quantity of a quantity-tracked product at a location.
     *
     * Context keys:
     *  reference (Model), reference_no, reason, user_id, approved_by, from_location_id, to_location_id,
     *  costing: 'fifo' (default; create lot on increase / consume on decrease) | 'none' (transfers),
     *  unit_cost (increase lot cost; defaults to product cost price), lot_source, supplier_id,
     *  stock_receipt_item_id, prefer_lot_ids (consume these lots first), restore_layers (list of [unit_cost, qty]).
     */
    public function adjust(Product $product, InventoryLocation $location, int $delta, string $type, array $ctx = []): StockMovement
    {
        $this->assertInTransaction();
        if ($product->isUnitTracked()) {
            throw new InventoryException('Individually tracked tyres must be moved per unit.', 422, 'unit_tracked');
        }
        if ($delta === 0) {
            throw new InventoryException('Quantity change cannot be zero.', 422, 'zero_quantity');
        }
        $this->lockGuard->assertAllowed($product, $location, $type);

        $balance = $this->lockBalance($product->id, $location->id);
        $previous = $balance->qty_on_hand;
        $resulting = $previous + $delta;
        if ($resulting < 0) {
            throw InventoryException::insufficientStock(
                $product->displayName().' at '.$location->name,
                abs($delta),
                $previous,
            );
        }
        $balance->qty_on_hand = $resulting;
        $balance->save();

        $movement = $this->recordMovement($product, $location, $delta, $previous, $resulting, $type, $ctx);

        $costing = $ctx['costing'] ?? 'fifo';
        if ($costing === 'fifo') {
            if ($delta > 0) {
                $this->addCostLayers($product, $movement, $delta, $ctx);
            } else {
                $this->consumeCostLayers($product, $movement, abs($delta), $ctx['prefer_lot_ids'] ?? []);
            }
        }

        $this->refreshProductTotals($product, $type === StockMovement::RECEIVED);

        return $movement;
    }

    /**
     * Change the status and/or location of an individually tracked tyre. Writes -1/+1 movements
     * whenever the tyre leaves or enters a location's physical balance.
     *
     * @return list<StockMovement>
     */
    public function moveUnit(Product $product, TyreUnit $unit, string $newStatus, ?InventoryLocation $toLocation, string $type, array $ctx = []): array
    {
        $this->assertInTransaction();
        $unit = TyreUnit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();

        $wasPhysical = $unit->isPhysical();
        $willBePhysical = in_array($newStatus, TyreUnit::PHYSICAL, true);
        $fromLocationId = $unit->location_id;
        $toLocationId = $willBePhysical ? ($toLocation?->id ?? $fromLocationId) : null;

        if ($willBePhysical && ! $toLocationId) {
            throw new InventoryException('A storage location is required for this tyre.', 422, 'location_required');
        }

        $movements = [];
        $leaves = $wasPhysical && (! $willBePhysical || $toLocationId !== $fromLocationId);
        $enters = $willBePhysical && (! $wasPhysical || $toLocationId !== $fromLocationId);
        $cost = Money::normalize($unit->cost_price);

        if ($leaves) {
            $from = InventoryLocation::query()->findOrFail($fromLocationId);
            $this->lockGuard->assertAllowed($product, $from, $type);
            $balance = $this->lockBalance($product->id, $from->id);
            if ($balance->qty_on_hand < 1) {
                throw new InventoryException("Balance for {$unit->unit_code} at {$from->name} is inconsistent.", 409, 'balance_mismatch');
            }
            $prev = $balance->qty_on_hand;
            $balance->decrement('qty_on_hand');
            $movements[] = $this->recordMovement($product, $from, -1, $prev, $prev - 1, $type, $ctx + [
                'tyre_unit_id' => $unit->id,
                'to_location_id' => $enters ? $toLocationId : ($ctx['to_location_id'] ?? null),
                'unit_cost' => $cost,
            ]);
        }

        if ($enters) {
            $to = $toLocation ?? InventoryLocation::query()->findOrFail($toLocationId);
            $this->lockGuard->assertAllowed($product, $to, $type);
            $balance = $this->lockBalance($product->id, $to->id);
            $prev = $balance->qty_on_hand;
            $balance->increment('qty_on_hand');
            $movements[] = $this->recordMovement($product, $to, 1, $prev, $prev + 1, $type, $ctx + [
                'tyre_unit_id' => $unit->id,
                'from_location_id' => $leaves ? $fromLocationId : ($ctx['from_location_id'] ?? null),
                'unit_cost' => $cost,
            ]);
        }

        $unit->forceFill([
            'status' => $newStatus,
            'location_id' => $toLocationId ?? ($willBePhysical ? $fromLocationId : $unit->location_id),
            'sold_at' => $newStatus === TyreUnit::SOLD ? now() : ($newStatus === TyreUnit::AVAILABLE ? null : $unit->sold_at),
        ])->save();

        $this->refreshProductTotals($product, $type === StockMovement::RECEIVED);

        return $movements;
    }

    /**
     * Recalculate the cached product totals from the authoritative balances / units / reservations,
     * enforcing the reserved <= sellable invariant.
     */
    public function refreshProductTotals(Product $product, bool $received = false): void
    {
        $before = $product->availableQty();

        if ($product->isUnitTracked()) {
            $sellable = TyreUnit::query()->where('product_id', $product->id)
                ->whereIn('status', TyreUnit::SELLABLE)
                ->whereHas('location', fn ($q) => $q->where('is_sellable', true))->count();
            $physical = TyreUnit::query()->where('product_id', $product->id)->whereIn('status', TyreUnit::PHYSICAL)->count();
            $unsellable = $physical - $sellable;
        } else {
            $rows = StockBalance::query()->join('inventory_locations as l', 'l.id', '=', 'stock_balances.location_id')
                ->where('stock_balances.product_id', $product->id)
                ->selectRaw('l.is_sellable as sellable, SUM(stock_balances.qty_on_hand) as qty')
                ->groupBy('l.is_sellable')->get();
            $sellable = (int) $rows->first(fn ($r) => (bool) $r->sellable)?->qty;
            $unsellable = (int) $rows->first(fn ($r) => ! (bool) $r->sellable)?->qty;
        }

        $reserved = (int) ReservationItem::query()
            ->join('reservations as r', 'r.id', '=', 'reservation_items.reservation_id')
            ->where('r.status', 'active')
            ->where('reservation_items.product_id', $product->id)
            ->sum(DB::raw('reservation_items.quantity - reservation_items.fulfilled_qty'));

        if ($reserved > $sellable) {
            throw new InventoryException(
                "This change would leave {$reserved} reserved tyre(s) of {$product->displayName()} with only {$sellable} in sellable stock. Release or fulfil the reservation first.",
                409,
                'reserved_exceeds_stock',
                ['reserved' => $reserved, 'on_hand' => $sellable],
            );
        }

        $product->forceFill([
            'qty_on_hand' => $sellable,
            'qty_unsellable' => $unsellable,
            'qty_reserved' => $reserved,
            'last_movement_at' => now(),
            'last_received_at' => $received ? now() : $product->last_received_at,
        ])->saveQuietly();

        $this->alerts->evaluate($product, $before);
    }

    protected function lockBalance(int $productId, int $locationId): StockBalance
    {
        StockBalance::query()->insertOrIgnore([
            'product_id' => $productId,
            'location_id' => $locationId,
            'qty_on_hand' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StockBalance::query()->where('product_id', $productId)->where('location_id', $locationId)->lockForUpdate()->firstOrFail();
    }

    protected function recordMovement(Product $product, InventoryLocation $location, int $delta, int $previous, int $resulting, string $type, array $ctx): StockMovement
    {
        if (! array_key_exists($type, StockMovement::TYPES)) {
            throw new InventoryException("Unknown movement type [{$type}].", 422, 'invalid_type');
        }
        $reference = $ctx['reference'] ?? null;
        $unitCost = isset($ctx['unit_cost']) ? Money::normalize($ctx['unit_cost']) : null;

        return StockMovement::query()->create([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->id,
            'tyre_unit_id' => $ctx['tyre_unit_id'] ?? null,
            'location_id' => $location->id,
            'type' => $type,
            'quantity_change' => $delta,
            'previous_qty' => $previous,
            'resulting_qty' => $resulting,
            'from_location_id' => $ctx['from_location_id'] ?? ($delta < 0 ? $location->id : null),
            'to_location_id' => $ctx['to_location_id'] ?? ($delta > 0 ? $location->id : null),
            'reference_type' => $reference ? class_basename($reference) : ($ctx['reference_type'] ?? null),
            'reference_id' => $reference?->getKey() ?? ($ctx['reference_id'] ?? null),
            'reference_no' => $ctx['reference_no'] ?? null,
            'reason' => isset($ctx['reason']) ? mb_substr((string) $ctx['reason'], 0, 500) : null,
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost !== null ? Money::mul($unitCost, abs($delta)) : null,
            'user_id' => $ctx['user_id'] ?? auth()->id(),
            'approved_by' => $ctx['approved_by'] ?? null,
            'created_at' => now(),
        ]);
    }

    protected function addCostLayers(Product $product, StockMovement $movement, int $qty, array $ctx): void
    {
        // Restoring previously sold stock (voids/returns) re-creates the original cost layers.
        $defaultCost = $ctx['unit_cost'] ?? $product->cost_price;
        $layers = $ctx['restore_layers'] ?? [];
        $layered = array_sum(array_map(fn ($l) => (int) $l[1], $layers));
        if ($layered < $qty) {
            $layers[] = [$defaultCost, $qty - $layered];
        }
        $total = 0;
        $remaining = $qty;
        foreach ($layers as [$cost, $layerQty]) {
            $layerQty = min((int) $layerQty, $remaining);
            if ($layerQty <= 0) {
                continue;
            }
            StockLot::query()->create([
                'product_id' => $product->id,
                'source_type' => $ctx['lot_source'] ?? $movement->type,
                'source_id' => $movement->id,
                'stock_receipt_item_id' => $ctx['stock_receipt_item_id'] ?? null,
                'supplier_id' => $ctx['supplier_id'] ?? null,
                'unit_cost' => Money::normalize($cost),
                'qty_received' => $layerQty,
                'qty_remaining' => $layerQty,
                'received_at' => now(),
            ]);
            $total += Money::toCents($cost) * $layerQty;
            $remaining -= $layerQty;
        }

        $movement->forceFill([
            'unit_cost' => Money::fromCents(Money::divRound($total, $qty)),
            'total_cost' => Money::fromCents($total),
        ]);
        DB::table('stock_movements')->where('id', $movement->id)->update([
            'unit_cost' => $movement->unit_cost,
            'total_cost' => $movement->total_cost,
        ]);
    }

    /** Consume FIFO layers; records allocations so sales margins use historical cost. */
    protected function consumeCostLayers(Product $product, StockMovement $movement, int $qty, array $preferLotIds = []): void
    {
        $lots = StockLot::query()->where('product_id', $product->id)->where('qty_remaining', '>', 0)
            ->when($preferLotIds, fn ($q) => $q->orderByRaw('CASE WHEN id IN ('.implode(',', array_map('intval', $preferLotIds)).') THEN 0 ELSE 1 END'))
            ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();

        $remaining = $qty;
        $totalCents = 0;
        foreach ($lots as $lot) {
            if ($remaining === 0) {
                break;
            }
            $take = min($remaining, $lot->qty_remaining);
            $lot->decrement('qty_remaining', $take);
            StockLotAllocation::query()->create([
                'stock_lot_id' => $lot->id,
                'stock_movement_id' => $movement->id,
                'quantity' => $take,
                'unit_cost' => $lot->unit_cost,
            ]);
            $totalCents += Money::toCents($lot->unit_cost) * $take;
            $remaining -= $take;
        }
        if ($remaining > 0) {
            // Cost layers exhausted (e.g. legacy data): fall back to the product's standard cost.
            $totalCents += Money::toCents($product->cost_price) * $remaining;
        }

        DB::table('stock_movements')->where('id', $movement->id)->update([
            'unit_cost' => Money::fromCents(Money::divRound($totalCents, $qty)),
            'total_cost' => Money::fromCents($totalCents),
        ]);
        $movement->forceFill([
            'unit_cost' => Money::fromCents(Money::divRound($totalCents, $qty)),
            'total_cost' => Money::fromCents($totalCents),
        ]);
    }

    /** @return list<array{0:string,1:int}> cost layers consumed by a movement, for restoring on void/return */
    public function layersFor(StockMovement $movement): array
    {
        return $movement->allocations()->get()->map(fn ($a) => [Money::normalize($a->unit_cost), $a->quantity])->all();
    }

    protected function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Inventory changes must run inside a database transaction.');
        }
    }
}
