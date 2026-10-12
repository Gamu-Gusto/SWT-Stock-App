<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\TyreUnit;
use App\Models\User;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\SalesService;
use App\Support\AppSettings;
use App\Support\Audit;
use App\Support\DocumentNumber;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Customer quotations. A quotation never affects inventory; it can be converted to a reservation or a sale.
 */
class QuotationService
{
    public function __construct(
        protected PricingCalculator $pricing,
        protected AppSettings $settings,
        protected ReservationService $reservations,
        protected SalesService $sales,
    ) {}

    public function create(array $data, User $user): Quotation
    {
        if (! empty($data['idempotency_key']) && ($existing = Quotation::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
            return $existing->load('items');
        }

        return DB::transaction(function () use ($data, $user) {
            $lines = [];
            $priceLines = [];
            foreach ($data['items'] ?? [] as $line) {
                $unit = ! empty($line['tyre_unit_id']) ? TyreUnit::query()->findOrFail($line['tyre_unit_id']) : null;
                $product = Product::query()->with(['brand', 'tyreModel', 'spec'])->findOrFail($unit?->product_id ?? $line['product_id']);
                $qty = $unit ? 1 : max(1, (int) $line['quantity']);
                $price = isset($line['unit_price']) && $line['unit_price'] !== '' ? Money::normalize($line['unit_price']) : ($unit ? (string) $unit->selling_price : $product->effectivePrice());
                $lines[] = [
                    'item_type' => 'product', 'product_id' => $product->id, 'tyre_unit_id' => $unit?->id,
                    'description' => trim($product->displayName().($product->condition === 'used' ? ' (used'.($unit?->tread_depth_mm ? ", {$unit->tread_depth_mm}mm" : '').')' : '')),
                    'size_display' => $product->spec?->size_display, 'quantity' => $qty, 'unit_price' => $price,
                    'discount' => Money::normalize($line['discount'] ?? 0),
                ];
                $priceLines[] = ['unit_price' => $price, 'quantity' => $qty, 'discount' => $line['discount'] ?? 0];
            }
            foreach ($data['services'] ?? [] as $line) {
                $code = $line['service_code'] ?? 'other';
                $lines[] = [
                    'item_type' => 'service', 'service_code' => $code,
                    'description' => $line['description'] ?? (SalesService::SERVICE_CODES[$code] ?? 'Service'),
                    'quantity' => (int) $line['quantity'], 'unit_price' => Money::normalize($line['unit_price']),
                    'discount' => Money::normalize($line['discount'] ?? 0),
                ];
                $priceLines[] = ['unit_price' => $line['unit_price'], 'quantity' => (int) $line['quantity'], 'discount' => $line['discount'] ?? 0];
            }
            if (! $lines) {
                throw new InventoryException('Add at least one tyre or service to the quotation.', 422, 'empty_quotation');
            }
            try {
                $totals = $this->pricing->calculate($priceLines);
            } catch (\InvalidArgumentException $e) {
                throw new InventoryException($e->getMessage(), 422, 'invalid_pricing');
            }

            $quotation = Quotation::query()->create([
                'quotation_no' => DocumentNumber::next('QT'),
                'status' => 'open',
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'vehicle' => $data['vehicle'] ?? null,
                'valid_until' => $data['valid_until'] ?? now()->addDays((int) $this->settings->get('quotation_valid_days', 7))->toDateString(),
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'user_id' => $user->id,
            ] + array_intersect_key($totals, array_flip(['subtotal', 'discount_total', 'vat_registered', 'prices_include_vat', 'vat_rate', 'vat_amount', 'total'])));
            foreach ($lines as $i => $line) {
                $quotation->items()->create($line + ['line_total' => $totals['lines'][$i]['line_total']]);
            }
            Audit::log('quotation.created', $quotation, "Quotation {$quotation->quotation_no}: R {$quotation->total}");

            return $quotation->load('items');
        });
    }

    public function cancel(Quotation $quotation): Quotation
    {
        if ($quotation->status !== 'open') {
            throw InventoryException::invalidState("Quotation {$quotation->quotation_no} is {$quotation->status}.");
        }
        $quotation->forceFill(['status' => 'cancelled'])->save();
        Audit::log('quotation.cancelled', $quotation, "Cancelled quotation {$quotation->quotation_no}");

        return $quotation;
    }

    public function toReservation(Quotation $quotation, array $data, User $user): Reservation
    {
        $this->assertConvertible($quotation);
        $items = $quotation->items()->where('item_type', 'product')->get()
            ->map(fn ($i) => $i->tyre_unit_id ? ['tyre_unit_id' => $i->tyre_unit_id] : ['product_id' => $i->product_id, 'quantity' => $i->quantity])->all();

        return DB::transaction(function () use ($quotation, $data, $user, $items) {
            $reservation = $this->reservations->create([
                'customer_name' => $data['customer_name'] ?? $quotation->customer_name,
                'customer_phone' => $data['customer_phone'] ?? $quotation->customer_phone,
                'notes' => "From quotation {$quotation->quotation_no}",
                'expires_hours' => $data['expires_hours'] ?? null,
                'quotation_id' => $quotation->id,
                'items' => $items,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ], $user);
            $quotation->forceFill(['status' => 'converted', 'converted_reservation_id' => $reservation->id])->save();

            return $reservation;
        });
    }

    public function toSale(Quotation $quotation, array $data, User $user): Sale
    {
        $this->assertConvertible($quotation);
        $products = $quotation->items()->where('item_type', 'product')->get()
            ->map(fn ($i) => ['product_id' => $i->product_id, 'tyre_unit_id' => $i->tyre_unit_id, 'quantity' => $i->quantity, 'unit_price' => (string) $i->unit_price, 'discount' => (string) $i->discount])->all();
        $services = $quotation->items()->where('item_type', 'service')->get()
            ->map(fn ($i) => ['service_code' => $i->service_code, 'description' => $i->description, 'quantity' => $i->quantity, 'unit_price' => (string) $i->unit_price, 'discount' => (string) $i->discount])->all();

        return $this->sales->create([
            'items' => $products,
            'services' => $services,
            'customer_name' => $quotation->customer_name,
            'customer_phone' => $quotation->customer_phone,
            'customer_email' => $quotation->customer_email,
            'vehicle' => $quotation->vehicle,
            'quotation_id' => $quotation->id,
        ] + $data, $user);
    }

    /** Shareable WhatsApp link (wa.me) with the quotation summary. No paid API required. */
    public function whatsappLink(Quotation $quotation): string
    {
        $business = $this->settings->get('business_name');
        $lines = ["*{$business} – Quotation {$quotation->quotation_no}*", ''];
        foreach ($quotation->items as $item) {
            $lines[] = "{$item->quantity} × {$item->description} @ R ".number_format((float) $item->unit_price, 2, '.', ' ').' = R '.number_format((float) $item->line_total, 2, '.', ' ');
        }
        $lines[] = '';
        if ($quotation->vat_registered) {
            $lines[] = 'VAT ('.rtrim(rtrim((string) $quotation->vat_rate, '0'), '.')."%): R {$quotation->vat_amount}";
        }
        $lines[] = '*Total: R '.number_format((float) $quotation->total, 2, '.', ' ').'*';
        $lines[] = 'Valid until '.$quotation->valid_until->format('d/m/Y').'. Subject to stock availability.';
        $lines[] = '';
        $lines[] = trim(str_replace("\n", ', ', (string) $this->settings->get('business_address')));
        $lines[] = 'WhatsApp/Call: '.$this->settings->get('business_phone');

        $phone = self::whatsappNumber($quotation->customer_phone);

        return 'https://wa.me/'.($phone ?? '').'?text='.rawurlencode(implode("\n", $lines));
    }

    /** Convert a South African number such as "076 454 4272" into the international "27764544272". */
    public static function whatsappNumber(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits === '' || $digits === null) {
            return null;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '27'.substr($digits, 1);
        }

        return $digits;
    }

    protected function assertConvertible(Quotation $quotation): void
    {
        if ($quotation->status !== 'open') {
            throw InventoryException::invalidState("Quotation {$quotation->quotation_no} is {$quotation->status} and cannot be converted.");
        }
    }
}
