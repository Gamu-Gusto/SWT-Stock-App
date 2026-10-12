<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StocktakeCount;
use App\Models\StocktakeItem;
use App\Models\TyreUnit;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Audit;
use App\Support\DocumentNumber;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Physical stocktaking.
 *
 * Lifecycle: draft → in_progress → submitted → under_review → approved → closed (or cancelled).
 *  - Starting takes a snapshot of system quantities for every product/location in scope.
 *  - Each count stores the live system quantity at the moment of counting; variance = counted − that.
 *  - Counts never change stock. Only approval posts STOCKTAKE_ADJUSTMENT movements via the ledger.
 *  - A product/location can be counted once; replacing a count needs explicit confirmation before
 *    submission, or a recount requested by a reviewer afterwards. Every count is kept in history.
 */
class StocktakeService
{
    public function __construct(protected StockLedger $ledger, protected NotificationService $notifications) {}

    public function create(array $data, User $user): Stocktake
    {
        return DB::transaction(function () use ($data, $user) {
            $type = $data['type'];
            if (! in_array($type, Stocktake::TYPES, true)) {
                throw new InventoryException('Invalid stocktake type.', 422, 'invalid_type');
            }
            $scope = array_filter([
                'brand_ids' => $type === 'brand' ? array_map('intval', $data['brand_ids'] ?? []) : null,
                'width' => $type === 'size' ? ($data['width'] ?? null) : null,
                'profile' => $type === 'size' ? ($data['profile'] ?? null) : null,
                'rim' => $type === 'size' ? ($data['rim'] ?? null) : null,
                'location_id' => $data['location_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== [] && $v !== '');
            if ($type === 'location' && empty($scope['location_id'])) {
                throw new InventoryException('Select the storage location to count.', 422, 'location_required');
            }
            if ($type === 'brand' && empty($scope['brand_ids'])) {
                throw new InventoryException('Select at least one brand to count.', 422, 'brand_required');
            }
            if ($type === 'size' && empty($scope['width']) && empty($scope['rim'])) {
                throw new InventoryException('Enter the tyre size (at least width or rim) to count.', 422, 'size_required');
            }

            $stocktake = Stocktake::query()->create([
                'stocktake_no' => DocumentNumber::next('ST'),
                'title' => $data['title'] ?? ucfirst($type).' stocktake',
                'type' => $type,
                'scope' => $scope ?: null,
                'is_blind' => (bool) ($data['is_blind'] ?? true),
                'status' => Stocktake::DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            $stocktake->assignees()->sync($data['assignee_ids'] ?? []);
            Audit::log('stocktake.created', $stocktake, "Created stocktake {$stocktake->stocktake_no}: ".StocktakeScope::describe($stocktake));

            return $stocktake;
        });
    }

    /** Start counting: snapshot expected quantities and activate the location lock. */
    public function start(Stocktake $stocktake, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake) {
            $stocktake = $this->lockStocktake($stocktake, [Stocktake::DRAFT]);
            if ($stocktake->assignees()->doesntExist()) {
                throw new InventoryException('Assign at least one employee to count before starting.', 422, 'no_assignees');
            }
            $locationId = StocktakeScope::locationId($stocktake);
            $productIds = StocktakeScope::productQuery($stocktake)->pluck('id');

            // Quantity-tracked stock: one line per product/location with stock.
            $balances = StockBalance::query()->whereIn('product_id', $productIds)->where('qty_on_hand', '>', 0)
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->whereHas('product', fn ($q) => $q->where('tracking', 'quantity'))->get();
            foreach ($balances as $balance) {
                $stocktake->items()->create([
                    'product_id' => $balance->product_id,
                    'location_id' => $balance->location_id,
                    'line_key' => StocktakeItem::lineKey($balance->product_id, $balance->location_id),
                    'snapshot_qty' => $balance->qty_on_hand,
                    'unit_cost' => $this->averageCost($balance->product_id),
                    'status' => 'pending',
                ]);
            }

            // Individually tracked used tyres: one line per physical unit.
            $units = TyreUnit::query()->whereIn('product_id', $productIds)->whereIn('status', TyreUnit::PHYSICAL)
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))->get();
            foreach ($units as $unit) {
                $stocktake->items()->create([
                    'product_id' => $unit->product_id,
                    'location_id' => $unit->location_id,
                    'tyre_unit_id' => $unit->id,
                    'line_key' => StocktakeItem::lineKey($unit->product_id, $unit->location_id, $unit->id),
                    'snapshot_qty' => 1,
                    'unit_cost' => $unit->cost_price,
                    'status' => 'pending',
                ]);
            }

            $stocktake->forceFill([
                'status' => Stocktake::IN_PROGRESS,
                'started_at' => now(),
                'start_movement_id' => (int) StockMovement::query()->max('id'),
            ])->save();
            foreach ($stocktake->assignees as $assignee) {
                $this->notifications->raise("stocktake:{$stocktake->id}:count:{$assignee->id}", [
                    'type' => 'pending_stocktake',
                    'severity' => 'info',
                    'title' => "Stocktake {$stocktake->stocktake_no} assigned to you",
                    'message' => $stocktake->title,
                    'link' => "/stocktakes/{$stocktake->id}/count",
                    'user_id' => $assignee->id,
                ]);
            }
            Audit::log('stocktake.started', $stocktake, "Started stocktake {$stocktake->stocktake_no} with {$stocktake->items()->count()} lines");

            return $stocktake->fresh();
        });
    }

    /**
     * Record a physical count.
     *
     * $data: item_id | (product_id + location_id) | (unit_code + location_id), counted_qty, replace (bool), note
     */
    public function count(Stocktake $stocktake, array $data, User $user): StocktakeItem
    {
        return DB::transaction(function () use ($stocktake, $data, $user) {
            $stocktake = $this->lockStocktake($stocktake, [Stocktake::IN_PROGRESS]);
            $item = $this->resolveItem($stocktake, $data);
            $item = StocktakeItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $counted = (int) $data['counted_qty'];
            if ($counted < 0 || ($item->tyre_unit_id && $counted > 1)) {
                throw new InventoryException($item->tyre_unit_id ? 'A used tyre is counted as 1 (found) or 0 (not found).' : 'Counted quantity cannot be negative.', 422, 'invalid_count');
            }
            if (in_array($item->status, ['approved', 'rejected'], true)) {
                throw InventoryException::invalidState('This line has already been reviewed.');
            }
            if ($item->status === 'counted' && empty($data['replace'])) {
                throw new InventoryException(
                    "This tyre was already counted at this location ({$item->counted_qty}). Confirm to replace the earlier count.",
                    409,
                    'duplicate_count',
                    ['item_id' => $item->id, 'counted_qty' => $item->counted_qty, 'counted_at' => $item->counted_at?->toIso8601String()],
                );
            }

            $systemQty = $this->currentSystemQty($item);
            $variance = $counted - $systemQty;
            $item->forceFill([
                'system_qty_at_count' => $systemQty,
                'counted_qty' => $counted,
                'variance' => $variance,
                'cost_impact' => Money::mul($item->unit_cost ?? '0', $variance),
                'counted_by' => $user->id,
                'counted_at' => now(),
                'count_version' => $item->count_version + 1,
                'status' => 'counted',
            ])->save();
            StocktakeCount::query()->create([
                'stocktake_item_id' => $item->id,
                'counted_qty' => $counted,
                'system_qty_at_count' => $systemQty,
                'counted_by' => $user->id,
                'note' => $data['note'] ?? null,
            ]);

            return $item->fresh(['product.brand', 'product.tyreModel', 'product.spec', 'location', 'unit']);
        });
    }

    /** Submit counts for review. Uncounted lines must be counted, or explicitly submitted as zero. */
    public function submit(Stocktake $stocktake, User $user, bool $uncountedAsZero = false): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $user, $uncountedAsZero) {
            $stocktake = $this->lockStocktake($stocktake, [Stocktake::IN_PROGRESS]);
            $uncounted = $stocktake->items()->whereIn('status', ['pending', 'recount_requested'])->get();
            if ($uncounted->isNotEmpty() && ! $uncountedAsZero) {
                throw new InventoryException(
                    "{$uncounted->count()} line(s) have not been counted. Count them, or confirm that they were not found (counted as zero).",
                    422,
                    'uncounted_lines',
                    ['uncounted' => $uncounted->count()],
                );
            }
            foreach ($uncounted as $item) {
                $system = $this->currentSystemQty($item);
                $item->forceFill([
                    'system_qty_at_count' => $system, 'counted_qty' => 0, 'variance' => -$system,
                    'cost_impact' => Money::mul($item->unit_cost ?? '0', -$system), 'counted_by' => $user->id,
                    'counted_at' => now(), 'count_version' => $item->count_version + 1, 'status' => 'counted',
                ])->save();
                StocktakeCount::query()->create(['stocktake_item_id' => $item->id, 'counted_qty' => 0, 'system_qty_at_count' => $system, 'counted_by' => $user->id, 'note' => 'Not found – submitted as zero']);
            }

            $stocktake->forceFill(['status' => Stocktake::SUBMITTED, 'submitted_at' => now(), 'submitted_by' => $user->id])->save();
            foreach ($stocktake->assignees as $assignee) {
                $this->notifications->resolve("stocktake:{$stocktake->id}:count:{$assignee->id}");
            }
            $this->notifications->raise("stocktake:{$stocktake->id}:review", [
                'type' => 'pending_approval',
                'severity' => 'warning',
                'title' => "Stocktake {$stocktake->stocktake_no} submitted for review",
                'message' => $stocktake->items()->where('variance', '!=', 0)->count().' line(s) with discrepancies',
                'link' => "/stocktakes/{$stocktake->id}/review",
                'permission' => 'stocktake.approve',
            ]);
            Audit::log('stocktake.submitted', $stocktake, "Submitted stocktake {$stocktake->stocktake_no}");

            return $stocktake->fresh();
        });
    }

    public function startReview(Stocktake $stocktake, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $user) {
            $stocktake = $this->lockStocktake($stocktake, [Stocktake::SUBMITTED]);
            $stocktake->forceFill(['status' => Stocktake::UNDER_REVIEW, 'reviewed_by' => $user->id, 'review_started_at' => now()])->save();
            Audit::log('stocktake.review_started', $stocktake, "Review of {$stocktake->stocktake_no} started");

            return $stocktake;
        });
    }

    /** Send selected lines back for recounting; the stocktake returns to in-progress (and is locked again). */
    public function requestRecount(Stocktake $stocktake, array $itemIds, string $reason, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $itemIds, $reason) {
            $stocktake = $this->lockStocktake($stocktake, [Stocktake::SUBMITTED, Stocktake::UNDER_REVIEW]);
            $updated = $stocktake->items()->whereIn('id', $itemIds)->where('status', 'counted')
                ->update(['status' => 'recount_requested', 'recount_reason' => $reason]);
            if ($updated === 0) {
                throw new InventoryException('Select counted lines to recount.', 422, 'no_lines');
            }
            $stocktake->forceFill(['status' => Stocktake::IN_PROGRESS, 'submitted_at' => null])->save();
            $this->notifications->resolve("stocktake:{$stocktake->id}:review");
            foreach ($stocktake->assignees as $assignee) {
                $this->notifications->raise("stocktake:{$stocktake->id}:count:{$assignee->id}", [
                    'type' => 'pending_stocktake',
                    'severity' => 'warning',
                    'title' => "Recount requested on {$stocktake->stocktake_no}",
                    'message' => "{$updated} line(s): {$reason}",
                    'link' => "/stocktakes/{$stocktake->id}/count",
                    'user_id' => $assignee->id,
                ]);
            }
            Audit::log('stocktake.recount_requested', $stocktake, "Recount of {$updated} line(s) requested: {$reason}");

            return $stocktake->fresh();
        });
    }

    /**
     * Approve or reject each discrepancy. Approved variances are posted as STOCKTAKE_ADJUSTMENT movements.
     *
     * @param  array<int, array{approve:bool, reason?:string}>  $decisions  keyed by item id; lines without a
     *                                                                      decision default to approve (zero-variance lines need no movement)
     */
    public function approve(Stocktake $stocktake, array $decisions, ?string $notes, User $approver): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $decisions, $notes, $approver) {
            $stocktake = $this->lockStocktake($stocktake, [Stocktake::SUBMITTED, Stocktake::UNDER_REVIEW]);
            $counterIds = StocktakeCount::query()->whereIn('stocktake_item_id', $stocktake->items()->select('id'))->distinct()->pluck('counted_by')->all();
            if (! $approver->isOwner() && in_array($approver->id, $counterIds, true)) {
                throw new InventoryException('You counted stock in this session, so another authorised manager must approve its discrepancies.', 403, 'self_approval');
            }

            // Apply "found" used-tyre lines first so a tyre found in another location is relocated (keeping
            // its status) before its "not found" line at the old location is evaluated.
            $items = $stocktake->items()->with(['product', 'unit'])->get()
                ->sortByDesc(fn ($i) => $i->tyre_unit_id && (int) $i->variance > 0 ? 1 : 0)->values();
            $withVariance = $items->filter(fn ($i) => (int) $i->variance !== 0);
            foreach ($withVariance as $item) {
                $decision = $decisions[$item->id] ?? ['approve' => true];
                if (! empty($decision['approve']) && trim((string) ($decision['reason'] ?? $notes ?? '')) === '') {
                    throw new InventoryException("Enter a reason for the adjustment of {$item->product->displayName()}.", 422, 'reason_required', ['item_id' => $item->id]);
                }
            }

            $products = $this->ledger->lockProducts($withVariance->pluck('product_id'));
            $ref = $stocktake->stocktake_no;
            foreach ($items as $item) {
                if ((int) $item->variance === 0) {
                    $item->forceFill(['status' => 'approved'])->save();

                    continue;
                }
                $decision = $decisions[$item->id] ?? ['approve' => true];
                $reason = trim((string) ($decision['reason'] ?? $notes));
                if (empty($decision['approve'])) {
                    $item->forceFill(['status' => 'rejected', 'adjustment_reason' => $reason ?: 'Difference rejected'])->save();

                    continue;
                }
                $product = $products[$item->product_id];
                $location = InventoryLocation::query()->findOrFail($item->location_id);
                $ctx = [
                    'reference' => $stocktake,
                    'reference_no' => $ref,
                    'reason' => "Stocktake variance ({$item->counted_qty} counted vs {$item->system_qty_at_count} expected): {$reason}",
                    'user_id' => $item->counted_by,
                    'approved_by' => $approver->id,
                ];
                $movements = $this->applyVariance($product, $location, $item, $ctx);
                $item->forceFill([
                    'status' => 'approved',
                    'adjustment_reason' => $reason,
                    'stock_movement_id' => $movements[0]->id ?? null,
                ])->save();
            }

            $stocktake->forceFill([
                'status' => Stocktake::APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'approval_notes' => $notes,
                'adjustment_reference' => $ref,
                'reviewed_by' => $stocktake->reviewed_by ?? $approver->id,
            ])->save();
            $this->notifications->resolve("stocktake:{$stocktake->id}:review");
            Audit::log('stocktake.approved', $stocktake, "Approved stocktake {$ref}: {$withVariance->count()} discrepancy line(s)", null, [
                'net_variance' => $withVariance->sum('variance'),
                'cost_impact' => Money::fromCents($withVariance->sum(fn ($i) => Money::toCents($i->cost_impact))),
            ]);

            return $stocktake->fresh();
        });
    }

    public function close(Stocktake $stocktake, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake) {
            $stocktake = $this->lockStocktake($stocktake, [Stocktake::APPROVED]);
            $stocktake->forceFill(['status' => Stocktake::CLOSED, 'closed_at' => now()])->save();
            Audit::log('stocktake.closed', $stocktake, "Closed stocktake {$stocktake->stocktake_no}");

            return $stocktake;
        });
    }

    public function cancel(Stocktake $stocktake, string $reason, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $reason) {
            $stocktake = $this->lockStocktake($stocktake, [Stocktake::DRAFT, Stocktake::IN_PROGRESS, Stocktake::SUBMITTED, Stocktake::UNDER_REVIEW]);
            $stocktake->forceFill(['status' => Stocktake::CANCELLED, 'approval_notes' => $reason, 'closed_at' => now()])->save();
            foreach ($stocktake->assignees as $assignee) {
                $this->notifications->resolve("stocktake:{$stocktake->id}:count:{$assignee->id}");
            }
            $this->notifications->resolve("stocktake:{$stocktake->id}:review");
            Audit::log('stocktake.cancelled', $stocktake, "Cancelled stocktake {$stocktake->stocktake_no}: {$reason}");

            return $stocktake;
        });
    }

    /** @return list<StockMovement> */
    protected function applyVariance(Product $product, InventoryLocation $location, StocktakeItem $item, array $ctx): array
    {
        $variance = (int) $item->variance;
        if (! $item->tyre_unit_id) {
            try {
                return [$this->ledger->adjust($product, $location, $variance, StockMovement::STOCKTAKE_ADJUSTMENT, $ctx + [
                    'unit_cost' => $item->unit_cost,
                    'lot_source' => 'stocktake',
                ])];
            } catch (InventoryException $e) {
                if ($e->errorCode() === 'insufficient_stock') {
                    throw new InventoryException(
                        "Stock of {$product->displayName()} at {$location->name} has changed since it was counted, so the shortage of ".abs($variance).' can no longer be applied. Request a recount of this line.',
                        409,
                        'stale_count',
                        ['item_id' => $item->id],
                    );
                }
                throw $e;
            }
        }

        $unit = TyreUnit::query()->findOrFail($item->tyre_unit_id);
        if ($variance < 0) {
            if ($unit->location_id !== $location->id || ! $unit->isPhysical()) {
                return []; // Already moved/sold since the count: nothing to correct.
            }
            if ($unit->status === TyreUnit::RESERVED) {
                throw InventoryException::invalidState("Used tyre {$unit->unit_code} is reserved; cancel the reservation before approving it as missing.");
            }

            return $this->ledger->moveUnit($product, $unit, TyreUnit::MISSING, null, StockMovement::STOCKTAKE_ADJUSTMENT, $ctx);
        }

        // Found a tyre that the system did not expect at this location.
        $status = $unit->isPhysical() ? $unit->status : TyreUnit::PENDING;

        return $this->ledger->moveUnit($product, $unit, $status, $location, StockMovement::STOCKTAKE_ADJUSTMENT, $ctx);
    }

    protected function resolveItem(Stocktake $stocktake, array $data): StocktakeItem
    {
        if (! empty($data['item_id'])) {
            return $stocktake->items()->whereKey($data['item_id'])->firstOrFail();
        }
        $location = InventoryLocation::query()->findOrFail($data['location_id'] ?? 0);
        $scopeLocation = StocktakeScope::locationId($stocktake);
        if ($scopeLocation && $scopeLocation !== $location->id) {
            throw new InventoryException('This stocktake only covers '.InventoryLocation::query()->find($scopeLocation)?->name.'.', 422, 'out_of_scope');
        }

        if (! empty($data['unit_code'])) {
            $unit = TyreUnit::query()->where('unit_code', trim($data['unit_code']))->first()
                ?? throw new InventoryException("No used tyre with code {$data['unit_code']} exists.", 404, 'not_found');
            $key = StocktakeItem::lineKey($unit->product_id, $location->id, $unit->id);
            $existing = $stocktake->items()->where('line_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            // Unit expected elsewhere in this stocktake? Then that line shows "not found" and this one "found".
            return $stocktake->items()->create([
                'product_id' => $unit->product_id,
                'location_id' => $location->id,
                'tyre_unit_id' => $unit->id,
                'line_key' => $key,
                'snapshot_qty' => 0,
                'unit_cost' => $unit->cost_price,
                'is_unexpected' => true,
                'status' => 'pending',
            ]);
        }

        $product = Product::query()->findOrFail($data['product_id'] ?? 0);
        if ($product->isUnitTracked()) {
            throw new InventoryException('Used tyres are counted individually: scan or enter the tyre code.', 422, 'unit_required');
        }
        $key = StocktakeItem::lineKey($product->id, $location->id);

        return $stocktake->items()->where('line_key', $key)->first() ?? $stocktake->items()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'line_key' => $key,
            'snapshot_qty' => (int) StockBalance::query()->where('product_id', $product->id)->where('location_id', $location->id)->value('qty_on_hand'),
            'unit_cost' => $this->averageCost($product->id),
            'is_unexpected' => true,
            'status' => 'pending',
        ]);
    }

    protected function currentSystemQty(StocktakeItem $item): int
    {
        if ($item->tyre_unit_id) {
            $unit = TyreUnit::query()->find($item->tyre_unit_id);

            return ($unit && $unit->isPhysical() && $unit->location_id === $item->location_id) ? 1 : 0;
        }

        return (int) StockBalance::query()->where('product_id', $item->product_id)->where('location_id', $item->location_id)->value('qty_on_hand');
    }

    /** Weighted average of remaining FIFO layers (falls back to the product cost price). */
    protected function averageCost(int $productId): string
    {
        $row = StockLot::query()->where('product_id', $productId)->where('qty_remaining', '>', 0)
            ->selectRaw('SUM(qty_remaining) as qty')->first();
        $qty = (int) ($row->qty ?? 0);
        if ($qty > 0) {
            $total = StockLot::query()->where('product_id', $productId)->where('qty_remaining', '>', 0)->get()
                ->sum(fn ($lot) => Money::toCents($lot->unit_cost) * $lot->qty_remaining);

            return Money::fromCents(Money::divRound($total, $qty));
        }

        return Money::normalize(Product::query()->whereKey($productId)->value('cost_price'));
    }

    /** @param list<string> $allowed */
    protected function lockStocktake(Stocktake $stocktake, array $allowed): Stocktake
    {
        $stocktake = Stocktake::query()->whereKey($stocktake->id)->lockForUpdate()->firstOrFail();
        if (! in_array($stocktake->status, $allowed, true)) {
            throw InventoryException::invalidState("Stocktake {$stocktake->stocktake_no} is ".str_replace('_', ' ', $stocktake->status).' and cannot do this.');
        }

        return $stocktake;
    }
}
