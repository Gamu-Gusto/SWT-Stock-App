<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\InventoryLocation;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Installs reference data (roles, permissions, categories, storage locations, brands) and creates
 * the first owner account. Contains NO stock data – production starts with an empty inventory.
 */
class Installer
{
    public const LOCATIONS = [
        ['MAIN', 'Main Store', 'store', true, 10],
        ['RACK-A', 'Rack A', 'rack', true, 20],
        ['RACK-B', 'Rack B', 'rack', true, 30],
        ['RACK-C', 'Rack C', 'rack', true, 40],
        ['WORKSHOP', 'Workshop Area', 'workshop', true, 50],
        ['USED', 'Used Tyre Section', 'used', true, 60],
        ['QUARANTINE', 'Returns & Inspection (quarantine)', 'quarantine', false, 90],
        ['DAMAGED', 'Damaged Stock', 'damaged', false, 95],
    ];

    public const BRANDS = ['Falken', 'Michelin', 'Pirelli', 'Dunlop', 'Goodyear', 'Bridgestone', 'Continental', 'Hankook', 'Yokohama'];

    public function isInstalled(): bool
    {
        return User::query()->whereHas('role', fn ($q) => $q->where('name', Role::OWNER))->exists();
    }

    public function installReferenceData(): void
    {
        DB::transaction(function () {
            PermissionRegistry::sync();

            ProductCategory::query()->firstOrCreate(['slug' => ProductCategory::TYRES], ['name' => 'Tyres', 'has_tyre_specs' => true]);
            // Future categories are registered but inactive until the business expands into them.
            foreach (['mag_wheels' => 'Mag wheels', 'batteries' => 'Batteries', 'accessories' => 'Accessories'] as $slug => $name) {
                ProductCategory::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'has_tyre_specs' => false, 'is_active' => false]);
            }

            if (InventoryLocation::query()->doesntExist()) {
                foreach (self::LOCATIONS as [$code, $name, $type, $sellable, $sort]) {
                    InventoryLocation::query()->create(['code' => $code, 'name' => $name, 'type' => $type, 'is_sellable' => $sellable, 'sort_order' => $sort]);
                }
            }
            if (Brand::query()->doesntExist()) {
                foreach (self::BRANDS as $name) {
                    Brand::query()->create(['name' => $name, 'is_active' => true, 'is_preferred' => $name === 'Falken']);
                }
            }
        });
    }

    public function createOwner(string $name, string $email, string $password): User
    {
        if (strlen($password) < 10) {
            throw new InvalidArgumentException('The owner password must be at least 10 characters.');
        }
        if (User::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException('A user with that e-mail address already exists.');
        }
        $this->installReferenceData();
        $owner = User::query()->create([
            'name' => $name,
            'email' => strtolower($email),
            'password' => Hash::make($password),
            'role_id' => Role::query()->where('name', Role::OWNER)->value('id'),
            'is_active' => true,
        ]);
        Audit::log('user.owner_created', $owner, "Owner account created for {$owner->email}", null, null, $owner->id);

        return $owner;
    }
}
