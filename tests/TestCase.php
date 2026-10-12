<?php

namespace Tests;

use App\Models\Brand;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Installer;
use App\Services\Inventory\ProductService;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    protected function installReferenceData(): void
    {
        app(Installer::class)->installReferenceData();
        app(AppSettings::class)->flush();
    }

    protected function makeUser(string $role = Role::OWNER, array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::query()->create($attributes + [
            'name' => ucfirst(str_replace('_', ' ', $role))." {$n}",
            'email' => "{$role}{$n}@example.test",
            'password' => Hash::make('Password123'),
            'role_id' => Role::query()->where('name', $role)->value('id'),
            'is_active' => true,
        ]);
    }

    protected function location(string $code = 'MAIN'): InventoryLocation
    {
        return InventoryLocation::query()->where('code', $code)->firstOrFail();
    }

    protected function brand(string $name = 'Falken'): Brand
    {
        return Brand::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    protected function supplier(): Supplier
    {
        return Supplier::query()->firstOrCreate(['company_name' => 'Example Tyre Distributor'], ['status' => 'active']);
    }

    /** Create a new-tyre product with optional opening stock at a location. */
    protected function makeTyre(int $qty = 0, array $overrides = [], ?User $user = null, string $location = 'MAIN'): Product
    {
        $user ??= User::query()->whereHas('role', fn ($q) => $q->where('name', Role::OWNER))->first() ?? $this->makeUser();

        return app(ProductService::class)->create($overrides + [
            'brand_id' => $this->brand()->id,
            'model_name' => 'Ziex ZE310',
            'width' => 205,
            'profile' => 55,
            'rim' => 16,
            'condition' => 'new',
            'load_index' => '91',
            'speed_rating' => 'V',
            'cost_price' => '900.00',
            'selling_price' => '1350.00',
            'min_stock_level' => 4,
            'default_location_id' => $this->location($location)->id,
            'opening_qty' => $qty,
            'opening_location_id' => $this->location($location)->id,
        ], $user);
    }
}
