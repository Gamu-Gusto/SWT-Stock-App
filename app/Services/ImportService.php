<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\Brand;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockImport;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TyreModel;
use App\Models\TyreUnit;
use App\Models\User;
use App\Services\Inventory\ProductService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\TyreUnitService;
use App\Support\Audit;
use App\Support\DocumentNumber;
use App\Support\Money;
use App\Support\TyreSize;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Opening-stock import from CSV/XLSX.
 *
 * Workflow: upload → detect headings → map columns → preview (validation, duplicates) → commit.
 * Existing quantities are never replaced: duplicates are either skipped or ADDED as opening-balance
 * movements, so every imported tyre has a formal stock movement.
 */
class ImportService
{
    public const MAX_ROWS = 5000;

    /** field => [label, required, header synonyms] */
    public const FIELDS = [
        'brand' => ['Brand', true, ['brand', 'make', 'manufacturer']],
        'model' => ['Model', false, ['model', 'pattern', 'tread pattern', 'description']],
        'width' => ['Width', false, ['width', 'w', 'section width']],
        'profile' => ['Profile', false, ['profile', 'aspect', 'aspect ratio', 'height', 'series']],
        'rim' => ['Rim', false, ['rim', 'rim size', 'diameter', 'r', 'inch']],
        'size' => ['Size (e.g. 205/55 R16)', false, ['size', 'tyre size', 'tire size', 'full size']],
        'condition' => ['Condition', true, ['condition', 'new/used', 'type', 'state']],
        'quantity' => ['Quantity', true, ['quantity', 'qty', 'stock', 'on hand', 'units', 'count']],
        'cost_price' => ['Cost price', false, ['cost', 'cost price', 'purchase price', 'buy price']],
        'selling_price' => ['Selling price', true, ['selling price', 'price', 'sell price', 'retail', 'retail price']],
        'supplier' => ['Supplier', false, ['supplier', 'vendor', 'distributor']],
        'location' => ['Location', false, ['location', 'rack', 'bin', 'storage', 'shelf']],
        'load_index' => ['Load index', false, ['load index', 'load', 'li']],
        'speed_rating' => ['Speed rating', false, ['speed rating', 'speed', 'sr']],
        'min_stock_level' => ['Minimum stock', false, ['minimum', 'min stock', 'min', 'reorder level']],
    ];

    public function __construct(
        protected ProductService $products,
        protected StockLedger $ledger,
        protected TyreUnitService $units,
    ) {}

    public function upload(UploadedFile $file, User $user): StockImport
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $name = Str::uuid().'.'.$ext;
        $path = $file->storeAs('imports', $name, 'local');
        $rows = $this->readRows(Storage::disk('local')->path($path), 1);
        $headers = array_map(fn ($h) => trim((string) $h), $rows[0] ?? []);
        if (! array_filter($headers)) {
            Storage::disk('local')->delete($path);
            throw new InventoryException('The file has no heading row. Use the import template.', 422, 'no_headers');
        }

        $import = StockImport::query()->create([
            'import_no' => DocumentNumber::next('IMP'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'path' => $path,
            'status' => 'uploaded',
            'headers' => $headers,
            'mapping' => $this->guessMapping($headers),
            'user_id' => $user->id,
        ]);
        Audit::log('import.uploaded', $import, "Uploaded import file {$import->original_name}");

        return $import;
    }

