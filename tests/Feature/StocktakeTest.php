<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StocktakeCount;
use App\Models\StocktakeItem;
use App\Models\TyreUnit;
use App\Models\User;
use App\Services\Inventory\TyreUnitService;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakeTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installReferenceData();
        $this->manager = $this->makeUser(Role::MANAGER);
        $this->counter = $this->makeUser(Role::COUNTER);
    }

    protected function startStocktake(array $extra = []): int
    {
        $id = $this->actingAs($this->manager)->postJson('/api/v1/stocktakes', $extra + [
            'title' => 'Main store count', 'type' => 'location', 'location_id' => $this->location()->id,
            'assignee_ids' => [$this->counter->id], 'is_blind' => true, 'start' => true,
        ])->assertCreated()->json('data.id');
        $this->assertSame(Stocktake::IN_PROGRESS, Stocktake::query()->find($id)->status);

        return $id;
    }

    /** Example test 3: system 20, counted 18 → shortage 2, unchanged until approval, then 18 with an adjustment movement. */
    public function test_shortage_is_calculated_and_applied_only_after_approval(): void
    {
        $product = $this->makeTyre(20);
        $id = $this->startStocktake();

        $this->actingAs($this->counter)->postJson("/api/v1/stocktakes/{$id}/count", [
            'product_id' => $product->id, 'location_id' => $this->location()->id, 'counted_qty' => 18,
        ])->assertOk();

        $this->assertSame(20, $product->fresh()->qty_on_hand, 'Counting must not change stock');
        $this->postJson("/api/v1/stocktakes/{$id}/submit")->assertOk();

        $items = $this->actingAs($this->manager)->getJson("/api/v1/stocktakes/{$id}/items")->json('data');
        $this->assertSame(20, $items[0]['system_qty_at_count']);
        $this->assertSame(18, $items[0]['counted_qty']);
        $this->assertSame(-2, $items[0]['variance']);
        $this->assertSame(20, $product->fresh()->qty_on_hand);

        $this->postJson("/api/v1/stocktakes/{$id}/approve", [
            'decisions' => [['item_id' => $items[0]['id'], 'approve' => true, 'reason' => 'Two tyres unaccounted for']],
        ])->assertOk();

        $this->assertSame(18, $product->fresh()->qty_on_hand);
        $movement = StockMovement::query()->where('type', StockMovement::STOCKTAKE_ADJUSTMENT)->sole();
        $this->assertSame(-2, $movement->quantity_change);
        $this->assertSame($this->manager->id, $movement->approved_by);
        $this->assertSame(Stocktake::APPROVED, Stocktake::query()->find($id)->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'stocktake.approved', 'entity_id' => $id]);
    }

    public function test_blind_count_hides_expected_quantities_from_counter(): void
    {
        $this->makeTyre(20);
        $id = $this->startStocktake();

        $item = $this->actingAs($this->counter)->getJson("/api/v1/stocktakes/{$id}/items")->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('snapshot_qty', $item);
        $this->assertArrayNotHasKey('system_qty_at_count', $item);
        $this->assertArrayNotHasKey('variance', $item);

        $counted = $this->postJson("/api/v1/stocktakes/{$id}/count", ['item_id' => $item['id'], 'counted_qty' => 19])->json('data');
        $this->assertArrayNotHasKey('variance', $counted);
    }

    public function test_duplicate_count_requires_confirmation_and_history_is_kept(): void
    {
        $product = $this->makeTyre(20);
        $id = $this->startStocktake();
        $payload = ['product_id' => $product->id, 'location_id' => $this->location()->id, 'counted_qty' => 18];

        $this->actingAs($this->counter)->postJson("/api/v1/stocktakes/{$id}/count", $payload)->assertOk();
        $this->postJson("/api/v1/stocktakes/{$id}/count", $payload + ['counted_qty' => 17])->assertStatus(409)->assertJsonPath('code', 'duplicate_count');
        $this->postJson("/api/v1/stocktakes/{$id}/count", ['counted_qty' => 19, 'replace' => true] + $payload)->assertOk();

        $this->assertSame(2, StocktakeCount::query()->count());
        $this->assertSame(19, StocktakeItem::query()->sole()->counted_qty);
    }

    public function test_counter_cannot_approve_and_unassigned_user_cannot_count(): void
    {
        $product = $this->makeTyre(20);
        $id = $this->startStocktake();
        $this->actingAs($this->counter)->postJson("/api/v1/stocktakes/{$id}/approve")->assertForbidden();

        $other = $this->makeUser(Role::COUNTER);
        $this->actingAs($other)->postJson("/api/v1/stocktakes/{$id}/count", [
            'product_id' => $product->id, 'location_id' => $this->location()->id, 'counted_qty' => 1,
        ])->assertForbidden();
    }

    public function test_manager_who_counted_cannot_approve_own_discrepancies(): void
    {
        $product = $this->makeTyre(20);
        $id = $this->startStocktake(['assignee_ids' => [$this->manager->id]]);
        $this->actingAs($this->manager)->postJson("/api/v1/stocktakes/{$id}/count", ['product_id' => $product->id, 'location_id' => $this->location()->id, 'counted_qty' => 10])->assertOk();
        $this->postJson("/api/v1/stocktakes/{$id}/submit")->assertOk();

        $this->postJson("/api/v1/stocktakes/{$id}/approve", ['notes' => 'ok'])->assertStatus(403)->assertJsonPath('code', 'self_approval');
        $this->assertSame(20, $product->fresh()->qty_on_hand);
    }

    public function test_block_mode_prevents_sales_of_stock_under_count(): void
    {
        $product = $this->makeTyre(20);
        $this->startStocktake();

        $this->actingAs($this->makeUser(Role::SALES))->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method' => 'cash',
        ])->assertStatus(423)->assertJsonPath('code', 'stocktake_locked');
        $this->assertSame(20, $product->fresh()->qty_on_hand);
    }

    public function test_track_mode_records_system_quantity_at_time_of_count(): void
    {
        app(AppSettings::class)->set(['stocktake_lock_mode' => 'track']);
        $product = $this->makeTyre(20);
        $id = $this->startStocktake();

        // A sale happens during the count, before this tyre is counted.
        $this->actingAs($this->makeUser(Role::SALES))->postJson('/api/v1/sales', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payment_method' => 'cash'])->assertCreated();
        $this->actingAs($this->counter)->postJson("/api/v1/stocktakes/{$id}/count", ['product_id' => $product->id, 'location_id' => $this->location()->id, 'counted_qty' => 18])->assertOk();
        $this->postJson("/api/v1/stocktakes/{$id}/submit")->assertOk();

        $item = $this->actingAs($this->manager)->getJson("/api/v1/stocktakes/{$id}/items")->json('data.0');
        $this->assertSame(20, $item['snapshot_qty']);
        $this->assertSame(18, $item['system_qty_at_count']);
        $this->assertSame(0, $item['variance'], 'The sale is not mistaken for a shortage');
        $this->assertSame(-2, $item['movements_during_count']);
    }

    public function test_recount_returns_lines_to_the_counter(): void
    {
        $product = $this->makeTyre(20);
        $id = $this->startStocktake();
        $this->actingAs($this->counter)->postJson("/api/v1/stocktakes/{$id}/count", ['product_id' => $product->id, 'location_id' => $this->location()->id, 'counted_qty' => 2])->assertOk();
        $this->postJson("/api/v1/stocktakes/{$id}/submit")->assertOk();

        $itemId = $this->actingAs($this->manager)->getJson("/api/v1/stocktakes/{$id}/items")->json('data.0.id');
        $this->postJson("/api/v1/stocktakes/{$id}/recount", ['item_ids' => [$itemId], 'reason' => 'Large difference – recount rack'])->assertOk();
        $this->assertSame(Stocktake::IN_PROGRESS, Stocktake::query()->find($id)->status);

        $this->actingAs($this->counter)->postJson("/api/v1/stocktakes/{$id}/count", ['item_id' => $itemId, 'counted_qty' => 20])->assertOk();
        $this->postJson("/api/v1/stocktakes/{$id}/submit")->assertOk();
        $this->actingAs($this->manager)->postJson("/api/v1/stocktakes/{$id}/approve", ['notes' => 'Recounted'])->assertOk();
        $this->assertSame(20, $product->fresh()->qty_on_hand);
        $this->assertSame(0, StockMovement::query()->where('type', StockMovement::STOCKTAKE_ADJUSTMENT)->count());
    }

    public function test_missing_used_tyre_is_marked_missing_after_approval(): void
    {
        $owner = $this->makeUser();
        $used = $this->makeTyre(0, ['condition' => 'used'], $owner);
        $units = app(TyreUnitService::class);
        $a = $units->create($used, ['location_id' => $this->location('USED')->id, 'status' => 'available', 'selling_price' => '600'], $owner);
        $b = $units->create($used, ['location_id' => $this->location('USED')->id, 'status' => 'available', 'selling_price' => '600'], $owner);

        $id = $this->startStocktake(['type' => 'used', 'location_id' => null]);
        $this->actingAs($this->counter)->postJson("/api/v1/stocktakes/{$id}/count", ['unit_code' => $a->unit_code, 'location_id' => $this->location('USED')->id, 'counted_qty' => 1])->assertOk();
        $this->postJson("/api/v1/stocktakes/{$id}/submit", ['uncounted_as_zero' => true])->assertOk();
        $this->actingAs($this->manager)->postJson("/api/v1/stocktakes/{$id}/approve", ['notes' => 'Tyre not found on rack'])->assertOk();

        $this->assertSame(TyreUnit::AVAILABLE, $a->fresh()->status);
        $this->assertSame(TyreUnit::MISSING, $b->fresh()->status);
        $this->assertSame(1, $used->fresh()->qty_on_hand);
    }

    public function test_uncounted_lines_block_submission_unless_confirmed(): void
    {
        $this->makeTyre(20);
        $id = $this->startStocktake();

        $this->actingAs($this->counter)->postJson("/api/v1/stocktakes/{$id}/submit")->assertStatus(422)->assertJsonPath('code', 'uncounted_lines');
    }
}
