<?php

namespace Tests\Feature;

use App\Models\AdjustmentRequest;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TransferAndAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installReferenceData();
    }

    protected function balance(int $productId, string $code): int
    {
        return (int) StockBalance::query()->where('product_id', $productId)->where('location_id', $this->location($code)->id)->value('qty_on_hand');
    }

    public function test_transfer_moves_stock_between_locations_atomically(): void
    {
        $product = $this->makeTyre(10);
        $this->actingAs($this->makeUser(Role::CLERK))->postJson('/api/v1/transfers', [
            'from_location_id' => $this->location('MAIN')->id, 'to_location_id' => $this->location('RACK-A')->id,
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertCreated();

        $this->assertSame(6, $this->balance($product->id, 'MAIN'));
        $this->assertSame(4, $this->balance($product->id, 'RACK-A'));
        $this->assertSame(10, $product->fresh()->qty_on_hand, 'Total stock is unchanged by a transfer');
        $this->assertSame(2, StockMovement::query()->where('type', StockMovement::TRANSFER)->count());
    }

    public function test_transfer_of_more_than_held_is_rejected_and_nothing_changes(): void
    {
        $product = $this->makeTyre(3);
        $other = $this->makeTyre(5, ['width' => 195, 'profile' => 65, 'rim' => 15]);

        $this->actingAs($this->makeUser(Role::CLERK))->postJson('/api/v1/transfers', [
            'from_location_id' => $this->location('MAIN')->id, 'to_location_id' => $this->location('RACK-B')->id,
            'items' => [['product_id' => $other->id, 'quantity' => 5], ['product_id' => $product->id, 'quantity' => 4]],
        ])->assertStatus(409);

        $this->assertSame(5, $this->balance($other->id, 'MAIN'), 'Earlier lines are rolled back too');
        $this->assertSame(0, $this->balance($other->id, 'RACK-B'));
    }

    public function test_clerk_cannot_approve_own_adjustment_and_manager_approval_posts_it(): void
    {
        $clerk = $this->makeUser(Role::CLERK);
        $product = $this->makeTyre(10);

        $id = $this->actingAs($clerk)->postJson('/api/v1/adjustments', [
            'type' => 'manual_adjustment', 'product_id' => $product->id, 'location_id' => $this->location()->id,
            'quantity_change' => -1, 'reason' => 'Tyre found cut during fitting',
        ])->assertCreated()->json('data.id');
        $this->assertSame(10, $product->fresh()->qty_on_hand, 'Requests do not change stock');

        $this->postJson("/api/v1/adjustments/{$id}/approve")->assertForbidden();

        $manager = $this->makeUser(Role::MANAGER);
        $this->actingAs($manager)->postJson("/api/v1/adjustments/{$id}/approve", ['notes' => 'Confirmed'])->assertOk();
        $this->assertSame(9, $product->fresh()->qty_on_hand);
        $movement = StockMovement::query()->where('type', StockMovement::MANUAL_ADJUSTMENT)->sole();
        $this->assertSame($manager->id, $movement->approved_by);
        $this->assertSame('Tyre found cut during fitting', $movement->reason);
        $this->assertDatabaseHas('activity_logs', ['action' => 'adjustment.approved', 'entity_id' => $id]);
    }

    public function test_manager_cannot_self_approve_but_owner_can(): void
    {
        $manager = $this->makeUser(Role::MANAGER);
        $product = $this->makeTyre(10);
        $id = $this->actingAs($manager)->postJson('/api/v1/adjustments', [
            'type' => 'write_off', 'product_id' => $product->id, 'location_id' => $this->location()->id, 'quantity_change' => 1, 'reason' => 'Bead damage',
        ])->json('data.id');
        $this->postJson("/api/v1/adjustments/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'self_approval');

        $owner = $this->makeUser();
        $ownId = $this->actingAs($owner)->postJson('/api/v1/adjustments', [
            'type' => 'write_off', 'product_id' => $product->id, 'location_id' => $this->location()->id, 'quantity_change' => 1, 'reason' => 'Owner write off',
        ])->json('data.id');
        $this->postJson("/api/v1/adjustments/{$ownId}/approve")->assertOk();
        $this->assertSame(9, $product->fresh()->qty_on_hand);
    }

    public function test_damaged_stock_is_removed_from_sellable_availability(): void
    {
        $product = $this->makeTyre(10);
        $adjustment = AdjustmentRequest::query()->create([
            'adjustment_no' => 'ADJ-T-1', 'type' => 'damaged', 'product_id' => $product->id, 'location_id' => $this->location()->id,
            'quantity_change' => 2, 'reason' => 'Damaged in storage', 'status' => 'pending', 'requested_by' => $this->makeUser(Role::CLERK)->id,
        ]);
        $this->actingAs($this->makeUser(Role::MANAGER))->postJson("/api/v1/adjustments/{$adjustment->id}/approve")->assertOk();

        $product->refresh();
        $this->assertSame(8, $product->qty_on_hand);
        $this->assertSame(2, $product->qty_unsellable);
        $this->assertSame(2, $this->balance($product->id, 'DAMAGED'));
    }

    public function test_adjustment_cannot_make_stock_negative(): void
    {
        $product = $this->makeTyre(1);
        $requester = $this->makeUser(Role::CLERK);
        $adjustment = AdjustmentRequest::query()->create([
            'adjustment_no' => 'ADJ-T-2', 'type' => 'manual_adjustment', 'product_id' => $product->id, 'location_id' => $this->location()->id,
            'quantity_change' => -3, 'reason' => 'Test', 'status' => 'pending', 'requested_by' => $requester->id,
        ]);

        $this->actingAs($this->makeUser(Role::MANAGER))->postJson("/api/v1/adjustments/{$adjustment->id}/approve")->assertStatus(409);
        $this->assertSame(1, $product->fresh()->qty_on_hand);
    }

    public function test_stock_movements_are_immutable(): void
    {
        $product = $this->makeTyre(5);
        $movement = StockMovement::query()->where('product_id', $product->id)->firstOrFail();

        try {
            $movement->update(['quantity_change' => 50]);
            $this->fail('Movement update should have been blocked');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_reserved_stock_cannot_be_written_off_from_under_a_reservation(): void
    {
        $product = $this->makeTyre(4);
        $this->actingAs($this->makeUser(Role::SALES))->postJson('/api/v1/reservations', [
            'customer_name' => 'A', 'customer_phone' => '0820000000', 'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertCreated();
        $adjustment = AdjustmentRequest::query()->create([
            'adjustment_no' => 'ADJ-T-3', 'type' => 'write_off', 'product_id' => $product->id, 'location_id' => $this->location()->id,
            'quantity_change' => 1, 'reason' => 'Test', 'status' => 'pending', 'requested_by' => $this->makeUser(Role::CLERK)->id,
        ]);

        $this->actingAs($this->makeUser(Role::MANAGER))->postJson("/api/v1/adjustments/{$adjustment->id}/approve")->assertStatus(409)->assertJsonPath('code', 'reserved_exceeds_stock');
        $this->assertSame(4, $product->fresh()->qty_on_hand);
    }
}