    /** @return array<string,int|null> field => column index */
    public function guessMapping(array $headers): array
    {
        $mapping = [];
        $normalised = array_map(fn ($h) => strtolower(trim(preg_replace('/[^a-z0-9\/ ]/i', ' ', (string) $h))), $headers);
        foreach (self::FIELDS as $field => [, , $synonyms]) {
            $mapping[$field] = null;
            foreach ($normalised as $i => $h) {
                if (in_array(preg_replace('/\s+/', ' ', $h), $synonyms, true)) {
                    $mapping[$field] = $i;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Validate every row without writing anything.
     *
     * @return array{rows:list<array>, summary:array}
     */
    public function preview(StockImport $import, array $mapping, string $duplicateMode, array $options = []): array
    {
        $import->forceFill(['mapping' => $mapping, 'duplicate_mode' => $duplicateMode])->save();
        $data = $this->readRows(Storage::disk('local')->path($import->path));
        array_shift($data);

        $brands = Brand::query()->get()->keyBy(fn ($b) => Str::lower($b->name));
        $locations = InventoryLocation::query()->get();
        $defaultLocationId = $options['default_location_id'] ?? null;
        $seen = [];
        $rows = [];
        $line = 1;
        foreach ($data as $raw) {
            $line++;
            if (! array_filter($raw, fn ($v) => trim((string) $v) !== '')) {
                continue;
            }
            $get = fn (string $field) => isset($mapping[$field]) && $mapping[$field] !== null && $mapping[$field] !== '' ? trim((string) ($raw[(int) $mapping[$field]] ?? '')) : '';
            $errors = [];
            $warnings = [];

            $brandName = $get('brand');
            if ($brandName === '') {
                $errors[] = 'Brand is required.';
            } elseif (! isset($brands[Str::lower($brandName)])) {
                $warnings[] = "New brand \"{$brandName}\" will be created.";
            }

            $width = $get('width');
            $profile = $get('profile');
            $rim = $get('rim');
            if (($width === '' || $rim === '') && $get('size') !== '') {
                $parsed = TyreSize::parse($get('size'));
                if ($parsed) {
                    [$width, $profile, $rim] = [(string) $parsed['width'], (string) ($parsed['profile'] ?? ''), $parsed['rim']];
                }
            }
            $rim = preg_replace('/^R/i', '', $rim);
            if (! ctype_digit($width) || ! TyreSize::validWidth((int) $width)) {
                $errors[] = 'Width is missing or invalid.';
            }
            if ($profile !== '' && (! ctype_digit($profile) || ! TyreSize::validProfile((int) $profile))) {
                $errors[] = 'Profile is invalid.';
            }
            if (! is_numeric($rim) || ! TyreSize::validRim($rim)) {
                $errors[] = 'Rim size is missing or invalid.';
            }

            $condition = Str::lower($get('condition'));
            $condition = match (true) {
                in_array($condition, ['new', 'n', 'nuwe'], true) => 'new',
                in_array($condition, ['used', 'u', 'second hand', 'secondhand', '2nd hand'], true) => 'used',
                default => null,
            };
            if (! $condition) {
                $errors[] = 'Condition must be New or Used.';
            }

            $qtyRaw = $get('quantity');
            if ($qtyRaw === '' || ! preg_match('/^\d+$/', $qtyRaw)) {
                $errors[] = 'Quantity must be a whole number (0 or more).';
            }
            $qty = (int) $qtyRaw;
            if ($qty > 10000) {
                $errors[] = 'Quantity looks too large (over 10 000).';
            }

            $prices = [];
            foreach (['cost_price', 'selling_price'] as $priceField) {
                $value = str_replace([' ', 'R', ','], ['', '', '.'], $get($priceField));
                if ($value === '') {
                    if ($priceField === 'selling_price') {
                        $errors[] = 'Selling price is required.';
                    }
                    $prices[$priceField] = '0.00';

                    continue;
                }
                if (! preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
                    $errors[] = self::FIELDS[$priceField][0].' is not a valid amount.';
                    $prices[$priceField] = '0.00';
                } else {
                    $prices[$priceField] = Money::normalize($value);
                }
            }

            $locationName = $get('location');
            $location = $locationName !== ''
                ? $locations->first(fn ($l) => Str::lower($l->name) === Str::lower($locationName) || Str::lower($l->code) === Str::lower($locationName))
                : ($defaultLocationId ? $locations->firstWhere('id', (int) $defaultLocationId) : null);
            if (! $location && $qty > 0) {
                $errors[] = $locationName !== '' ? "Location \"{$locationName}\" does not exist. Create it first or map a different column." : 'A storage location is required.';
            }

            $status = $errors ? 'invalid' : 'valid';
            $existing = null;
            $key = null;
            if (! $errors) {
                $brand = $brands[Str::lower($brandName)] ?? null;
                $sizeKey = TyreSize::key((int) $width, $profile !== '' ? (int) $profile : null, $rim);
                $key = Str::lower(implode('|', [$brandName, $get('model'), $sizeKey, $get('load_index'), $get('speed_rating'), $condition]));
                if ($brand) {
                    $existing = $this->findExisting($brand, $get('model'), $sizeKey, $get('load_index'), $get('speed_rating'), $condition);
                }
                if ($existing) {
                    $status = 'duplicate';
                    $warnings[] = "Matches existing tyre {$existing->sku}: ".($duplicateMode === 'add' ? "{$qty} will be ADDED to stock." : 'row will be skipped.');
                } elseif (isset($seen[$key])) {
                    $status = 'duplicate';
                    $warnings[] = "Same tyre as row {$seen[$key]}: ".($duplicateMode === 'add' ? 'quantities will be added together.' : 'row will be skipped.');
                }
                $seen[$key] ??= $line;
            }

            $rows[] = [
                'line' => $line,
                'status' => $status,
                'errors' => $errors,
                'warnings' => $warnings,
                'existing_product_id' => $existing?->id,
                'key' => $key,
                'data' => [
                    'brand' => $brandName, 'model' => $get('model'), 'width' => $width, 'profile' => $profile, 'rim' => $rim,
                    'size' => ctype_digit($width) && is_numeric($rim) ? TyreSize::format((int) $width, $profile !== '' && ctype_digit($profile) ? (int) $profile : null, $rim) : $get('size'),
                    'condition' => $condition, 'quantity' => $qty, 'cost_price' => $prices['cost_price'], 'selling_price' => $prices['selling_price'],
                    'supplier' => $get('supplier'), 'location_id' => $location?->id, 'location' => $location?->name ?? $locationName,
                    'load_index' => $get('load_index'), 'speed_rating' => $get('speed_rating'),
                    'min_stock_level' => ctype_digit($get('min_stock_level')) ? (int) $get('min_stock_level') : null,
                ],
            ];
            if (count($rows) > self::MAX_ROWS) {
                throw new InventoryException('Import files are limited to '.self::MAX_ROWS.' rows. Split the file and import in parts.', 422, 'too_many_rows');
            }
        }

        $willImport = fn ($r) => $r['status'] === 'valid' || ($r['status'] === 'duplicate' && $duplicateMode === 'add');

        return ['rows' => $rows, 'summary' => [
            'total_rows' => count($rows),
            'valid' => count(array_filter($rows, fn ($r) => $r['status'] === 'valid')),
            'duplicates' => count(array_filter($rows, fn ($r) => $r['status'] === 'duplicate')),
            'invalid' => count(array_filter($rows, fn ($r) => $r['status'] === 'invalid')),
            'quantity_to_import' => array_sum(array_map(fn ($r) => $willImport($r) ? $r['data']['quantity'] : 0, $rows)),
        ]];
    }

    /** Commit the import in one transaction. Can only be committed once. */
    public function commit(StockImport $import, array $options, User $user): StockImport
    {
        return DB::transaction(function () use ($import, $options, $user) {
            $import = StockImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($import->status !== 'uploaded') {
                throw InventoryException::invalidState("Import {$import->import_no} has already been {$import->status}.");
            }
            $preview = $this->preview($import, $import->mapping ?? [], $import->duplicate_mode, $options);
            $mode = $import->duplicate_mode;
            $usedStatus = ($options['used_status'] ?? 'pending_inspection') === 'available' ? TyreUnit::AVAILABLE : TyreUnit::PENDING;
            $created = $added = $skipped = $invalid = $duplicates = $qtyImported = 0;
            $createdByKey = [];

            foreach ($preview['rows'] as $row) {
                if ($row['status'] === 'invalid') {
                    $invalid++;

                    continue;
                }
                if ($row['status'] === 'duplicate') {
                    $duplicates++;
                    if ($mode !== 'add') {
                        $skipped++;

                        continue;
                    }
                }
                $d = $row['data'];
                $product = $row['existing_product_id'] ? Product::query()->find($row['existing_product_id']) : ($createdByKey[$row['key']] ?? null);
                if (! $product) {
                    $brand = Brand::query()->firstOrCreate(['name' => $d['brand']], ['is_active' => true]);
                    $supplierId = $d['supplier'] !== '' ? Supplier::query()->firstOrCreate(['company_name' => $d['supplier']], ['status' => 'active'])->id : null;
                    $product = $this->products->create([
                        'brand_id' => $brand->id,
                        'model_name' => $d['model'] !== '' ? $d['model'] : null,
                        'name' => $d['model'] !== '' ? $d['model'] : $brand->name,
                        'width' => (int) $d['width'],
                        'profile' => $d['profile'] !== '' ? (int) $d['profile'] : null,
                        'rim' => $d['rim'],
                        'condition' => $d['condition'],
                        'load_index' => $d['load_index'] ?: null,
                        'speed_rating' => $d['speed_rating'] ?: null,
                        'cost_price' => $d['cost_price'],
                        'selling_price' => $d['selling_price'],
                        'min_stock_level' => $d['min_stock_level'] ?? 0,
                        'default_location_id' => $d['location_id'],
                        'default_supplier_id' => $supplierId,
                    ], $user);
                    $createdByKey[$row['key']] = $product;
                    $created++;
                } else {
                    $added++;
                }

                if ($d['quantity'] > 0) {
                    $product = $this->ledger->lockProduct($product->id);
                    $location = InventoryLocation::query()->findOrFail($d['location_id']);
                    $ctx = ['reference' => $import, 'reference_no' => $import->import_no, 'reason' => "Opening stock import (row {$row['line']})", 'user_id' => $user->id];
                    if ($product->isUnitTracked()) {
                        for ($i = 0; $i < $d['quantity']; $i++) {
                            $this->units->createInTransaction($product, $location, [
                                'cost_price' => $d['cost_price'],
                                'selling_price' => $d['selling_price'],
                                'status' => $usedStatus,
                                'inspection_notes' => 'Imported from spreadsheet – verify tread depth, DOT and condition.',
                            ], $user, StockMovement::OPENING_BALANCE, $ctx);
                        }
                    } else {
                        $this->ledger->adjust($product, $location, $d['quantity'], StockMovement::OPENING_BALANCE, $ctx + ['unit_cost' => $d['cost_price'], 'lot_source' => 'import']);
                    }
                    $qtyImported += $d['quantity'];
                }
            }

            $summary = [
                'products_created' => $created,
                'quantities_added_to_existing' => $added,
                'imported' => $created + $added,
                'skipped' => $skipped,
                'invalid' => $invalid,
                'duplicates' => $duplicates,
                'total_quantity_imported' => $qtyImported,
            ];
            $import->forceFill(['status' => 'completed', 'summary' => $summary, 'completed_at' => now()])->save();
            Audit::log('import.completed', $import, "Import {$import->import_no} completed: {$qtyImported} tyres", null, $summary);

            return $import;
        });
    }

    public function templatePath(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Stock import');
        $headers = ['Brand', 'Model', 'Width', 'Profile', 'Rim', 'Condition', 'Quantity', 'Cost Price', 'Selling Price', 'Supplier', 'Location', 'Load Index', 'Speed Rating', 'Min Stock'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray([
            ['SAMPLE – Falken', 'Ziex ZE310', 205, 55, 16, 'New', 4, '950.00', '1350.00', 'SAMPLE Supplier', 'Main Store', '91', 'V', 4],
            ['SAMPLE – Michelin', 'Primacy 4', 225, 45, 17, 'Used', 2, '400.00', '750.00', '', 'Used Tyre Section', '94', 'W', 0],
        ], null, 'A2');
        $sheet->getStyle('A1:N1')->getFont()->setBold(true);
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $notes = $spreadsheet->createSheet();
        $notes->setTitle('Instructions');
        $notes->fromArray([
            ['Delete the SAMPLE rows before importing.'],
            ['Condition: New or Used. Used tyres are created as individual tyre units (quantity = number of tyres).'],
            ['Prices: numbers only, e.g. 1350.00 (no R symbol or thousand separators).'],
            ['Location must match an existing storage location name or code.'],
            ['Existing quantities are never replaced: matching tyres are skipped or added, as chosen during import.'],
        ], null, 'A1');
        $path = storage_path('app/private/import-template.xlsx');
        @mkdir(dirname($path), 0775, true);
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /** @return list<list<mixed>> */
    protected function readRows(string $path, ?int $limit = null): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getActiveSheet();
        } catch (\Throwable $e) {
            throw new InventoryException('The file could not be read. Upload a valid CSV or XLSX file.', 422, 'unreadable_file');
        }
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $cell->getFormattedValue();
            }
            $rows[] = $cells;
            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        return $rows;
    }

    protected function findExisting(Brand $brand, string $model, string $sizeKey, string $load, string $speed, string $condition): ?Product
    {
        $modelId = $model !== '' ? TyreModel::query()->where('brand_id', $brand->id)->where('name', $model)->value('id') : null;

        return Product::query()->where('brand_id', $brand->id)->where('condition', $condition)
            ->when($model !== '', fn ($q) => $modelId ? $q->where('tyre_model_id', $modelId) : $q->whereRaw('1 = 0'))
            ->whereHas('spec', fn ($q) => $q->where('size_key', $sizeKey)
                ->when($load !== '', fn ($q) => $q->where('load_index', strtoupper($load)))
                ->when($speed !== '', fn ($q) => $q->where('speed_rating', strtoupper($speed))))
            ->first();
    }
}
