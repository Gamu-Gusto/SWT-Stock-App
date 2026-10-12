<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\ReceivingService;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installReferenceData();
        $this->seller = $this->makeUser(Role::SALES);
    }

    protected function sell(array $items, array $extra = [])
    {
        return $this->actingAs($this->seller)->postJson('/api/v1/sales', $extra + [
            'items' => $items,
            'payment_method' => 'card',
        ]);
    }

    /** Example test 1: 10 in stock, sell 4, 6 remain. */
    public function test_completed_sale_deducts_stock(): void
    {
        $product = $this->makeTyre(10);

        $this->sell([['product_id' => $product->id, 'quantity' => 4]])->assertCreated()->assertJsonPath('data.status', 'completed');

        $this->assertSame(6, $product->fresh()->qty_on_hand);
        $movement = StockMovement::query()->where('type', StockMovement::SALE)->sole();
        $this->assertSame([10, -4, 6], [$movement->previous_qty, $movement->quantity_change, $movement->resulting_qty]);
    }

    public function test_selling_more_than_available_is_rejected_without_changes(): void
    {
        $product = $this->makeTyre(3);

        $this->sell([['product_id' => $product->id, 'quantity' => 4]])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');

        $this->assertSame(3, $product->fresh()->qty_on_hand);
        $this->assertSame(0, Sale::query()->count(), 'The failed sale must be rolled back completely');
    }

    /** Example test 2: 10 physical, 3 reserved → 7 available; selling 8 is rejected. */
    public function test_reserved_stock_cannot_be_sold_to_another_customer(): void
    {
        $product = $this->makeTyre(10);
        $this->actingAs($this->seller)->postJson('/api/v1/reservations', [
            'customer_name' => 'T. Customer', 'customer_phone' => '0821234567',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        $product->refresh();
        $this->assertSame(10, $product->qty_on_hand);
        $this->assertSame(3, $product->qty_reserved);
        $this->assertSame(7, $product->availableQty());

        $this->sell([['product_id' => $product->id, 'quantity' => 8]])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->sell([['product_id' => $product->id, 'quantity' => 7]])->assertCreated();
        $this->assertSame(3, $product->fresh()->qty_on_hand);
    }

    public function test_selling_a_reservation_consumes_it_without_double_deduction(): void
    {
        $product = $this->makeTyre(10);
        $reservation = $this->actingAs($this->seller)->postJson('/api/v1/reservations', [
            'customer_name' => 'T. Customer', 'customer_phone' => '0821234567',
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->json('data');

        $this->sell([['product_id' => $product->id, 'quantity' => 4]], ['reservation_id' => $reservation['id']])->assertCreated();

        $product->refresh();
        $this->assertSame(6, $product->qty_on_hand);
        $this->assertSame(0, $product->qty_reserved);
        $this->assertSame(6, $product->availableQty());
        $this->assertSame('completed', Reservation::query()->find($reservation['id'])->status);
    }

    public function test_duplicate_submission_with_same_key_records_one_sale(): void
    {
        $product = $this->makeTyre(10);
        $line = [['product_id' => $product->id, 'quantity' => 2]];

        $first = $this->sell($line, ['idempotency_key' => 'sale-key-1'])->json('data.id');
        $second = $this->sell($line, ['idempotency_key' => 'sale-key-1'])->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(8, $product->fresh()->qty_on_hand);
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_draft_sale_does_not_deduct_until_completed_once(): void
    {
        $product = $this->makeTyre(5);
        $id = $this->sell([['product_id' => $product->id, 'quantity' => 2]], ['status' => 'draft'])->assertCreated()->json('data.id');
        $this->assertSame(5, $product->fresh()->qty_on_hand);

        $this->postJson("/api/v1/sales/{$id}/complete", ['payment_method' => 'cash'])->assertOk();
        $this->postJson("/api/v1/sales/{$id}/complete", ['payment_method' => 'cash'])->assertStatus(409);
        $this->assertSame(3, $product->fresh()->qty_on_hand);
    }

    public function test_sale_preserves_price_and_description_and_calculates_totals(): void
    {
        app(AppSettings::class)->set(['vat_registered' => true, 'vat_rate' => '15.00', 'prices_include_vat' => true]);
        $product = $this->makeTyre(10);

        $sale = $this->sell(
            [['product_id' => $product->id, 'quantity' => 4, 'discount' => '100.00']],
            ['services' => [['service_code' => 'fitting', 'quantity' => 4, 'unit_price' => '50.00'], ['service_code' => 'alignment', 'quantity' => 1, 'unit_price' => '350.00']]],
        )->assertCreated()->json('data');

        // 4 × 1350 = 5400 − 100 discount + 200 fitting + 350 alignment = 5850 incl. VAT
        $this->assertSame('5950.00', $sale['subtotal']);
        $this->assertSame('100.00', $sale['discount_total']);
        $this->assertSame('5850.00', $sale['total']);
        $this->assertSame('763.04', $sale['vat_amount']);
        $this->assertSame('Falken Ziex ZE310 205/55 R16', $sale['items'][0]['description']);

        // Later price changes do not alter the historical sale.
        $product->forceFill(['selling_price' => '1500.00'])->save();
        $this->assertSame('1350.00', (string) Sale::query()->find($sale['id'])->items()->first()->unit_price);
        $this->assertArrayNotHasKey('cost_total', $sale, 'Sales employees must not see cost of sales');
    }

    public function test_fifo_cost_is_allocated_to_sales(): void
    {
        $owner = $this->makeUser();
        $product = $this->makeTyre(2, ['cost_price' => '800.00'], $owner); // opening lot: 2 @ 800
        $receiving = app(ReceivingService::class);
        $receipt = $receiving->saveDraft([
            'supplier_id' => $this->supplier()->id, 'delivery_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'location_id' => $this->location()->id, 'quantity' => 4, 'unit_cost' => '1000.00']],
        ], $owner);
        $receiving->post($receipt, $owner);

        $sale = $this->actingAs($owner)->postJson('/api/v1/sales', ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'payment_method' => 'eft'])->json('data');

        // FIFO: 2 × 800 + 1 × 1000 = 2600
        $this->assertSame('2600.00', $sale['cost_total']);
        $this->assertSame(3, (int) $product->lots()->sum('qty_remaining'));
    }

    public function test_price_below_minimum_requires_override_permission(): void
    {
        $product = $this->makeTyre(5, ['min_selling_price' => '1200.00']);

        $this->sell([['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '1100.00']])->assertStatus(403)->assertJsonPath('code', 'below_minimum_price');
        $this->assertSame(5, $product->fresh()->qty_on_hand);
    }

    public function test_void_restores_stock_and_return_goes_to_quarantine_until_inspected(): void
    {
        $manager = $this->makeUser(Role::MANAGER);
        $product = $this->makeTyre(10);

        $saleA = $this->sell([['product_id' => $product->id, 'quantity' => 2]])->json('data');
        $this->actingAs($manager)->postJson("/api/v1/sales/{$saleA['id']}/void", ['reason' => 'Captured twice by mistake'])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->assertSame(10, $product->fresh()->qty_on_hand);

        $saleB = $this->sell([['product_id' => $product->id, 'quantity' => 4]])->json('data');
        $this->actingAs($manager)->postJson("/api/v1/sales/{$saleB['id']}/returns", [
            'reason' => 'Customer brought back two tyres',
            'items' => [['sale_item_id' => $saleB['items'][0]['id'], 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.status', 'partially_returned');

        $product->refresh();
        $this->assertSame(6, $product->qty_on_hand, 'Returned tyres are not sellable before inspection');
        $this->assertSame(2, $product->qty_unsellable);

        $pending = $this->actingAs($manager)->getJson('/api/v1/returns/pending')->json('data');
        $this->postJson("/api/v1/return-items/{$pending[0]['id']}/inspect", ['outcome' => 'damaged', 'notes' => 'Sidewall cut'])->assertOk();

        $product->refresh();
        $this->assertSame(6, $product->qty_on_hand, 'Damaged returns never become sellable');
        $this->assertSame(2, $product->qty_unsellable);
    }

    public function test_sales_employee_sees_only_own_sales(): void
    {
        $product = $this->makeTyre(10);
        $other = $this->makeUser(Role::SALES);
        $mine = $this->sell([['product_id' => $product->id, 'quantity' => 1]])->json('data.id');
        $theirs = $this->actingAs($other)->postJson('/api/v1/sales', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method' => 'cash'])->json('data.id');

        $list = collect($this->actingAs($this->seller)->getJson('/api/v1/sales')->json('data'))->pluck('id')->all();
        $this->assertSame([$mine], $list);
        $this->getJson("/api/v1/sales/{$theirs}")->assertForbidden();
    }
}
