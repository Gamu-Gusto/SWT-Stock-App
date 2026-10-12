<?php

namespace Database\Seeders;

use App\Services\Installer;
use Illuminate\Database\Seeder;

/**
 * Production-safe seeder: installs reference data only (roles, permissions, categories,
 * default storage locations and brand names). It never creates stock, prices or users.
 *
 * Fictional demonstration data is available separately for development:
 *   php artisan db:seed --class=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(Installer $installer): void
    {
        $installer->installReferenceData();
    }
}
