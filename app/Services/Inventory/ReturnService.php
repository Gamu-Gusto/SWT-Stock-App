<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\TyreUnit;
use App\Models\User;
use App\Support\Audit;
use App\Support\DocumentNumber;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Customer returns. Returned tyres are booked into the QUARANTINE location (physical, not sellable)
 * and only become sellable after an inspection outcome of "restock". Damaged tyres go to the damaged
 * location or are written off – never automatically back into sellable stock.
 */
class ReturnService
{
    public function __construct(protected StockLedger $ledger) {}

    /** @param list<array{sale_item_id:int, quantity:int}> $lines */
    public function create(Sale $sale, array $lines, string $reason, User $user, ?string $idempotencyKey = null): SaleReturn
    {
        if ($idempotencyKey && ($existing = SaleReturn::query()->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing->load('items');
        }

        return DB::transaction(function () use ($sale, $lines, $reason, $user, $idempotencyKey) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! in_array($sale->status, [Sale::COMPLETED, Sale::PARTIALLY_RETURNED], true)) {
                throw InventoryException::invalidState("Sale {$sale->sale_no} is {$sale->status}; returns can only be recorded against completed sales.");
            }
            $quarantine = InventoryLocation::holding('quarantine');
            $saleItems = $sale->items()->where('item_type', 'product')->get()->keyBy('id');
            $products = $this->ledger->lockProducts($saleItems->pluck('product_id'));

            $return = SaleReturn::query()->create([
                'return_no' => DocumentNumber::next('RET'),
                'sale_id' => $sale->id,
                'reason' => $reason,
                'status' => 'pending_inspection',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $user->id,
            ]);
            $ctx = ['reference' => $return, 'reference_no' => $return->return_no, 'reason' => "Customer return ({$sale->sale_no}): {$reason}", 'user_id' => $user->id];

            $refund = 0;
            $any = false;
            foreach ($lines as $line) {
                $qty = (int) ($line['quantity'] ?? 0);
                if ($qty < 1) {
                    continue;
                }
                /** @var SaleItem|null $saleItem */
                $saleItem = $saleItems[$line['sale_item_id']] ?? null;
                if (! $saleItem) {
                    throw new InventoryException('Returned item does not belong to this sale.', 422, 'invalid_item');
                }
                if ($qty > $saleItem->quantity - $saleItem->returned_qty) {
                    throw new InventoryException("Cannot return {$qty} × {$saleItem->description}; only ".($saleItem->quantity - $saleItem->returned_qty).' remain un-returned.', 422, 'over_return');
                }
                $product = $products[$saleItem->product_id];
                $unitCostCents = $saleItem->quantity ? Money::divRound(Money::toCents($saleItem->cost_total), $saleItem->quantity) : 0;

                if ($saleItem->tyre_unit_id) {
                    $unit = TyreUnit::query()->findOrFail($saleItem->tyre_unit_id);
                    $this->ledger->moveUnit($product, $unit, TyreUnit::PENDING, $quarantine, StockMovement::CUSTOMER_RETURN, $ctx);
                } else {
                    $this->ledger->adjust($product, $quarantine, $qty, StockMovement::CUSTOMER_RETURN, $ctx + [
                        'unit_cost' => Money::fromCents($unitCostCents),
                        'lot_source' => 'return',
                    ]);
                }
                SaleReturnItem::query()->create([
                    'sale_return_id' => $return->id,
                    'sale_item_id' => $saleItem->id,
                    'product_id' => $product->id,
                    'tyre_unit_id' => $saleItem->tyre_unit_id,
                    'quantity' => $qty,
                    'unit_cost' => Money::fromCents($unitCostCents),
                    'outcome' => 'pending',
                ]);
                $saleItem->increment('returned_qty', $qty);
                $lineNet = Money::toCents($saleItem->line_total);
                $refund += Money::divRound($lineNet * $qty, $saleItem->quantity);
                $any = true;
            }
            if (! $any) {
                throw new InventoryException('Enter the quantity being returned.', 422, 'empty_return');
            }

            $return->forceFill(['refund_amount' => Money::fromCents($refund)])->save();
            $fullyReturned = $sale->items()->where('item_type', 'product')->get()->every(fn ($i) => $i->returned_qty >= $i->quantity);
            $sale->forceFill(['status' => $fullyReturned ? Sale::RETURNED : Sale::PARTIALLY_RETURNED])->save();
            Audit::log('sale.returned', $return, "Return {$return->return_no} on {$sale->sale_no} received into quarantine");

            return $return->load('items');
        });
    }

    /**
     * Record the inspection outcome of a returned item: restocked (to a sellable location), damaged
     * (to the damaged-stock location) or written off.
     */
    public function inspect(SaleReturnItem $item, string $outcome, ?int $restockLocationId, ?string $notes, User $user): SaleReturnItem
    {
        return DB::transaction(function () use ($item, $outcome, $restockLocationId, $notes, $user) {
            $item = SaleReturnItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->outcome !== 'pending') {
                throw InventoryException::invalidState('This returned item has already been inspected.');
            }
            $product = $this->ledger->lockProduct($item->product_id);
            $quarantine = InventoryLocation::holding('quarantine');
            $return = $item->saleReturn;
            $ctx = ['reference' => $return, 'reference_no' => $return->return_no, 'user_id' => $user->id, 'approved_by' => $user->id];
            $unit = $item->tyre_unit_id ? TyreUnit::query()->findOrFail($item->tyre_unit_id) : null;

            switch ($outcome) {
                case 'restocked':
                    $to = InventoryLocation::query()->findOrFail($restockLocationId ?? $product->default_location_id);
                    if (! $to->is_sellable) {
                        throw new InventoryException('Choose a sellable location to restock the returned tyre.', 422, 'location_not_sellable');
                    }
                    $ctx['reason'] = 'Return inspection passed – restocked'.($notes ? ": {$notes}" : '');
                    if ($unit) {
                        $this->ledger->moveUnit($product, $unit, TyreUnit::AVAILABLE, $to, StockMovement::TRANSFER, $ctx);
                    } else {
                        $this->transfer($product, $quarantine, $to, $item->quantity, StockMovement::TRANSFER, $ctx);
                    }
                    break;
                case 'damaged':
                    $to = InventoryLocation::holding('damaged');
                    $ctx['reason'] = 'Return inspection failed – damaged'.($notes ? ": {$notes}" : '');
                    if ($unit) {
                        $this->ledger->moveUnit($product, $unit, TyreUnit::REJECTED, $to, StockMovement::DAMAGED, $ctx);
                    } else {
                        $this->transfer($product, $quarantine, $to, $item->quantity, StockMovement::DAMAGED, $ctx);
                    }
                    break;
                case 'written_off':
                    $ctx['reason'] = 'Return inspection failed – written off'.($notes ? ": {$notes}" : '');
                    if ($unit) {
                        $this->ledger->moveUnit($product, $unit, TyreUnit::WRITTEN_OFF, null, StockMovement::WRITE_OFF, $ctx);
                    } else {
                        $this->ledger->adjust($product, $quarantine, -$item->quantity, StockMovement::WRITE_OFF, $ctx);
                    }
                    break;
                default:
                    throw new InventoryException('Invalid inspection outcome.', 422, 'invalid_outcome');
            }

            $item->forceFill([
                'outcome' => $outcome,
                'restock_location_id' => $outcome === 'restocked' ? ($restockLocationId ?? $product->default_location_id) : null,
                'inspection_notes' => $notes,
                'inspected_by' => $user->id,
                'inspected_at' => now(),
            ])->save();

            if ($return->items()->where('outcome', 'pending')->doesntExist()) {
                $return->forceFill(['status' => 'completed'])->save();
            }
            Audit::log('return.inspected', $return, "Return item on {$return->return_no} inspected: {$outcome}");

            return $item;
        });
    }

    private function transfer($product, InventoryLocation $from, InventoryLocation $to, int $qty, string $type, array $ctx): void
    {
        $this->ledger->adjust($product, $from, -$qty, $type, $ctx + ['costing' => 'none', 'to_location_id' => $to->id]);
        $this->ledger->adjust($product, $to, $qty, $type, $ctx + ['costing' => 'none', 'from_location_id' => $from->id]);
    }
}
