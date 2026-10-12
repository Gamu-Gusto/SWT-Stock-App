<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Services\ReportService;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportReportQuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installReferenceData();
    }

    protected function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('stock.csv', $content);
    }

    public function test_import_validates_previews_and_creates_opening_movements(): void
    {
        $owner = $this->makeUser();
        $existing = $this->makeTyre(3, [], $owner);
        $csv = "Brand,Model,Width,Profile,Rim,Condition,Quantity,Cost Price,Selling Price,Supplier,Location\n"
            ."Falken,Ziex ZE310,205,55,16,New,4,900,1350,,Main Store\n"       // duplicate of existing (but load/speed blank → still matches)
            ."Michelin,Primacy 4,225,45,17,New,6,1400.50,2100,ABC Tyres,Rack A\n"
            ."Dunlop,SP Sport,999,55,16,New,2,800,1100,,Main Store\n"          // invalid width
            ."Hankook,Ventus,195,65,15,Used,2,300,650,,Used Tyre Section\n"
            ."Pirelli,P7,205,55,16,New,abc,900,1300,,Main Store\n";            // invalid quantity

        $import = $this->actingAs($owner)->postJson('/api/v1/imports', ['file' => $this->csv($csv)])->assertCreated()->json('data');
        $this->assertSame(0, $import['mapping']['brand']);
        $this->assertSame(10, $import['mapping']['location']);

        $preview = $this->postJson("/api/v1/imports/{$import['id']}/preview", ['mapping' => $import['mapping'], 'duplicate_mode' => 'skip'])->assertOk()->json('data');
        $this->assertSame(['total_rows' => 5, 'valid' => 2, 'duplicates' => 1, 'invalid' => 2, 'quantity_to_import' => 8], $preview['summary']);
        $this->assertSame(1, Product::query()->count(), 'Preview must not write anything');

        $result = $this->postJson("/api/v1/imports/{$import['id']}/commit", ['mapping' => $import['mapping'], 'duplicate_mode' => 'skip'])->assertOk()->json('data');
        $this->assertSame(2, $result['summary']['products_created']);
        $this->assertSame(1, $result['summary']['skipped']);
        $this->assertSame(2, $result['summary']['invalid']);
        $this->assertSame(8, $result['summary']['total_quantity_imported']);
        $this->assertSame(3, $existing->fresh()->qty_on_hand, 'Existing quantities are never replaced');
        $this->assertSame(8, (int) StockMovement::query()->where('reference_type', 'StockImport')->sum('quantity_change'));

        $this->postJson("/api/v1/imports/{$import['id']}/commit", ['mapping' => $import['mapping'], 'duplicate_mode' => 'skip'])->assertStatus(409);
    }

    public function test_import_add_mode_adds_to_existing_stock(): void
    {
        $owner = $this->makeUser();
        $existing = $this->makeTyre(3, [], $owner);
        $csv = "Brand,Model,Width,Profile,Rim,Condition,Quantity,Selling Price,Location,Load Index,Speed Rating\nFalken,Ziex ZE310,205,55,16,New,4,1350,Main Store,91,V\n";

        $import = $this->actingAs($owner)->postJson('/api/v1/imports', ['file' => $this->csv($csv)])->json('data');
        $this->postJson("/api/v1/imports/{$import['id']}/commit", ['mapping' => $import['mapping'], 'duplicate_mode' => 'add'])->assertOk();

        $this->assertSame(7, $existing->fresh()->qty_on_hand);
    }

    public function test_import_template_downloads(): void
    {
        $this->actingAs($this->makeUser())->get('/api/v1/imports/template')->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=swt-stock-import-template.xlsx');
    }

    public function test_reports_export_as_csv_and_xlsx(): void
    {
        $owner = $this->makeUser();
        $this->makeTyre(5, [], $owner);

        $csv = $this->actingAs($owner)->get('/api/v1/reports/current_stock/export?format=csv')->assertOk();
        $this->assertStringContainsString('205/55 R16', $csv->streamedContent());
        $this->actingAs($owner)->get('/api/v1/reports/stock_by_size/export?format=xlsx')->assertOk();
    }

    public function test_all_reports_run(): void
    {
        $owner = $this->makeUser();
        $product = $this->makeTyre(5, [], $owner);
        $this->actingAs($owner)->postJson('/api/v1/sales', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payment_method' => 'cash'])->assertCreated();

        foreach (array_keys(ReportService::REPORTS) as $key) {
            $this->getJson("/api/v1/reports/{$key}?group_by=brand")->assertOk()->assertJsonStructure(['data' => ['columns', 'rows', 'summary']]);
        }
        $sales = $this->getJson('/api/v1/reports/sales?group_by=size')->json('data.rows.0');
        $this->assertSame(['205/55 R16', 2, '2700.00'], [$sales['group'], $sales['tyres'], $sales['revenue']]);
        $this->assertSame('900.00', $sales['gross_margin']);
    }

    public function test_quotation_does_not_reduce_stock_and_converts_to_sale(): void
    {
        app(AppSettings::class)->set(['whatsapp_number' => '+27764544272']);
        $seller = $this->makeUser(Role::SALES);
        $product = $this->makeTyre(10);

        $quote = $this->actingAs($seller)->postJson('/api/v1/quotations', [
            'customer_name' => 'S. Naidoo', 'customer_phone' => '082 123 4567',
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'services' => [['service_code' => 'fitting', 'quantity' => 4, 'unit_price' => '50']],
        ])->assertCreated()->json('data');

        $this->assertSame('5600.00', $quote['total']);
        $this->assertStringStartsWith('https://wa.me/27821234567?text=', $quote['whatsapp_url']);
        $this->assertSame(10, $product->fresh()->qty_on_hand);

        $this->postJson("/api/v1/quotations/{$quote['id']}/sale", ['payment_method' => 'cash'])->assertCreated();
        $this->assertSame(6, $product->fresh()->qty_on_hand);
        $this->getJson("/api/v1/quotations/{$quote['id']}")->assertJsonPath('data.status', 'converted');
        $this->postJson("/api/v1/quotations/{$quote['id']}/sale", ['payment_method' => 'cash'])->assertStatus(409);
    }

    public function test_public_api_is_disabled_by_default_and_exposes_only_approved_fields(): void
    {
        $this->makeTyre(10);
        $this->getJson('/api/public/tyres/availability?width=205&profile=55&rim=16')->assertNotFound();

        app(AppSettings::class)->set(['public_api_enabled' => true]);
        $row = $this->getJson('/api/public/tyres/availability?width=205&profile=55&rim=16')->assertOk()->json('data.0');

        $this->assertSame(['brand', 'model', 'size', 'width', 'profile', 'rim', 'load_index', 'speed_rating', 'condition', 'availability'], array_keys($row));
        $this->assertSame('in_stock', $row['availability']);

        app(AppSettings::class)->set(['public_api_visibility' => 'quantity', 'public_api_show_prices' => true]);
        $row = $this->getJson('/api/public/tyres/availability?width=205&rim=16&quantity=4')->json('data.0');
        $this->assertSame(10, $row['available_quantity']);
        $this->assertSame('1350.00', $row['price']);
        $this->assertArrayNotHasKey('cost_price', $row);
    }

    public function test_dashboard_figures_come_from_the_database(): void
    {
        $owner = $this->makeUser();
        $product = $this->makeTyre(10, [], $owner);
        $this->actingAs($owner)->postJson('/api/v1/sales', ['items' => [['product_id' => $product->id, 'quantity' => 4]], 'payment_method' => 'cash'])->assertCreated();

        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertSame(6, $data['stats']['total_physical']);
        $this->assertSame(4, $data['stats']['sold_today']);
        $this->assertSame('5400.00', $data['financial']['revenue_today']);
        $this->assertSame('1800.00', $data['financial']['gross_profit_month']);
        $this->assertSame('5400.00', $data['financial']['inventory_cost_value']);

        $sales = $this->actingAs($this->makeUser(Role::SALES))->getJson('/api/v1/dashboard')->json('data');
        $this->assertNull($sales['financial']);
    }
}
