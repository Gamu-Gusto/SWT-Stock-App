<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\AdjustmentRequest;
use App\Models\InventoryLocation;
use App\Models\StockMovement;
use App\Models\TyreUnit;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Audit;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Manual stock adjustments, damaged stock, write-offs and supplier returns.
 * Every adjustment needs a reason and is posted only after approval by a user holding
 * `adjustments.approve` who is not the requester (the owner may approve their own).
 */
class AdjustmentService
{
    public function __construct(protected StockLedger $ledger, protected NotificationService $notifications) {}

    public function request(array $data, User $user): AdjustmentRequest
    {
        if (! empty($data['idempotency_key']) && ($existing = AdjustmentRequest::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
            return $existing;
        }
        $type = $data['type'];
        if (! in_array($type, AdjustmentRequest::TYPES, true)) {
            throw new InventoryException('Invalid adjustment type.', 422, 'invalid_type');
        }
        $unit = ! empty($data['tyre_unit_id']) ? TyreUnit::query()->findOrFail($data['tyre_unit_id']) : null;
        $qty = $unit ? ($type === 'manual_adjustment' ? -1 : 1) : (int) $data['quantity_change'];
        if ($qty === 0) {
            throw new InventoryException('Quantity cannot be zero.', 422, 'invalid_quantity');
        }
        if ($type !== 'manual_adjustment' && $qty < 0) {
            $qty = abs($qty);
        }
        if ($unit && ! $unit->isPhysical()) {
            throw InventoryException::invalidState("Used tyre {$unit->unit_code} is not in stock ({$unit->status}).");
        }

        $adjustment = AdjustmentRequest::query()->create([
            'adjustment_no' => DocumentNumber::next('ADJ'),
            'type' => $type,
            'product_id' => $unit?->product_id ?? $data['product_id'],
            'tyre_unit_id' => $unit?->id,
            'location_id' => $unit?->location_id ?? $data['location_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'quantity_change' => $qty,
            'reason' => $data['reason'],
            'status' => 'pending',
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'requested_by' => $user->id,
        ]);
        Audit::log('adjustment.requested', $adjustment, "Adjustment {$adjustment->adjustment_no} requested: {$type} {$qty}", null, $adjustment->toArray());
        $this->notifications->raise("adjustment:{$adjustment->id}", [
            'type' => 'pending_approval',
            'severity' => 'warning',
            'title' => "Adjustment {$adjustment->adjustment_no} awaiting approval",
            'message' => str_replace('_', ' ', $type).": {$qty} – {$adjustment->reason}",
            'link' => '/movements/adjustments',
            'permission' => 'adjustments.approve',
        ]);

        return $adjustment;
    }

    public function approve(AdjustmentRequest $adjustment, User $approver, ?string $notes = null): AdjustmentRequest
    {
        return DB::transaction(function () use ($adjustment, $approver, $notes) {
            $adjustment = AdjustmentRequest::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($adjustment);
            if ($adjustment->requested_by === $approver->id && ! $approver->isOwner()) {
                throw new InventoryException('You cannot approve your own adjustment request. Another authorised manager must approve it.', 403, 'self_approval');
            }
            $product = $this->ledger->lockProduct($adjustment->product_id);
            $location = InventoryLocation::query()->findOrFail($adjustment->location_id);
            $ctx = [
                'reference' => $adjustment,
                'reference_no' => $adjustment->adjustment_no,
                'reason' => $adjustment->reason,
                'user_id' => $adjustment->requested_by,
                'approved_by' => $approver->id,
            ];
            $qty = $adjustment->quantity_change;
            $unit = $adjustment->tyre_unit_id ? TyreUnit::query()->findOrFail($adjustment->tyre_unit_id) : null;
            if ($unit && $unit->status === TyreUnit::RESERVED) {
                throw InventoryException::invalidState("Used tyre {$unit->unit_code} is reserved for a customer. Cancel the reservation first.");
            }
            if ($unit && ! $unit->isPhysical()) {
                throw InventoryException::invalidState("Used tyre {$unit->unit_code} is no longer in stock ({$unit->status}).");
            }

            switch ($adjustment->type) {
                case 'manual_adjustment':
                    if ($unit) {
                        $this->ledger->moveUnit($product, $unit, TyreUnit::MISSING, null, StockMovement::MANUAL_ADJUSTMENT, $ctx);
                    } else {
                        $this->ledger->adjust($product, $location, $qty, StockMovement::MANUAL_ADJUSTMENT, $ctx + ['lot_source' => 'adjustment']);
                    }
                    break;
                case 'damaged':
                    $damaged = InventoryLocation::holding('damaged');
                    if ($unit) {
                        $this->ledger->moveUnit($product, $unit, TyreUnit::REJECTED, $damaged, StockMovement::DAMAGED, $ctx);
                    } else {
                        $this->ledger->adjust($product, $location, -$qty, StockMovement::DAMAGED, $ctx + ['costing' => 'none', 'to_location_id' => $damaged->id]);
                        $this->ledger->adjust($product, $damaged, $qty, StockMovement::DAMAGED, $ctx + ['costing' => 'none', 'from_location_id' => $location->id]);
                    }
                    break;
                case 'write_off':
                    $unit
                        ? $this->ledger->moveUnit($product, $unit, TyreUnit::WRITTEN_OFF, null, StockMovement::WRITE_OFF, $ctx)
                        : $this->ledger->adjust($product, $location, -$qty, StockMovement::WRITE_OFF, $ctx);
                    break;
                case 'supplier_return':
                    $unit
                        ? $this->ledger->moveUnit($product, $unit, TyreUnit::RETURNED_TO_SUPPLIER, null, StockMovement::SUPPLIER_RETURN, $ctx)
                        : $this->ledger->adjust($product, $location, -$qty, StockMovement::SUPPLIER_RETURN, $ctx);
                    break;
            }

            $adjustment->forceFill(['status' => 'approved', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_notes' => $notes])->save();
            $this->notifications->resolve("adjustment:{$adjustment->id}");
            Audit::log('adjustment.approved', $adjustment, "Approved adjustment {$adjustment->adjustment_no}", ['status' => 'pending'], ['status' => 'approved', 'notes' => $notes]);

            return $adjustment;
        });
    }

    public function reject(AdjustmentRequest $adjustment, User $approver, string $notes): AdjustmentRequest
    {
        return DB::transaction(function () use ($adjustment, $approver, $notes) {
            $adjustment = AdjustmentRequest::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($adjustment);
            $adjustment->forceFill(['status' => 'rejected', 'decided_by' => $approver->id, 'decided_at' => now(), 'decision_notes' => $notes])->save();
            $this->notifications->resolve("adjustment:{$adjustment->id}");
            Audit::log('adjustment.rejected', $adjustment, "Rejected adjustment {$adjustment->adjustment_no}: {$notes}");

            return $adjustment;
        });
    }

    private function assertPending(AdjustmentRequest $adjustment): void
    {
        if ($adjustment->status !== 'pending') {
            throw InventoryException::invalidState("Adjustment {$adjustment->adjustment_no} has already been {$adjustment->status}.");
        }
    }
}
