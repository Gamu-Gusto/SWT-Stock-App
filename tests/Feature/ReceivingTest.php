<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\TyreUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceivingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installReferenceData();
    }

    protected function receive(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/receipts', $extra + [
            'supplier_id' => $this->supplier()->id,
            'supplier_reference' => 'INV-1001',
            'delivery_date' => now()->toDateString(),
            'items' => $items,
        ]);
    }

    public function test_posting_a_receipt_increases_stock_and_records_movements(): void
    {
        $clerk = $this->makeUser(Role::CLERK);
        $product = $this->makeTyre(12);

        $this->actingAs($clerk);
        $draft = $this->receive([['product_id' => $product->id, 'location_id' => $this->location()->id, 'quantity' => 20, 'unit_cost' => '950.00']])
            ->assertCreated()->assertJsonPath('data.status', 'draft');

        // A draft does not change stock.
        $this->assertSame(12, $product->fresh()->qty_on_hand);

        $this->postJson("/api/v1/receipts/{$draft->json('data.id')}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $product->refresh();
        $this->assertSame(32, $product->qty_on_hand);
        $this->assertSame('950.00', (string) $product->cost_price);
        $movement = StockMovement::query()->where('type', StockMovement::RECEIVED)->sole();
        $this->assertSame(12, $movement->previous_qty);
        $this->assertSame(20, $movement->quantity_change);
        $this->assertSame(32, $movement->resulting_qty);
        $this->assertSame($clerk->id, $movement->user_id);
        $this->assertSame(20, StockLot::query()->where('source_type', 'receipt')->sole()->qty_remaining);
    }

    public function test_a_receipt_cannot_be_posted_twice(): void
    {
        $this->actingAs($this->makeUser(Role::CLERK));
        $product = $this->makeTyre(0);
        $id = $this->receive([['product_id' => $product->id, 'location_id' => $this->location()->id, 'quantity' => 4]], ['post' => true])->json('data.id');

        $this->postJson("/api/v1/receipts/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'already_posted');
        $this->assertSame(4, $product->fresh()->qty_on_hand);
        $this->assertSame(1, StockMovement::query()->where('type', StockMovement::RECEIVED)->count());
    }

    public function test_same_idempotency_key_does_not_create_a_second_receipt(): void
    {
        $this->actingAs($this->makeUser(Role::CLERK));
        $product = $this->makeTyre(0);
        $payload = [['product_id' => $product->id, 'location_id' => $this->location()->id, 'quantity' => 4]];

        $a = $this->receive($payload, ['idempotency_key' => 'abc-123'])->json('data.id');
        $b = $this->receive($payload, ['idempotency_key' => 'abc-123'])->json('data.id');
        $this->assertSame($a, $b);
    }

    public function test_duplicate_supplier_reference_requires_confirmation(): void
    {
        $this->actingAs($this->makeUser(Role::CLERK));
        $product = $this->makeTyre(0);
        $line = [['product_id' => $product->id, 'location_id' => $this->location()->id, 'quantity' => 2]];
        $this->receive($line, ['post' => true])->assertCreated();

        $second = $this->receive($line)->json('data.id');
        $this->postJson("/api/v1/receipts/{$second}/post")->assertStatus(409)->assertJsonPath('code', 'duplicate_reference');
        $this->postJson("/api/v1/receipts/{$second}/post", ['allow_duplicate_reference' => true])->assertOk();
        $this->assertSame(4, $product->fresh()->qty_on_hand);
    }

    public function test_posted_receipts_cannot_be_edited_and_are_corrected_by_reversal(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $product = $this->makeTyre(5, [], $owner);
        $id = $this->receive([['product_id' => $product->id, 'location_id' => $this->location()->id, 'quantity' => 10]], ['post' => true])->json('data.id');

        $this->putJson("/api/v1/receipts/{$id}", [
            'supplier_id' => $this->supplier()->id, 'delivery_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'location_id' => $this->location()->id, 'quantity' => 1]],
        ])->assertStatus(409);

        $this->postJson("/api/v1/receipts/{$id}/reverse", ['reason' => 'Captured against wrong supplier'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame(5, $product->fresh()->qty_on_hand);
        $this->assertSame(1, StockMovement::query()->where('type', StockMovement::RECEIPT_REVERSAL)->count());
    }

    public function test_used_tyres_received_become_individual_units(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $used = $this->makeTyre(0, ['condition' => 'used'], $owner);

        $this->receive([[
            'product_id' => $used->id, 'location_id' => $this->location('USED')->id, 'unit_cost' => '300',
            'unit_details' => [
                ['tread_depth_mm' => 6.5, 'grade' => 'A', 'dot_code' => '2223', 'selling_price' => '750', 'status' => 'available'],
                ['tread_depth_mm' => 4.0, 'grade' => 'C', 'selling_price' => '450', 'status' => 'rejected'],
            ],
        ]], ['post' => true])->assertCreated();

        $this->assertSame(2, TyreUnit::query()->where('product_id', $used->id)->count());
        $used->refresh();
        $this->assertSame(1, $used->qty_on_hand, 'Only the inspected, passed tyre is sellable');
        $this->assertSame(1, $used->qty_unsellable, 'The rejected tyre is physical but never sellable');
    }
}
