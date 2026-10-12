<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\TyreUnit;
use App\Models\User;
use App\Services\PricingCalculator;
use App\Support\Audit;
use App\Support\DocumentNumber;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tyre sales. Only COMPLETED sales deduct stock, exactly once, inside one locked transaction.
 * Prices and descriptions are snapshotted onto sale lines. Voids and returns create compensating
 * movements; returned tyres go to quarantine until inspected.
 */
class SalesService
{
    public const SERVICE_CODES = ['fitting' => 'Fitting', 'balancing' => 'Balancing', 'alignment' => 'Wheel alignment', 'puncture' => 'Puncture repair', 'valve' => 'Valve', 'other' => 'Other service'];

    public function __construct(
        protected StockLedger $ledger,
        protected ReservationService $reservations,
        protected PricingCalculator $pricing,
    ) {}

    /**
     * Create a sale. $data['status'] = 'draft' saves without touching stock; 'completed' (default) posts it.
     *
     * items: [{product_id?, tyre_unit_id?, quantity, unit_price?, discount?, location_id?, reservation_item_id?}]
     * services: [{service_code, description?, quantity, unit_price, discount?}]
     */
    public function create(array $data, User $user): Sale
    {
        if (! empty($data['idempotency_key'])) {
            $existing = Sale::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing->load('items');
            }
        }
        $this->reservations->expireDue();

