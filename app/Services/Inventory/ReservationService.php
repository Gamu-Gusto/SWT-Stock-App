<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\TyreUnit;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Audit;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Customer reservations. A reservation never changes physical stock; it reduces the quantity
 * available to sell. Product rows are locked while reserving, so two employees can never reserve
 * the same remaining tyres.
 */
class ReservationService
{
    public function __construct(protected StockLedger $ledger, protected AppSettings $settings) {}

    /**
     * @param array{customer_name:string, customer_phone:string, notes?:string, expires_hours?:int,
     *   items:list<array{product_id?:int, tyre_unit_id?:int, quantity?:int}>, quotation_id?:int, idempotency_key?:string} $data
     */
    public function create(array $data, User $user): Reservation
    {
        $this->expireDue();

        return DB::transaction(function () use ($data, $user) {
            if (! empty($data['idempotency_key'])) {
                $existing = Reservation::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing->load('items');
                }
            }
            $lines = $this->normaliseLines($data['items'] ?? []);
            if (! $lines) {
                throw new InventoryException('Select at least one tyre to reserve.', 422, 'empty_reservation');
            }
            $products = $this->ledger->lockProducts(array_column($lines, 'product_id'));

            $hours = (int) ($data['expires_hours'] ?? $this->settings->get('reservation_hours', 24));
            $reservation = Reservation::query()->create([
                'reservation_no' => DocumentNumber::next('RES'),
                'status' => Reservation::ACTIVE,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'notes' => $data['notes'] ?? null,
                'expires_at' => now()->addHours(max(1, $hours)),
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'user_id' => $user->id,
                'quotation_id' => $data['quotation_id'] ?? null,
            ]);

            // Requested quantities per product, to check availability once per product.
            $requested = [];
            foreach ($lines as $line) {
                $requested[$line['product_id']] = ($requested[$line['product_id']] ?? 0) + $line['quantity'];
            }
            foreach ($requested as $productId => $qty) {
                $product = $products[$productId];
                if ($product->status !== Product::STATUS_ACTIVE) {
                    throw new InventoryException("{$product->displayName()} is archived and cannot be reserved.", 422, 'archived');
                }
                if ($product->availableQty() < $qty) {
                    throw InventoryException::insufficientStock($product->displayName(), $qty, $product->availableQty());
                }
            }

            foreach ($lines as $line) {
                $product = $products[$line['product_id']];
                $price = $product->effectivePrice();
                if (! empty($line['tyre_unit_id'])) {
                    $unit = TyreUnit::query()->whereKey($line['tyre_unit_id'])->lockForUpdate()->firstOrFail();
                    if ($unit->product_id !== $product->id || $unit->status !== TyreUnit::AVAILABLE || ! $unit->location?->is_sellable) {
                        throw new InventoryException("Used tyre {$unit->unit_code} is not available to reserve.", 409, 'unit_unavailable');
                    }
                    $price = (string) $unit->selling_price;
                    $this->ledger->moveUnit($product, $unit, TyreUnit::RESERVED, null, StockMovement::MANUAL_ADJUSTMENT, ['user_id' => $user->id]);
                } elseif ($product->isUnitTracked()) {
                    throw new InventoryException('Select the specific used tyre(s) to reserve.', 422, 'unit_required');
                }
                $reservation->items()->create([
                    'product_id' => $product->id,
                    'tyre_unit_id' => $line['tyre_unit_id'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $price,
                ]);
            }

            // Recalculate reserved totals (enforces reserved <= sellable).
            foreach (array_keys($requested) as $productId) {
                $this->ledger->refreshProductTotals($products[$productId]);
            }

            Audit::log('reservation.created', $reservation, "Reserved {$reservation->reservation_no} for {$reservation->customer_name}", null, [
                'items' => $lines, 'expires_at' => $reservation->expires_at->toIso8601String(),
            ]);

            return $reservation->load('items');
        });
    }

    public function cancel(Reservation $reservation, ?string $reason, User $user): Reservation
    {
        return $this->release($reservation, Reservation::CANCELLED, $reason, $user);
    }

    public function extend(Reservation $reservation, int $hours, User $user): Reservation
    {
        return DB::transaction(function () use ($reservation, $hours) {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($reservation->status !== Reservation::ACTIVE) {
                throw InventoryException::invalidState('Only active reservations can be extended.');
            }
            $old = $reservation->expires_at;
            $reservation->forceFill(['expires_at' => $reservation->expires_at->max(now())->addHours($hours)])->save();
            Audit::log('reservation.extended', $reservation, "Extended {$reservation->reservation_no} by {$hours}h", ['expires_at' => $old?->toIso8601String()], ['expires_at' => $reservation->expires_at->toIso8601String()]);

            return $reservation;
        });
    }

