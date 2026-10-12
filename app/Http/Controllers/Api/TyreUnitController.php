<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Http\Resources\MovementResource;
use App\Http\Resources\TyreUnitResource;
use App\Models\Product;
use App\Models\TyreUnit;
use App\Services\Inventory\TyreUnitService;
use App\Services\MediaService;
use App\Support\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TyreUnitController extends Controller
{
    public function __construct(protected TyreUnitService $units, protected AppSettings $settings) {}

    protected function detailRules(bool $creating): array
    {
        return [
            'tread_depth_mm' => ['nullable', 'numeric', 'between:0,30', 'decimal:0,1'],
            'grade' => ['nullable', Rule::in($this->settings->get('used_tyre_grades', ['A', 'B', 'C']))],
            'dot_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9 ]+$/'],
            'defects' => ['nullable', 'string', 'max:1000'],
            'inspection_notes' => ['nullable', 'string', 'max:1000'],
            'selling_price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
        ];
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate($this->detailRules(true) + [
            'location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')],
            'status' => ['required', Rule::in(TyreUnitService::INSPECTION_RESULTS)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        if ($data['status'] !== TyreUnit::PENDING && ! $request->user()->hasPermission('used.inspect')) {
            $data['status'] = TyreUnit::PENDING;
        }
        if (! $request->user()->hasPermission('financials.view')) {
            unset($data['cost_price']);
        }
        $unit = $this->units->create($product, $data, $request->user());

        return (new TyreUnitResource($unit->load(['location', 'product.brand', 'product.spec'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, TyreUnit $unit): JsonResponse
    {
        $unit->load(['location', 'inspector', 'images', 'product.brand', 'product.tyreModel', 'product.spec', 'supplier']);
        $data = (new TyreUnitResource($unit))->toArray($request);
        $data['movements'] = MovementResource::collection($unit->product->movements()->where('tyre_unit_id', $unit->id)
            ->with(['location', 'user', 'approver'])->orderByDesc('id')->get());

        return response()->json(['data' => $data]);
    }

    public function lookup(Request $request): TyreUnitResource
    {
        $request->validate(['code' => ['required', 'string', 'max:30']]);

        return new TyreUnitResource(TyreUnit::query()->where('unit_code', trim($request->string('code')))
            ->with(['location', 'product.brand', 'product.spec', 'product.tyreModel'])->firstOrFail());
    }

    public function update(Request $request, TyreUnit $unit): TyreUnitResource
    {
        $data = $request->validate($this->detailRules(false));
        if (! $request->user()->hasPermission('financials.view')) {
            unset($data['cost_price']);
        }
        if (! $request->user()->hasPermission('used.inspect')) {
            unset($data['grade']);
        }

        return new TyreUnitResource($this->units->update($unit, $data, $request->user())->load('location'));
    }

    public function inspect(Request $request, TyreUnit $unit): TyreUnitResource
    {
        $data = $request->validate([
            'result' => ['required', Rule::in(TyreUnitService::INSPECTION_RESULTS)],
            'grade' => ['nullable', Rule::in($this->settings->get('used_tyre_grades', ['A', 'B', 'C']))],
            'tread_depth_mm' => ['nullable', 'numeric', 'between:0,30'],
            'inspection_notes' => ['nullable', 'string', 'max:1000'],
            'defects' => ['nullable', 'string', 'max:1000'],
        ]);

        return new TyreUnitResource($this->units->inspect($unit, $data['result'], $data, $request->user())->load('location'));
    }

    public function uploadImage(Request $request, TyreUnit $unit, MediaService $media): JsonResponse
    {
        $request->validate(['image' => MediaService::imageRules()]);

        return (new MediaResource($media->store($unit, $request->file('image'), 'images', $request->user())))->response()->setStatusCode(201);
    }
}