        try {
            return $this->createInTransaction($data, $user);
        } catch (UniqueConstraintViolationException $e) {
            // A simultaneous duplicate submission with the same idempotency key won the race.
            $existing = ! empty($data['idempotency_key']) ? Sale::query()->where('idempotency_key', $data['idempotency_key'])->first() : null;
            if ($existing) {
                return $existing->load('items');
            }
            throw $e;
        }
    }

    protected function createInTransaction(array $data, User $user): Sale
    {
        return DB::transaction(function () use ($data, $user) {
            $sale = Sale::query()->create([
                'sale_no' => DocumentNumber::next('SL'),
                'status' => Sale::DRAFT,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'user_id' => $user->id,
            ]);
            $this->fillSale($sale, $data, $user);

            if (($data['status'] ?? Sale::COMPLETED) === Sale::COMPLETED) {
                $this->completeLocked($sale, $data, $user);
            } else {
                Audit::log('sale.draft_saved', $sale, "Draft sale {$sale->sale_no} saved");
            }

            return $sale->fresh(['items', 'user']);
        });
    }

    public function updateDraft(Sale $sale, array $data, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $data, $user) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== Sale::DRAFT) {
                throw InventoryException::invalidState("Sale {$sale->sale_no} is {$sale->status}; completed sales cannot be edited.");
            }
            $this->fillSale($sale, $data, $user);

            return $sale->fresh(['items']);
        });
    }

    /** Complete a previously saved draft. Repeated calls fail safely with "already completed". */
    public function complete(Sale $sale, array $payment, User $user): Sale
    {
        $this->reservations->expireDue();

        return DB::transaction(function () use ($sale, $payment, $user) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status === Sale::COMPLETED) {
                throw new InventoryException("Sale {$sale->sale_no} has already been completed.", 409, 'already_completed');
            }
            if ($sale->status !== Sale::DRAFT) {
                throw InventoryException::invalidState("Sale {$sale->sale_no} is {$sale->status} and cannot be completed.");
            }
            $sale->fill(array_intersect_key($payment, array_flip(['payment_method', 'payment_reference'])));
            $this->completeLocked($sale, $payment + ['reservation_id' => $sale->reservation_id], $user);

            return $sale->fresh(['items', 'user']);
        });
    }

    public function deleteDraft(Sale $sale): void
    {
        DB::transaction(function () use ($sale) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== Sale::DRAFT) {
                throw InventoryException::invalidState('Only draft sales can be deleted.');
            }
            Audit::log('sale.draft_deleted', $sale, "Deleted draft sale {$sale->sale_no}");
            $sale->items()->delete();
            $sale->delete();
        });
    }

    /**
     * Void a completed sale recorded in error (the tyres never left the premises). Stock returns to the
     * locations it was sold from, with the original FIFO cost layers.
     */
    public function void(Sale $sale, string $reason, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $reason, $user) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== Sale::COMPLETED) {
                throw InventoryException::invalidState("Only completed sales without returns can be voided ({$sale->sale_no} is {$sale->status}).");
            }
            $movements = StockMovement::query()->where('reference_type', 'Sale')->where('reference_id', $sale->id)
                ->where('type', StockMovement::SALE)->orderBy('id')->get();
            $products = $this->ledger->lockProducts($movements->pluck('product_id'));
            $ctx = ['reference' => $sale, 'reference_no' => $sale->sale_no, 'reason' => 'Sale voided: '.$reason, 'user_id' => $user->id, 'approved_by' => $user->id];

            foreach ($movements as $movement) {
                $product = $products[$movement->product_id];
                $location = InventoryLocation::query()->findOrFail($movement->location_id);
                if ($movement->tyre_unit_id) {
                    $unit = TyreUnit::query()->findOrFail($movement->tyre_unit_id);
                    $this->ledger->moveUnit($product, $unit, TyreUnit::AVAILABLE, $location, StockMovement::SALE_VOID, $ctx);
                } else {
                    $this->ledger->adjust($product, $location, abs($movement->quantity_change), StockMovement::SALE_VOID, $ctx + [
                        'restore_layers' => $this->ledger->layersFor($movement),
                        'unit_cost' => $movement->unit_cost,
                        'lot_source' => 'return',
                    ]);
                }
            }
            $sale->forceFill(['status' => Sale::VOIDED, 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason])->save();
            Audit::log('sale.voided', $sale, "Voided sale {$sale->sale_no}: {$reason}", ['status' => Sale::COMPLETED], ['status' => Sale::VOIDED]);

            return $sale->fresh(['items']);
        });
    }

    /** Replace a sale's lines/customer data and recalculate totals (no stock effect). */
    protected function fillSale(Sale $sale, array $data, User $user): void
    {
        $productLines = array_values($data['items'] ?? []);
        $serviceLines = array_values($data['services'] ?? []);
        if (! $productLines && ! $serviceLines) {
            throw new InventoryException('Add at least one tyre or service to the sale.', 422, 'empty_sale');
        }

        $reservation = null;
        if (! empty($data['reservation_id'])) {
            $reservation = Reservation::query()->with('items')->findOrFail($data['reservation_id']);
        }

        $sale->items()->delete();
        $lines = [];
        $priceLines = [];
        foreach ($productLines as $line) {
            $unit = ! empty($line['tyre_unit_id']) ? TyreUnit::query()->findOrFail($line['tyre_unit_id']) : null;
            $product = Product::query()->with(['brand', 'tyreModel', 'spec'])->findOrFail($unit?->product_id ?? $line['product_id']);
            if ($product->isUnitTracked() && ! $unit) {
                throw new InventoryException("Select the specific used tyre for {$product->displayName()}.", 422, 'unit_required');
            }
            $qty = $unit ? 1 : (int) $line['quantity'];
            $defaultPrice = $unit ? (string) $unit->selling_price : $product->effectivePrice();
            $price = isset($line['unit_price']) && $line['unit_price'] !== '' ? Money::normalize($line['unit_price']) : $defaultPrice;
            $this->assertPriceAllowed($product, $unit, $price, $user);

            $reservationItemId = $line['reservation_item_id'] ?? null;
            if ($reservation && ! $reservationItemId) {
                $match = $reservation->items->first(fn (ReservationItem $ri) => $ri->product_id === $product->id && (! $unit || $ri->tyre_unit_id === $unit->id));
                $reservationItemId = $match?->id;
            }

            $lines[] = [
                'item_type' => 'product',
                'product_id' => $product->id,
                'tyre_unit_id' => $unit?->id,
                'location_id' => $line['location_id'] ?? null,
                'reservation_item_id' => $reservationItemId,
                'description' => trim($product->displayName().($unit ? " ({$unit->unit_code})" : '')),
                'sku' => $product->sku,
                'size_display' => $product->spec?->size_display,
                'condition' => $product->condition,
                'brand_name' => $product->brand?->name,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount' => Money::normalize($line['discount'] ?? 0),
            ];
            $priceLines[] = ['unit_price' => $price, 'quantity' => $qty, 'discount' => $line['discount'] ?? 0];
        }
        foreach ($serviceLines as $line) {
            $code = $line['service_code'] ?? 'other';
            $lines[] = [
                'item_type' => 'service',
                'service_code' => $code,
                'description' => $line['description'] ?? (self::SERVICE_CODES[$code] ?? 'Service'),
                'quantity' => (int) $line['quantity'],
                'unit_price' => Money::normalize($line['unit_price']),
                'discount' => Money::normalize($line['discount'] ?? 0),
            ];
            $priceLines[] = ['unit_price' => $line['unit_price'], 'quantity' => (int) $line['quantity'], 'discount' => $line['discount'] ?? 0];
        }

        try {
            $totals = $this->pricing->calculate($priceLines);
        } catch (\InvalidArgumentException $e) {
            throw new InventoryException($e->getMessage(), 422, 'invalid_pricing');
        }
        foreach ($lines as $i => $line) {
            $sale->items()->create($line + ['line_total' => $totals['lines'][$i]['line_total']]);
        }

        $sale->fill([
            'customer_name' => $data['customer_name'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'vehicle' => $data['vehicle'] ?? null,
            'notes' => $data['notes'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'payment_reference' => $data['payment_reference'] ?? null,
            'reservation_id' => $reservation?->id,
            'quotation_id' => $data['quotation_id'] ?? null,
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'vat_registered' => $totals['vat_registered'],
            'prices_include_vat' => $totals['prices_include_vat'],
            'vat_rate' => $totals['vat_rate'],
            'vat_amount' => $totals['vat_amount'],
            'total' => $totals['total'],
        ])->save();
    }

    /** Deduct stock for a sale whose row is locked by the caller. */
    protected function completeLocked(Sale $sale, array $data, User $user): void
    {
        if (! in_array($sale->payment_method, Sale::PAYMENT_METHODS, true)) {
            throw new InventoryException('Select a payment method (cash, card or EFT) to complete the sale.', 422, 'payment_method_required');
        }
        /** @var Collection<int, SaleItem> $items */
        $items = $sale->items()->where('item_type', 'product')->get();
        $products = $this->ledger->lockProducts($items->pluck('product_id'));

        // 1. Consume the reservation first so the reserved tyres become sellable to THIS sale only.
        if ($sale->reservation_id) {
            $reservation = Reservation::query()->with('items')->findOrFail($sale->reservation_id);
            $consume = [];
            foreach ($items as $item) {
                if ($item->reservation_item_id) {
                    $consume[$item->reservation_item_id] = ($consume[$item->reservation_item_id] ?? 0) + $item->quantity;
                }
            }
            $this->reservations->consume($reservation, $consume, $sale->id);
            foreach ($products as $product) {
                $this->ledger->refreshProductTotals($product);
            }
        }

        // 2. Check availability per product across all lines.
        $requested = $items->whereNull('tyre_unit_id')->groupBy('product_id')->map->sum('quantity');
        foreach ($requested as $productId => $qty) {
            $product = $products[$productId];
            if ($product->status !== Product::STATUS_ACTIVE) {
                throw new InventoryException("{$product->displayName()} is archived and cannot be sold.", 422, 'archived');
            }
            if ($product->availableQty() < $qty) {
                throw InventoryException::insufficientStock($product->displayName(), (int) $qty, $product->availableQty());
            }
        }

        // 3. Deduct stock and capture historical cost of sales.
        $ctx = ['reference' => $sale, 'reference_no' => $sale->sale_no, 'reason' => 'Sale'.($sale->customer_name ? ' to '.$sale->customer_name : ''), 'user_id' => $user->id];
        $costTotal = 0;
        foreach ($items as $item) {
            $product = $products[$item->product_id];
            $lineCost = 0;
            if ($item->tyre_unit_id) {
                $unit = TyreUnit::query()->whereKey($item->tyre_unit_id)->lockForUpdate()->firstOrFail();
                $allowed = $unit->status === TyreUnit::AVAILABLE || ($unit->status === TyreUnit::RESERVED && $item->reservation_item_id);
                if (! $allowed || ! $unit->location?->is_sellable) {
                    throw new InventoryException("Used tyre {$unit->unit_code} is not available for sale (status: {$unit->status}).", 409, 'unit_unavailable');
                }
                $item->forceFill(['location_id' => $unit->location_id]);
                $this->ledger->moveUnit($product, $unit, TyreUnit::SOLD, null, StockMovement::SALE, $ctx);
                $lineCost = Money::toCents($unit->cost_price);
            } else {
                foreach ($this->allocateLocations($product, $item) as [$location, $qty]) {
                    $movement = $this->ledger->adjust($product, $location, -$qty, StockMovement::SALE, $ctx);
                    $lineCost += Money::toCents($movement->total_cost);
                    $item->location_id ??= $location->id;
                }
            }
            $item->forceFill(['cost_total' => Money::fromCents($lineCost)])->save();
            $costTotal += $lineCost;
            $product->forceFill(['last_sold_at' => now()])->saveQuietly();
        }

        $amountPaid = isset($data['amount_paid']) && $data['amount_paid'] !== '' ? Money::normalize($data['amount_paid']) : (string) $sale->total;
        $sale->forceFill([
            'status' => Sale::COMPLETED,
            'completed_at' => now(),
            'cost_total' => Money::fromCents($costTotal),
            'amount_paid' => $amountPaid,
            'payment_status' => Money::cmp($amountPaid, $sale->total) >= 0 ? 'paid' : (Money::cmp($amountPaid, 0) > 0 ? 'partial' : 'unpaid'),
        ])->save();

        if ($sale->quotation_id) {
            Quotation::query()->whereKey($sale->quotation_id)->update(['status' => 'converted', 'converted_sale_id' => $sale->id]);
        }

        Audit::log('sale.completed', $sale, "Completed sale {$sale->sale_no}: R {$sale->total}", null, [
            'total' => (string) $sale->total, 'items' => $items->map->only(['product_id', 'tyre_unit_id', 'quantity', 'unit_price'])->all(),
        ]);
    }

    /**
     * Choose sellable locations to take stock from: the requested location, else the product's default
     * location first, then the locations holding the most stock.
     *
     * @return list<array{0:InventoryLocation,1:int}>
     */
    protected function allocateLocations(Product $product, SaleItem $item): array
    {
        if ($item->location_id) {
            $location = InventoryLocation::query()->findOrFail($item->location_id);
            if (! $location->is_sellable) {
                throw new InventoryException("{$location->name} is not a sellable location.", 422, 'location_not_sellable');
            }

            return [[$location, $item->quantity]];
        }
        $balances = StockBalance::query()->with('location')
            ->where('product_id', $product->id)->where('qty_on_hand', '>', 0)
            ->whereHas('location', fn ($q) => $q->where('is_sellable', true))
            ->get()
            ->sortBy([fn ($a, $b) => ($b->location_id === $product->default_location_id) <=> ($a->location_id === $product->default_location_id), ['qty_on_hand', 'desc']]);

        $remaining = $item->quantity;
        $plan = [];
        foreach ($balances as $balance) {
            if ($remaining === 0) {
                break;
            }
            $take = min($remaining, $balance->qty_on_hand);
            $plan[] = [$balance->location, $take];
            $remaining -= $take;
        }
        if ($remaining > 0) {
            throw InventoryException::insufficientStock($product->displayName(), $item->quantity, $item->quantity - $remaining);
        }

        return $plan;
    }

    protected function assertPriceAllowed(Product $product, ?TyreUnit $unit, string $price, User $user): void
    {
        if (Money::cmp($price, 0) < 0) {
            throw new InventoryException('Price cannot be negative.', 422, 'invalid_price');
        }
        $minimum = $unit ? null : $product->min_selling_price;
        if ($minimum !== null && Money::cmp($price, $minimum) < 0 && ! $user->hasPermission('sales.price_override')) {
            throw new InventoryException("The price for {$product->displayName()} is below the approved minimum selling price. A manager must approve this price.", 403, 'below_minimum_price');
        }
    }
}
