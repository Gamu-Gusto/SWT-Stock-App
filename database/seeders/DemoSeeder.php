<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Installer;
use App\Services\Inventory\ProductService;
use App\Services\Inventory\ReceivingService;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\SalesService;
use App\Services\Inventory\TyreUnitService;
use App\Services\QuotationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * FICTIONAL DEMONSTRATION DATA – for development and training only.
 *
 * Every supplier, customer, price and quantity here is invented and clearly marked "DEMO".
 * It is NOT Super Wheels and Tyres stock information. Refuses to run in production.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'DemoPass2026';

    public function run(Installer $installer): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must never be loaded into a production installation.');
        }
        $installer->installReferenceData();

        $users = [];
        foreach ([
            Role::OWNER => ['Demo Owner', 'owner@demo.test'],
            Role::MANAGER => ['Demo Manager', 'manager@demo.test'],
            Role::CLERK => ['Demo Clerk', 'clerk@demo.test'],
            Role::SALES => ['Demo Sales', 'sales@demo.test'],
            Role::COUNTER => ['Demo Counter', 'counter@demo.test'],
        ] as $role => [$name, $email]) {
            $users[$role] = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'role_id' => Role::query()->where('name', $role)->value('id'),
                'is_active' => true,
            ]);
        }
        $owner = $users[Role::OWNER];
        auth()->setUser($owner);

        if (Product::query()->exists()) {
            $this->command?->warn('Products already exist – demo stock not added.');

            return;
        }

        $supplierA = Supplier::query()->create(['company_name' => 'DEMO Tyre Distributors (fictional)', 'contact_person' => 'Demo Contact', 'phone' => '012 000 0000', 'status' => 'active', 'notes' => 'Sample data – not a real supplier.']);
        $supplierB = Supplier::query()->create(['company_name' => 'DEMO Used Tyre Traders (fictional)', 'status' => 'active', 'notes' => 'Sample data – not a real supplier.']);

        $loc = fn (string $code) => InventoryLocation::query()->where('code', $code)->value('id');
        $brand = fn (string $name) => Brand::query()->where('name', $name)->value('id');
        $products = app(ProductService::class);

        $catalogue = [
            ['Falken', 'Ziex ZE310', 205, 55, 16, '91', 'V', 'passenger', '780.00', '1150.00', 'RACK-A', 6],
            ['Falken', 'Sincera SN110', 195, 65, 15, '91', 'H', 'passenger', '610.00', '895.00', 'RACK-A', 6],
            ['Falken', 'Azenis FK520', 225, 45, 17, '94', 'Y', 'performance', '1180.00', '1690.00', 'RACK-B', 4],
            ['Falken', 'Wildpeak A/T3W', 265, 65, 17, '112', 'T', 'all_terrain', '1650.00', '2350.00', 'MAIN', 4],
            ['Falken', 'Wildpeak A/T3W', 265, 65, 18, '114', 'T', 'all_terrain', '1820.00', '2590.00', 'MAIN', 4],
            ['Michelin', 'Primacy 4', 205, 55, 16, '91', 'W', 'passenger', '1050.00', '1520.00', 'RACK-A', 4],
            ['Bridgestone', 'Dueler H/T 684', 255, 70, 16, '111', 'T', 'highway_terrain', '1390.00', '1980.00', 'MAIN', 4],
            ['Continental', 'ContiPremiumContact 6', 225, 45, 17, '94', 'Y', 'performance', '1290.00', '1850.00', 'RACK-B', 4],
            ['Dunlop', 'SP Sport LM705', 195, 65, 15, '91', 'H', 'passenger', '640.00', '920.00', 'RACK-C', 4],
            ['Hankook', 'Kinergy Eco 2', 185, 65, 14, '86', 'H', 'passenger', '520.00', '760.00', 'RACK-C', 4],
        ];
        $created = [];
        foreach ($catalogue as [$b, $model, $w, $p, $r, $li, $sr, $type, $cost, $price, $location, $min]) {
            $created[] = $products->create([
                'brand_id' => $brand($b), 'model_name' => $model, 'width' => $w, 'profile' => $p, 'rim' => $r,
                'load_index' => $li, 'speed_rating' => $sr, 'tyre_type' => $type, 'condition' => 'new',
                'cost_price' => $cost, 'selling_price' => $price, 'fitting_fee' => '50.00', 'balancing_fee' => '60.00',
                'min_stock_level' => $min, 'reorder_qty' => 8, 'default_location_id' => $loc($location),
                'default_supplier_id' => $supplierA->id, 'description' => 'DEMO sample record – fictional price and quantity.',
            ], $owner);
        }

        // Opening stock via a posted demo delivery.
        $receiving = app(ReceivingService::class);
        $quantities = [20, 12, 8, 6, 4, 10, 4, 3, 9, 0];
        $items = [];
        foreach ($created as $i => $product) {
            if ($quantities[$i] > 0) {
                $items[] = ['product_id' => $product->id, 'location_id' => $product->default_location_id, 'quantity' => $quantities[$i], 'unit_cost' => (string) $product->cost_price];
            }
        }
        $receipt = $receiving->saveDraft(['supplier_id' => $supplierA->id, 'supplier_reference' => 'DEMO-INV-0001', 'delivery_date' => now()->subDays(20)->toDateString(), 'notes' => 'DEMO delivery (fictional)', 'items' => $items], $users[Role::CLERK]);
        $receiving->post($receipt, $users[Role::CLERK]);

        // Used tyres, individually identified.
        $used = $products->create([
            'brand_id' => $brand('Falken'), 'model_name' => 'Ziex ZE310', 'width' => 205, 'profile' => 55, 'rim' => 16,
            'condition' => 'used', 'tyre_type' => 'passenger', 'cost_price' => '250.00', 'selling_price' => '550.00',
            'default_location_id' => $loc('USED'), 'description' => 'DEMO used tyres – fictional.',
        ], $owner);
        $usedMichelin = $products->create([
            'brand_id' => $brand('Michelin'), 'model_name' => 'Primacy 4', 'width' => 225, 'profile' => 45, 'rim' => 17,
            'condition' => 'used', 'tyre_type' => 'passenger', 'cost_price' => '300.00', 'selling_price' => '650.00',
            'default_location_id' => $loc('USED'), 'description' => 'DEMO used tyres – fictional.',
        ], $owner);
        $units = app(TyreUnitService::class);
        foreach ([[$used, '6.5', 'A', '2224', '600.00', 'available'], [$used, '5.0', 'B', '1823', '480.00', 'available'], [$used, '3.0', 'C', '0921', '300.00', 'rejected'],
            [$usedMichelin, '7.0', 'A', '3124', '750.00', 'available'], [$usedMichelin, null, null, null, '650.00', 'pending_inspection']] as [$product, $tread, $grade, $dot, $price, $status]) {
            $units->create($product, [
                'location_id' => $loc('USED'), 'tread_depth_mm' => $tread, 'grade' => $grade, 'dot_code' => $dot,
                'selling_price' => $price, 'cost_price' => '250.00', 'status' => $status, 'supplier_id' => $supplierB->id,
                'inspection_notes' => $status === 'rejected' ? 'DEMO: sidewall bulge – not for sale' : 'DEMO inspection note',
            ], $owner);
        }

        // A few demo sales, a reservation and a quotation.
        $sales = app(SalesService::class);
        $sales->create(['items' => [['product_id' => $created[0]->id, 'quantity' => 4]], 'services' => [['service_code' => 'fitting', 'quantity' => 4, 'unit_price' => '50.00'], ['service_code' => 'balancing', 'quantity' => 4, 'unit_price' => '60.00']], 'customer_name' => 'DEMO Customer A', 'payment_method' => 'card'], $users[Role::SALES]);
        $sales->create(['items' => [['product_id' => $created[1]->id, 'quantity' => 2]], 'customer_name' => 'DEMO Customer B', 'payment_method' => 'cash'], $users[Role::SALES]);
        $sales->create(['items' => [['product_id' => $created[8]->id, 'quantity' => 6]], 'customer_name' => 'DEMO Fleet C', 'payment_method' => 'eft'], $users[Role::MANAGER]);
        app(ReservationService::class)->create(['customer_name' => 'DEMO Customer D', 'customer_phone' => '082 000 0001', 'items' => [['product_id' => $created[3]->id, 'quantity' => 4]], 'notes' => 'DEMO reservation'], $users[Role::SALES]);
        app(QuotationService::class)->create(['customer_name' => 'DEMO Customer E', 'customer_phone' => '082 000 0002', 'items' => [['product_id' => $created[2]->id, 'quantity' => 2]], 'services' => [['service_code' => 'alignment', 'quantity' => 1, 'unit_price' => '350.00']]], $users[Role::SALES]);

        $this->command?->info('DEMO data loaded. All demo accounts use the password '.self::PASSWORD.' (e.g. owner@demo.test). Never use these in production.');
    }
}
