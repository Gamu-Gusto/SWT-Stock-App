<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\InventoryLocation;
use App\Models\StockBalance;
use App\Models\TyreModel;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Brands, tyre models and storage locations.
 */
class CatalogController extends Controller
{
    public function brands(): JsonResponse
    {
        return response()->json(['data' => Brand::query()->withCount(['products' => fn ($q) => $q->where('status', 'active')])
            ->with(['models' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()]);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('brands', 'name')],
            'is_preferred' => ['sometimes', 'boolean'],
        ]);
        $brand = Brand::query()->create($data + ['is_active' => true]);
        Audit::log('brand.created', $brand, "Created brand {$brand->name}");

        return response()->json(['data' => $brand], 201);
    }

    public function updateBrand(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('brands', 'name')->ignore($brand)],
            'is_preferred' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
        ]);
        $original = $brand->getOriginal();
        if (array_key_exists('archived', $data)) {
            $brand->archived_at = $data['archived'] ? now() : null;
            if ($data['archived']) {
                $brand->is_active = false;
            }
            unset($data['archived']);
        }
        $brand->fill($data)->save();
        Audit::changes('brand.updated', $brand, $original, "Updated brand {$brand->name}");

        return response()->json(['data' => $brand]);
    }

    public function storeModel(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('tyre_models', 'name')->where('brand_id', $brand->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $model = $brand->models()->create($data + ['is_active' => true]);
        Audit::log('tyre_model.created', $model, "Created model {$brand->name} {$model->name}");

        return response()->json(['data' => $model], 201);
    }

    public function updateModel(Request $request, TyreModel $model): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('tyre_models', 'name')->where('brand_id', $model->brand_id)->ignore($model)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $original = $model->getOriginal();
        $model->fill($data)->save();
        Audit::changes('tyre_model.updated', $model, $original, "Updated model {$model->name}");

        return response()->json(['data' => $model]);
    }

    public function locations(): JsonResponse
    {
        $totals = StockBalance::query()->selectRaw('location_id, SUM(qty_on_hand) as qty, COUNT(CASE WHEN qty_on_hand > 0 THEN 1 END) as variants')
            ->groupBy('location_id')->get()->keyBy('location_id');

        return response()->json(['data' => InventoryLocation::query()->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($l) => $l->toArray() + ['qty' => (int) ($totals[$l->id]->qty ?? 0), 'variants' => (int) ($totals[$l->id]->variants ?? 0)])]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $data = $this->validateLocation($request);
        $location = InventoryLocation::query()->create($data);
        Audit::log('location.created', $location, "Created location {$location->name}");

        return response()->json(['data' => $location], 201);
    }

    public function updateLocation(Request $request, InventoryLocation $location): JsonResponse
    {
        $data = $this->validateLocation($request, $location);
        $hasStock = StockBalance::query()->where('location_id', $location->id)->where('qty_on_hand', '>', 0)->exists();
        if ($hasStock && ((isset($data['is_sellable']) && $data['is_sellable'] !== $location->is_sellable) || (isset($data['is_active']) && ! $data['is_active']))) {
            return response()->json(['message' => 'This location holds stock. Transfer the stock out before changing whether it is sellable or deactivating it.', 'code' => 'location_has_stock'], 409);
        }
        $original = $location->getOriginal();
        $location->fill($data)->save();
        Audit::changes('location.updated', $location, $original, "Updated location {$location->name}");

        return response()->json(['data' => $location]);
    }

    protected function validateLocation(Request $request, ?InventoryLocation $location = null): array
    {
        $req = $location ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_]+$/', Rule::unique('inventory_locations', 'code')->ignore($location)],
            'name' => [$req, 'string', 'max:100'],
            'type' => [$req, Rule::in(['store', 'rack', 'workshop', 'used', 'quarantine', 'damaged'])],
            'is_sellable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
