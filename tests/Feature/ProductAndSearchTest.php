<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAndSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installReferenceData();
    }

    public function test_employee_can_register_a_tyre_and_size_is_generated(): void
    {
        $clerk = $this->makeUser(Role::CLERK);

        $response = $this->actingAs($clerk)->postJson('/api/v1/products', [
            'brand_id' => $this->brand()->id,
            'model_name' => 'Ziex ZE310',
            'width' => 205, 'profile' => 55, 'rim' => 16,
            'condition' => 'new',
            'load_index' => '91', 'speed_rating' => 'v',
            'selling_price' => '1350.00',
            'min_stock_level' => 4,
            'default_location_id' => $this->location()->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.spec.size', '205/55 R16')
            ->assertJsonPath('data.spec.speed_rating', 'V')
            ->assertJsonPath('data.condition', 'new')
            ->assertJsonPath('data.qty_available', 0)
            ->assertJsonPath('data.stock_status', 'out_of_stock')
            ->assertJsonMissingPath('data.cost_price'); // clerk has no financial access
        $this->assertStringStartsWith('FAL-2055516-N-', $response->json('data.sku'));
    }

    public function test_size_can_be_entered_as_one_string(): void
    {
        $this->actingAs($this->makeUser())->postJson('/api/v1/products', [
            'brand_id' => $this->brand()->id, 'model_name' => 'Wildpeak AT3W', 'size' => '265/65R17',
            'condition' => 'new', 'selling_price' => '2600',
        ])->assertCreated()->assertJsonPath('data.spec.size', '265/65 R17');
    }

    public function test_new_and_used_versions_are_separate_records_and_duplicates_are_blocked(): void
    {
        $owner = $this->makeUser();
        $new = $this->makeTyre(0, [], $owner);
        $used = $this->makeTyre(0, ['condition' => 'used'], $owner);

        $this->assertNotSame($new->id, $used->id);
        $this->assertSame('quantity', $new->tracking);
        $this->assertSame('unit', $used->tracking);

        $this->actingAs($owner)->postJson('/api/v1/products', [
            'brand_id' => $this->brand()->id, 'model_name' => 'Ziex ZE310', 'width' => 205, 'profile' => 55, 'rim' => 16,
            'load_index' => '91', 'speed_rating' => 'V', 'condition' => 'new', 'selling_price' => '1',
        ])->assertStatus(409)->assertJsonPath('code', 'duplicate_product');
    }

    public function test_opening_stock_creates_a_movement(): void
    {
        $product = $this->makeTyre(10);

        $this->assertSame(10, $product->qty_on_hand);
        $movement = StockMovement::query()->where('product_id', $product->id)->sole();
        $this->assertSame(StockMovement::OPENING_BALANCE, $movement->type);
        $this->assertSame(0, $movement->previous_qty);
        $this->assertSame(10, $movement->resulting_qty);
    }

    public function test_search_normalises_common_size_formats(): void
    {
        $owner = $this->makeUser();
        $falken = $this->makeTyre(8, [], $owner);
        $michelin = $this->makeTyre(2, ['brand_id' => $this->brand('Michelin')->id, 'model_name' => 'Primacy 4'], $owner);
        $used = $this->makeTyre(0, ['condition' => 'used'], $owner);
        $other = $this->makeTyre(5, ['width' => 195, 'profile' => 65, 'rim' => 15], $owner);

        $ids = fn (string $q) => collect($this->actingAs($owner)->getJson('/api/v1/products?q='.urlencode($q))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $all205 = collect([$falken->id, $michelin->id, $used->id])->sort()->values()->all();

        $this->assertSame($all205, $ids('205/55 R16'));
        $this->assertSame($all205, $ids('205/55R16'));
        $this->assertSame($all205, $ids('205 55 16'));
        $this->assertSame([$falken->id, $used->id], $ids('Falken 205/55 R16'));
        $this->assertSame([$used->id], $ids('Used 205/55 R16'));
        $this->assertContains($other->id, $ids('R15'));
        $this->assertNotContains($other->id, $ids('R16'));
    }

    public function test_search_ignores_letter_case_on_every_database(): void
    {
        $owner = $this->makeUser();
        $falken = $this->makeTyre(4, [], $owner);

        $ids = fn (string $q) => collect($this->actingAs($owner)->getJson('/api/v1/products?q='.urlencode($q))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$falken->id], $ids('falken 205/55r16'));
        $this->assertSame([$falken->id], $ids('ZIEX'));
        $this->assertSame([$falken->id], $ids(strtolower($falken->sku)));
    }

    public function test_search_result_shows_quantities_and_status(): void
    {
        $owner = $this->makeUser();
        $product = $this->makeTyre(3, [], $owner); // min stock 4 → low stock

        $row = $this->actingAs($owner)->getJson('/api/v1/products?q=205/55R16')->json('data.0');
        $this->assertSame($product->id, $row['id']);
        $this->assertSame(3, $row['qty_physical']);
        $this->assertSame(0, $row['qty_reserved']);
        $this->assertSame(3, $row['qty_available']);
        $this->assertSame('low_stock', $row['stock_status']);
        $this->assertSame('Main Store', $row['locations'][0]['name']);
    }

    public function test_product_with_stock_cannot_be_archived(): void
    {
        $owner = $this->makeUser();
        $product = $this->makeTyre(2, [], $owner);

        $this->actingAs($owner)->postJson("/api/v1/products/{$product->id}/archive")->assertStatus(409);
    }
}
