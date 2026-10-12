<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\TyreUnit;
use App\Models\User;
use App\Support\Audit;
use App\Support\DocumentNumber;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Stock receiving (goods received notes). Drafts can be edited; posting is atomic and happens exactly
 * once; posted receipts are never edited – corrections are made by a traceable reversal.
 */
class ReceivingService
{
    public function __construct(protected StockLedger $ledger, protected TyreUnitService $units) {}

    public function saveDraft(array $data, User $user, ?StockReceipt $receipt = null): StockReceipt
    {
        return DB::transaction(function () use ($data, $user, $receipt) {
            if (! $receipt && ! empty($data['idempotency_key'])) {
                $existing = StockReceipt::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing->load('items');
                }
            }
            if ($receipt) {
                $receipt = StockReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
                if ($receipt->status !== StockReceipt::DRAFT) {
                    throw InventoryException::invalidState("Receipt {$receipt->receipt_no} is {$receipt->status} and can no longer be edited. Use a reversal to correct it.");
                }
            } else {
                $receipt = new StockReceipt([
                    'receipt_no' => DocumentNumber::next('GRN'),
                    'status' => StockReceipt::DRAFT,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                    'created_by' => $user->id,
                ]);
            }

            $receipt->fill([
                'supplier_id' => $data['supplier_id'],
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'delivery_date' => $data['delivery_date'],
                'notes' => $data['notes'] ?? null,
            ]);
            $receipt->save();

            $receipt->items()->delete();
            $totalCents = 0;
            $totalQty = 0;
            foreach ($data['items'] ?? [] as $item) {
                $product = Product::query()->findOrFail($item['product_id']);
                $details = $product->isUnitTracked() ? array_values($item['unit_details'] ?? []) : null;
                $qty = $product->isUnitTracked() ? count($details ?? []) : (int) $item['quantity'];
                if ($qty < 1) {
                    throw new InventoryException("Enter a quantity (or tyre details for used tyres) for {$product->displayName()}.", 422, 'invalid_quantity');
                }
                $unitCost = Money::normalize($item['unit_cost'] ?? $product->cost_price);
                $lineCents = $product->isUnitTracked()
                    ? array_sum(array_map(fn ($d) => Money::toCents($d['cost_price'] ?? $unitCost), $details))
                    : Money::toCents($unitCost) * $qty;
                $receipt->items()->create([
                    'product_id' => $product->id,
                    'location_id' => $item['location_id'],
                    'quantity' => $qty,
                    'unit_cost' => $product->isUnitTracked() ? Money::fromCents(Money::divRound($lineCents, $qty)) : $unitCost,
                    'line_total' => Money::fromCents($lineCents),
                    'unit_details' => $details,
                ]);
                $totalCents += $lineCents;
                $totalQty += $qty;
            }
            $receipt->forceFill(['total_cost' => Money::fromCents($totalCents), 'total_quantity' => $totalQty])->save();
            Audit::log($receipt->wasRecentlyCreated ? 'receipt.draft_created' : 'receipt.draft_updated', $receipt, "Draft receipt {$receipt->receipt_no} saved ({$totalQty} tyres)");

            return $receipt->load('items');
        });
    }

    /**
     * Post a draft receipt: increases stock, creates FIFO lots and movements. Safe against double posting.
     */
    public function post(StockReceipt $receipt, User $user, bool $allowDuplicateReference = false): StockReceipt
    {
        return DB::transaction(function () use ($receipt, $user, $allowDuplicateReference) {
            $receipt = StockReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status === StockReceipt::POSTED) {
                throw new InventoryException("Receipt {$receipt->receipt_no} has already been posted.", 409, 'already_posted');
            }
            if ($receipt->status !== StockReceipt::DRAFT) {
                throw InventoryException::invalidState("Receipt {$receipt->receipt_no} is {$receipt->status} and cannot be posted.");
            }
            $items = $receipt->items()->get();
            if ($items->isEmpty()) {
                throw new InventoryException('Add at least one tyre before posting the receipt.', 422, 'empty_receipt');
            }
            if (! $allowDuplicateReference && $receipt->supplier_reference) {
                $duplicate = StockReceipt::query()->where('supplier_id', $receipt->supplier_id)
                    ->where('supplier_reference', $receipt->supplier_reference)->where('status', StockReceipt::POSTED)
                    ->whereKeyNot($receipt->id)->first();
                if ($duplicate) {
                    throw new InventoryException(
                        "Supplier reference {$receipt->supplier_reference} was already received on {$duplicate->receipt_no}. Confirm if this is a different delivery.",
                        409,
                        'duplicate_reference',
                        ['receipt_no' => $duplicate->receipt_no],
                    );
                }
            }

            $products = $this->ledger->lockProducts($items->pluck('product_id'));
            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $product->loadMissing('brand', 'tyreModel', 'spec');
                $location = InventoryLocation::query()->findOrFail($item->location_id);
                $ctx = [
                    'reference' => $receipt,
                    'reference_no' => $receipt->receipt_no,
                    'reason' => 'Delivery '.($receipt->supplier_reference ?: 'without reference'),
                    'supplier_id' => $receipt->supplier_id,
                    'user_id' => $user->id,
                ];
                if ($product->isUnitTracked()) {
                    foreach ($item->unit_details ?? [] as $detail) {
                        $this->units->createInTransaction($product, $location, $detail + [
                            'supplier_id' => $receipt->supplier_id,
                            'cost_price' => $detail['cost_price'] ?? $item->unit_cost,
                            'status' => $detail['status'] ?? TyreUnit::PENDING,
                        ], $user, StockMovement::RECEIVED, $ctx);
                    }
                } else {
                    $this->ledger->adjust($product, $location, $item->quantity, StockMovement::RECEIVED, $ctx + [
                        'unit_cost' => $item->unit_cost,
                        'lot_source' => 'receipt',
                        'stock_receipt_item_id' => $item->id,
                    ]);
                    // Latest purchase cost becomes the standard cost used for new estimates.
                    $product->forceFill(['cost_price' => $item->unit_cost])->saveQuietly();
                }
                if (! $product->default_supplier_id) {
                    $product->forceFill(['default_supplier_id' => $receipt->supplier_id])->saveQuietly();
                }
            }

            $receipt->forceFill(['status' => StockReceipt::POSTED, 'posted_by' => $user->id, 'posted_at' => now()])->save();
            Audit::log('receipt.posted', $receipt, "Posted receipt {$receipt->receipt_no}: {$receipt->total_quantity} tyres", null, [
                'supplier_id' => $receipt->supplier_id, 'reference' => $receipt->supplier_reference, 'total_quantity' => $receipt->total_quantity,
            ]);

            return $receipt->fresh(['items', 'supplier']);
        });
    }

    /** Reverse a posted receipt with compensating movements (never edits history). */
    public function reverse(StockReceipt $receipt, string $reason, User $user): StockReceipt
    {
        return DB::transaction(function () use ($receipt, $reason, $user) {
            $receipt = StockReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== StockReceipt::POSTED) {
                throw InventoryException::invalidState("Only posted receipts can be reversed ({$receipt->receipt_no} is {$receipt->status}).");
            }
            $items = $receipt->items()->get();
            $products = $this->ledger->lockProducts($items->pluck('product_id'));
            $ctx = ['reference' => $receipt, 'reference_no' => $receipt->receipt_no, 'reason' => 'Receipt reversal: '.$reason, 'user_id' => $user->id, 'approved_by' => $user->id];

            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $product->loadMissing('brand', 'tyreModel', 'spec');
                if ($product->isUnitTracked()) {
                    $unitIds = StockMovement::query()->where('reference_type', 'StockReceipt')->where('reference_id', $receipt->id)
                        ->where('product_id', $product->id)->where('quantity_change', '>', 0)->pluck('tyre_unit_id')->filter();
                    foreach (TyreUnit::query()->whereIn('id', $unitIds)->get() as $unit) {
                        if (! in_array($unit->status, [TyreUnit::PENDING, TyreUnit::AVAILABLE, TyreUnit::REJECTED], true)) {
                            throw InventoryException::invalidState("Used tyre {$unit->unit_code} is {$unit->status}; it must be back in stock and unreserved before the receipt can be reversed.");
                        }
                        $this->ledger->moveUnit($product, $unit, TyreUnit::RETURNED_TO_SUPPLIER, null, StockMovement::RECEIPT_REVERSAL, $ctx);
                    }
                } else {
                    $lotIds = $product->lots()->where('stock_receipt_item_id', $item->id)->pluck('id')->all();
                    $location = InventoryLocation::query()->findOrFail($item->location_id);
                    $this->ledger->adjust($product, $location, -$item->quantity, StockMovement::RECEIPT_REVERSAL, $ctx + ['prefer_lot_ids' => $lotIds]);
                }
            }

            $receipt->forceFill([
                'status' => StockReceipt::REVERSED,
                'reversed_by' => $user->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();
            Audit::log('receipt.reversed', $receipt, "Reversed receipt {$receipt->receipt_no}: {$reason}");

            return $receipt->fresh(['items', 'supplier']);
        });
    }

    public function deleteDraft(StockReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt) {
            $receipt = StockReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== StockReceipt::DRAFT) {
                throw InventoryException::invalidState('Only draft receipts can be deleted.');
            }
            Audit::log('receipt.draft_deleted', $receipt, "Deleted draft receipt {$receipt->receipt_no}");
            $receipt->items()->delete();
            $receipt->delete();
        });
    }
}
