<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use App\Models\Brand;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\TyreModel;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Audit;
use App\Support\TyreSize;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    public function __construct(protected StockLedger $ledger, protected AppSettings $settings) {}

    /**
     * Create a tyre product (catalogue record). New tyres are quantity tracked; used tyres are tracked
     * per individual unit. Optional opening stock is posted through the ledger as an Opening Balance.
     */
    public function create(array $data, User $user): Product
    {
        return DB::transaction(function () use ($data, $user) {
            $attributes = $this->buildAttributes($data);
            $this->assertUniqueVariant($attributes['variant_key']);
            $spec = $attributes['_spec'];
            unset($attributes['_spec']);

            $product = new Product($attributes + [
                'sku' => $data['sku'] ?? null ?: 'TMP-'.Str::uuid(),
                'status' => Product::STATUS_ACTIVE,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $product->save();

            if (empty($data['sku'])) {
                $product->sku = $this->generateSku($product, $spec);
                $product->save();
            }
            $product->spec()->create($spec);
            $product->load('brand', 'tyreModel', 'spec');

            $openingQty = (int) ($data['opening_qty'] ?? 0);
            if ($openingQty > 0) {
                if ($product->isUnitTracked()) {
                    throw new InventoryException('Used tyres are added individually as tyre units, not as an opening quantity.', 422, 'unit_tracked');
                }
                $location = InventoryLocation::query()->findOrFail($data['opening_location_id'] ?? $product->default_location_id);
                $this->ledger->lockProduct($product->id);
                $this->ledger->adjust($product, $location, $openingQty, StockMovement::OPENING_BALANCE, [
                    'reference' => $product,
                    'reference_no' => $product->sku,
                    'reason' => 'Opening balance on product registration',
                    'unit_cost' => $data['cost_price'] ?? $product->cost_price,
                    'lot_source' => 'opening',
                    'user_id' => $user->id,
                ]);
            }

            Audit::log('product.created', $product, "Created {$product->displayName()} ({$product->sku})", null, Arr::except($product->toArray(), ['brand', 'tyre_model', 'spec']));

            return $product->fresh(['brand', 'tyreModel', 'spec']);
        });
    }

    public function update(Product $product, array $data, User $user): Product
    {
        return DB::transaction(function () use ($product, $data, $user) {
            $product = $this->ledger->lockProduct($product->id);
            $product->load('spec');
            $hasHistory = $product->movements()->exists() || $product->units()->exists();

            $merged = array_merge([
                'brand_id' => $product->brand_id,
                'tyre_model_id' => $product->tyre_model_id,
                'name' => $product->name,
                'condition' => $product->condition,
                'width' => $product->spec?->width,
                'profile' => $product->spec?->profile,
                'rim' => $product->spec?->rim,
                'load_index' => $product->spec?->load_index,
                'speed_rating' => $product->spec?->speed_rating,
                'tyre_type' => $product->spec?->tyre_type,
                'run_flat' => $product->spec?->run_flat,
            ], $data);

            if ($hasHistory && $merged['condition'] !== $product->condition) {
                throw new InventoryException('The condition of a tyre with stock history cannot be changed. Create a separate new/used record instead.', 422, 'condition_locked');
            }

            $attributes = $this->buildAttributes($merged);
            if ($attributes['variant_key'] !== $product->variant_key) {
                $this->assertUniqueVariant($attributes['variant_key'], $product->id);
            }
            $spec = $attributes['_spec'];
            unset($attributes['_spec'], $attributes['tracking']);

            $original = $product->getOriginal();
            $originalSpec = $product->spec?->toArray() ?? [];
            if (! empty($data['sku'])) {
                $attributes['sku'] = $data['sku'];
            }
            $product->fill($attributes + ['updated_by' => $user->id])->save();
            $product->spec()->updateOrCreate(['product_id' => $product->id], $spec);

            Audit::changes('product.updated', $product, $original, "Updated {$product->sku}");
            $specChanges = array_diff_assoc(array_map('strval', $spec), array_map('strval', Arr::only($originalSpec, array_keys($spec))));
            if ($specChanges) {
                Audit::log('product.spec_updated', $product, "Updated specification of {$product->sku}", Arr::only($originalSpec, array_keys($specChanges)), $specChanges);
            }

            // Thresholds may have changed: re-evaluate stock alerts.
            $this->ledger->refreshProductTotals($product);

            return $product->fresh(['brand', 'tyreModel', 'spec']);
        });
    }

    public function archive(Product $product, User $user): Product
    {
        return DB::transaction(function () use ($product) {
            $product = $this->ledger->lockProduct($product->id);
            if ($product->qty_on_hand + $product->qty_unsellable > 0 || $product->qty_reserved > 0) {
                throw new InventoryException('Only tyres with no physical stock and no reservations can be archived. Write off or transfer remaining stock first.', 409, 'has_stock');
            }
            $product->forceFill(['status' => Product::STATUS_ARCHIVED])->save();
            Audit::log('product.archived', $product, "Archived {$product->sku}");

            return $product;
        });
    }

    public function restore(Product $product): Product
    {
        $product->forceFill(['status' => Product::STATUS_ACTIVE])->save();
        Audit::log('product.restored', $product, "Restored {$product->sku}");

        return $product;
    }

    /** @return array<string, mixed> */
    protected function buildAttributes(array $data): array
    {
        $brand = Brand::query()->findOrFail($data['brand_id']);
        $modelId = $data['tyre_model_id'] ?? null;
        if (! $modelId && ! empty($data['model_name'])) {
            $modelId = TyreModel::query()->firstOrCreate(['brand_id' => $brand->id, 'name' => trim($data['model_name'])])->id;
        }
        $model = $modelId ? TyreModel::query()->where('brand_id', $brand->id)->findOrFail($modelId) : null;

        $width = (int) $data['width'];
        $profile = isset($data['profile']) && $data['profile'] !== '' && $data['profile'] !== null ? (int) $data['profile'] : null;
        $rim = TyreSize::rimText($data['rim']);
        $condition = $data['condition'];

        $spec = [
            'width' => $width,
            'profile' => $profile,
            'rim' => $rim,
            'size_display' => TyreSize::format($width, $profile, $rim),
            'size_key' => TyreSize::key($width, $profile, $rim),
            'load_index' => isset($data['load_index']) ? strtoupper(trim((string) $data['load_index'])) ?: null : null,
            'speed_rating' => isset($data['speed_rating']) ? strtoupper(trim((string) $data['speed_rating'])) ?: null : null,
            'tyre_type' => $data['tyre_type'] ?? null,
            'run_flat' => (bool) ($data['run_flat'] ?? false),
        ];

        $variantKey = implode('|', [
            'tyre', $brand->id, $model?->id ?? Str::lower(trim($data['name'] ?? '')), $spec['size_key'],
            $spec['load_index'] ?? '-', $spec['speed_rating'] ?? '-', $spec['run_flat'] ? 'rf' : 'std', $condition,
        ]);

        $attributes = Arr::only($data, [
            'description', 'cost_price', 'selling_price', 'promo_price', 'min_selling_price', 'fitting_fee',
            'balancing_fee', 'alignment_fee', 'min_stock_level', 'reorder_qty', 'default_location_id',
            'default_supplier_id', 'is_public',
        ]);

        return $attributes + [
            'category_id' => ProductCategory::query()->where('slug', ProductCategory::TYRES)->value('id')
                ?? ProductCategory::query()->create(['slug' => ProductCategory::TYRES, 'name' => 'Tyres', 'has_tyre_specs' => true])->id,
            'brand_id' => $brand->id,
            'tyre_model_id' => $model?->id,
            'name' => $model?->name ?? trim($data['name'] ?? '') ?: $brand->name,
            'condition' => $condition,
            'tracking' => $condition === 'used' ? 'unit' : 'quantity',
            'variant_key' => $variantKey,
            '_spec' => $spec,
        ];
    }

    protected function assertUniqueVariant(string $variantKey, ?int $exceptId = null): void
    {
        $existing = Product::query()->where('variant_key', $variantKey)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->first();
        if ($existing) {
            throw new InventoryException(
                "This tyre already exists as {$existing->sku}. Receive stock against the existing record instead of creating a duplicate.",
                409,
                'duplicate_product',
                ['product_id' => $existing->id, 'sku' => $existing->sku],
            );
        }
    }

    protected function generateSku(Product $product, array $spec): string
    {
        $brand = Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', $product->brand?->name ?? 'GEN'), 0, 3));
        $size = $spec['width'].($spec['profile'] ?? '').str_replace('.', '', $spec['rim']);

        return sprintf('%s-%s-%s-%s', $brand, $size, $product->condition === 'used' ? 'U' : 'N', str_pad((string) $product->id, 5, '0', STR_PAD_LEFT));
    }
}
