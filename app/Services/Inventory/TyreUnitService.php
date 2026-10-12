<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\TyreUnit;
use App\Models\User;
use App\Support\Audit;
use App\Support\DocumentNumber;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Individually identified used tyres: registration, inspection and detail updates.
 * Grades (A/B/C…) are internal classifications only and never replace a safety inspection;
 * rejected tyres can never be sold or reserved.
 */
class TyreUnitService
{
    public const INSPECTION_RESULTS = [TyreUnit::AVAILABLE, TyreUnit::PENDING, TyreUnit::REJECTED];

    public function __construct(protected StockLedger $ledger) {}

    /**
     * Register a used tyre currently on the premises (e.g. trade-in or opening stock).
     * Receipts from suppliers go through ReceivingService, which calls createInTransaction().
     */
    public function create(Product $product, array $data, User $user): TyreUnit
    {
        return DB::transaction(function () use ($product, $data, $user) {
            $product = $this->ledger->lockProduct($product->id);
            $location = InventoryLocation::query()->findOrFail($data['location_id']);
            $unit = $this->createInTransaction($product, $location, $data, $user, StockMovement::OPENING_BALANCE, [
                'reason' => $data['reason'] ?? 'Used tyre registered',
            ]);
            Audit::log('tyre_unit.created', $unit, "Registered used tyre {$unit->unit_code} for {$product->sku}", null, $unit->toArray());

            return $unit;
        });
    }

    /** Must be called with the product already locked inside a transaction. */
    public function createInTransaction(Product $product, InventoryLocation $location, array $data, User $user, string $movementType, array $ctx = []): TyreUnit
    {
        if (! $product->isUnitTracked()) {
            throw new InventoryException('Only used (individually tracked) tyre records can hold tyre units.', 422, 'not_unit_tracked');
        }
        $status = $data['status'] ?? TyreUnit::PENDING;
        if (! in_array($status, self::INSPECTION_RESULTS, true)) {
            throw new InventoryException('Invalid inspection status.', 422, 'invalid_status');
        }

        $unit = new TyreUnit(Arr::only($data, ['tread_depth_mm', 'grade', 'dot_code', 'defects', 'inspection_notes', 'supplier_id']) + [
            'unit_code' => $data['unit_code'] ?? DocumentNumber::next('UT'),
            'product_id' => $product->id,
            'cost_price' => $data['cost_price'] ?? $product->cost_price,
            'selling_price' => $data['selling_price'] ?? $product->selling_price,
            'created_by' => $user->id,
        ]);
        // Not physical until the ledger books it into a location.
        $unit->forceFill(['status' => TyreUnit::WRITTEN_OFF, 'location_id' => null, 'received_at' => now()]);
        if ($status !== TyreUnit::PENDING) {
            $unit->forceFill(['inspected_by' => $user->id, 'inspected_at' => now()]);
        }
        $unit->save();

        $this->ledger->moveUnit($product, $unit, $status, $location, $movementType, $ctx + [
            'reference' => $ctx['reference'] ?? $unit,
            'reference_no' => $ctx['reference_no'] ?? $unit->unit_code,
            'user_id' => $user->id,
        ]);

        return $unit->fresh();
    }

    /** Record the inspection outcome: available for sale, still pending, or rejected (never sellable). */
    public function inspect(TyreUnit $unit, string $result, array $data, User $user): TyreUnit
    {
        return DB::transaction(function () use ($unit, $result, $data, $user) {
            $product = $this->ledger->lockProduct($unit->product_id);
            $unit = TyreUnit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();
            if (! in_array($unit->status, [TyreUnit::PENDING, TyreUnit::AVAILABLE, TyreUnit::REJECTED], true)) {
                throw InventoryException::invalidState("Tyre {$unit->unit_code} is {$unit->status} and cannot be re-inspected.");
            }
            if (! in_array($result, self::INSPECTION_RESULTS, true)) {
                throw new InventoryException('Invalid inspection result.', 422, 'invalid_status');
            }
            $old = $unit->only(['status', 'grade', 'tread_depth_mm', 'inspection_notes']);
            $unit->fill(Arr::only($data, ['grade', 'tread_depth_mm', 'inspection_notes', 'defects']));
            $unit->forceFill(['inspected_by' => $user->id, 'inspected_at' => now()])->save();
            $this->ledger->moveUnit($product, $unit, $result, null, StockMovement::MANUAL_ADJUSTMENT, ['user_id' => $user->id]);
            $unit->refresh();
            Audit::log('tyre_unit.inspected', $unit, "Inspected {$unit->unit_code}: {$result}", $old, $unit->only(['status', 'grade', 'tread_depth_mm', 'inspection_notes']));

            return $unit;
        });
    }

    public function update(TyreUnit $unit, array $data, User $user): TyreUnit
    {
        $original = $unit->getOriginal();
        $unit->fill(Arr::only($data, ['tread_depth_mm', 'grade', 'dot_code', 'defects', 'inspection_notes', 'cost_price', 'selling_price']))->save();
        Audit::changes('tyre_unit.updated', $unit, $original, "Updated used tyre {$unit->unit_code}");

        return $unit;
    }
}
