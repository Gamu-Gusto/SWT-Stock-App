<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Role;
use App\Models\TyreUnit;
use App\Models\User;
use App\Services\Inventory\TyreUnitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installReferenceData();
        $this->seller = $this->makeUser(Role::SALES);
    }

    protected function reserve(array $items, array $extra = [])
    {
        return $this->actingAs($this->seller)->postJson('/api/v1/reservations', $extra + [
            'customer_name' => 'J. Mokoena', 'customer_phone' => '076 000 0000', 'items' => $items,
        ]);
    }

    public function test_reservation_reduces_available_but_not_physical_stock(): void
    {
        $product = $this->makeTyre(10);

        $this->reserve([['product_id' => $product->id, 'quantity' => 4]])->assertCreated()->assertJsonPath('data.status', 'active');

        $product->refresh();
        $this->assertSame(10, $product->qty_on_hand);
        $this->assertSame(4, $product->qty_reserved);
        $this->assertSame(6, $product->availableQty());
    }

    public function test_cannot_reserve_more_than_available(): void
    {
        $product = $this->makeTyre(5);
        $this->reserve([['product_id' => $product->id, 'quantity' => 4]])->assertCreated();

        $this->reserve([['product_id' => $product->id, 'quantity' => 2]])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(4, $product->fresh()->qty_reserved);
    }

    public function test_cancellation_releases_reserved_quantity(): void
    {
        $product = $this->makeTyre(10);
        $id = $this->reserve([['product_id' => $product->id, 'quantity' => 4]])->json('data.id');

        $this->postJson("/api/v1/reservations/{$id}/cancel", ['reason' => 'Customer changed mind'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, $product->fresh()->qty_reserved);
        $this->assertSame(10, $product->fresh()->availableQty());
    }

    public function test_expired_reservations_release_stock(): void
    {
        $product = $this->makeTyre(10);
        $id = $this->reserve([['product_id' => $product->id, 'quantity' => 4]], ['expires_hours' => 24])->json('data.id');

        $this->travel(25)->hours();
        $this->artisan('swt:expire-reservations')->assertSuccessful();

        $this->assertSame('expired', Reservation::query()->find($id)->status);
        $this->assertSame(0, $product->fresh()->qty_reserved);
    }

    public function test_default_expiry_comes_from_settings(): void
    {
        $product = $this->makeTyre(2);
        $expires = $this->reserve([['product_id' => $product->id, 'quantity' => 1]])->json('data.expires_at');

        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, strtotime($expires), 60);
    }

    public function test_used_tyre_unit_can_be_reserved_once(): void
    {
        $owner = $this->makeUser();
        $used = $this->makeTyre(0, ['condition' => 'used'], $owner);
        $unit = app(TyreUnitService::class)->create($used, ['location_id' => $this->location('USED')->id, 'status' => 'available', 'selling_price' => '650', 'tread_depth_mm' => 6], $owner);

        $this->reserve([['tyre_unit_id' => $unit->id]])->assertCreated();
        $this->assertSame(TyreUnit::RESERVED, $unit->fresh()->status);
        $this->reserve([['tyre_unit_id' => $unit->id]])->assertStatus(409);
    }

    public function test_rejected_used_tyre_cannot_be_reserved_or_sold(): void
    {
        $owner = $this->makeUser();
        $used = $this->makeTyre(0, ['condition' => 'used'], $owner);
        $unit = app(TyreUnitService::class)->create($used, ['location_id' => $this->location('USED')->id, 'status' => 'rejected', 'selling_price' => '650'], $owner);

        $this->reserve([['tyre_unit_id' => $unit->id]])->assertStatus(409);
        $this->actingAs($this->seller)->postJson('/api/v1/sales', ['items' => [['tyre_unit_id' => $unit->id]], 'payment_method' => 'cash'])->assertStatus(409);
        $this->assertSame(0, $used->fresh()->qty_on_hand);
    }
}