    /** Expire all active reservations past their expiry time. Safe to call often. */
    public function expireDue(): int
    {
        $count = 0;
        Reservation::query()->where('status', Reservation::ACTIVE)->where('expires_at', '<', now())->pluck('id')
            ->each(function ($id) use (&$count) {
                try {
                    $this->release(Reservation::query()->findOrFail($id), Reservation::EXPIRED, 'Reservation period ended', null);
                    $count++;
                } catch (InventoryException) {
                    // Already handled by a concurrent request.
                }
            });

        return $count;
    }

    /**
     * Consume a reservation as part of a sale (called by SalesService inside its transaction, with products
     * already locked). Returns quantity consumed per reservation item id. Any unconsumed remainder is released.
     *
     * @param  array<int, int>  $consumeByItem  reservation_item_id => quantity sold
     */
    public function consume(Reservation $reservation, array $consumeByItem, int $saleId): void
    {
        $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
        if ($reservation->status !== Reservation::ACTIVE) {
            throw InventoryException::invalidState("Reservation {$reservation->reservation_no} is {$reservation->status} and cannot be used for this sale.");
        }
        foreach ($reservation->items as $item) {
            $take = min($consumeByItem[$item->id] ?? 0, $item->outstanding());
            // Mark fully fulfilled: unconsumed quantities are released when the reservation completes.
            $item->forceFill(['fulfilled_qty' => $item->quantity])->save();
            if ($item->tyre_unit_id && $take === 0) {
                $unit = TyreUnit::query()->whereKey($item->tyre_unit_id)->lockForUpdate()->first();
                if ($unit && $unit->status === TyreUnit::RESERVED) {
                    $unit->forceFill(['status' => TyreUnit::AVAILABLE])->save();
                }
            }
        }
        $reservation->forceFill(['status' => Reservation::COMPLETED, 'completed_at' => now(), 'sale_id' => $saleId])->save();
        Audit::log('reservation.completed', $reservation, "Reservation {$reservation->reservation_no} converted to sale");
    }

    protected function release(Reservation $reservation, string $status, ?string $reason, ?User $user): Reservation
    {
        return DB::transaction(function () use ($reservation, $status, $reason, $user) {
            $productIds = $reservation->items()->pluck('product_id');
            $products = $this->ledger->lockProducts($productIds);
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($reservation->status !== Reservation::ACTIVE) {
                throw InventoryException::invalidState("Reservation {$reservation->reservation_no} is already {$reservation->status}.");
            }
            $reservation->forceFill([
                'status' => $status,
                'cancelled_at' => now(),
                'cancelled_by' => $user?->id,
                'cancel_reason' => $reason,
            ])->save();

            foreach ($reservation->items as $item) {
                if ($item->tyre_unit_id) {
                    $unit = TyreUnit::query()->whereKey($item->tyre_unit_id)->lockForUpdate()->first();
                    if ($unit && $unit->status === TyreUnit::RESERVED) {
                        $unit->forceFill(['status' => TyreUnit::AVAILABLE])->save();
                    }
                }
            }
            foreach ($products as $product) {
                $this->ledger->refreshProductTotals($product);
            }
            Audit::log('reservation.'.$status, $reservation, "Reservation {$reservation->reservation_no} {$status}".($reason ? ": {$reason}" : ''), null, null, $user?->id);

            return $reservation->load('items');
        });
    }

    /** @return list<array{product_id:int, tyre_unit_id:?int, quantity:int}> */
    protected function normaliseLines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            if (! empty($item['tyre_unit_id'])) {
                $unit = TyreUnit::query()->findOrFail($item['tyre_unit_id']);
                $lines[] = ['product_id' => $unit->product_id, 'tyre_unit_id' => $unit->id, 'quantity' => 1];
            } else {
                $qty = (int) ($item['quantity'] ?? 0);
                if ($qty < 1) {
                    throw new InventoryException('Reserved quantity must be at least 1.', 422, 'invalid_quantity');
                }
                $lines[] = ['product_id' => (int) $item['product_id'], 'tyre_unit_id' => null, 'quantity' => $qty];
            }
        }

        return $lines;
    }
}
