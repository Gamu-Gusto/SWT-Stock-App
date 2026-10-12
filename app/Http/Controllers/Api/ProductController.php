<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\MediaResource;
use App\Http\Resources\MovementResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\TyreUnitResource;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\TyreUnit;
use App\Services\Inventory\ProductService;
use App\Services\Inventory\StocktakeLockGuard;
use App\Services\Inventory\TyreSearchService;
use App\Services\MediaService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(protected ProductService $products) {}

    /** Inventory list & tyre search (server-side, paginated). */
    public function index(Request $request, TyreSearchService $search): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'width' => ['nullable', 'integer'],
            'profile' => ['nullable', 'integer'],
            'rim' => ['nullable', 'numeric'],
            'brand_id' => ['nullable'],
            'tyre_model_id' => ['nullable', 'integer'],
            'condition' => ['nullable', Rule::in(['new', 'used'])],
            'tyre_type' => ['nullable', 'string', 'max:30'],
            'location_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'min_available' => ['nullable', 'integer', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
            'stock_status' => ['nullable', Rule::in(['in_stock', 'low_stock', 'out_of_stock'])],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'include_archived' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(TyreSearchService::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $results = $search->search($filters, (int) ($filters['per_page'] ?? 25));
        $parsed = $search->parseQuery($filters['q'] ?? null);

        return ProductResource::collection($results)->additional(['meta' => ['interpreted' => [
            'width' => $parsed['width'], 'profile' => $parsed['profile'], 'rim' => $parsed['rim'],
            'condition' => $parsed['condition'], 'terms' => $parsed['terms'],
        ]]]);
    }

    public function show(Request $request, Product $product, StocktakeLockGuard $guard): JsonResponse
    {
        $product->load(['brand', 'tyreModel', 'spec', 'balances.location', 'images', 'defaultLocation', 'defaultSupplier']);
        $data = (new ProductResource($product))->toArray($request);
        $data['images'] = MediaResource::collection($product->images);
        $data['default_location'] = $product->defaultLocation?->name;
        $data['default_supplier'] = $request->user()->hasPermission('suppliers.view') ? $product->defaultSupplier?->company_name : null;
        $data['active_reservations'] = Reservation::query()->where('status', 'active')
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->with(['items' => fn ($q) => $q->where('product_id', $product->id)])
            ->orderBy('expires_at')->get()
            ->map(fn ($r) => ['id' => $r->id, 'reservation_no' => $r->reservation_no, 'customer_name' => $r->customer_name,
                'quantity' => $r->items->sum(fn ($i) => $i->outstanding()), 'expires_at' => $r->expires_at->toIso8601String()]);
        $data['locked_by'] = collect($product->balances)->map(fn ($b) => $guard->blockingStocktake($product, $b->location))->filter()
            ->map(fn ($s) => ['id' => $s->id, 'stocktake_no' => $s->stocktake_no])->unique('id')->values();
        if ($request->user()->hasPermission('financials.view') && $product->isUnitTracked()) {
            // Used tyres: specific identification – each physical tyre carries its own cost.
            $data['valuation_method'] = 'unit';
            $data['cost_layers'] = [];
            $data['stock_value'] = Money::add('0', ...$product->units()->whereIn('status', TyreUnit::PHYSICAL)->pluck('cost_price')->map(fn ($c) => (string) $c)->all());
        } elseif ($request->user()->hasPermission('financials.view')) {
            $data['valuation_method'] = 'fifo';
            $lots = $product->lots()->where('qty_remaining', '>', 0)->orderBy('received_at')->get();
            $data['cost_layers'] = $lots->map(fn ($l) => ['id' => $l->id, 'received_at' => $l->received_at->toIso8601String(), 'source' => $l->source_type,
                'qty_remaining' => $l->qty_remaining, 'unit_cost' => (string) $l->unit_cost]);
            $data['stock_value'] = Money::add('0', ...$lots->map(fn ($l) => Money::mul($l->unit_cost, $l->qty_remaining))->all());
        }

        return response()->json(['data' => $data]);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated(), $request->user());

        return (new ProductResource($product->load(['brand', 'tyreModel', 'spec', 'balances.location'])))->response()->setStatusCode(201);
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $data = $request->validated();
        unset($data['opening_qty'], $data['opening_location_id']);

        return new ProductResource($this->products->update($product, $data, $request->user())->load('balances.location'));
    }

    public function archive(Request $request, Product $product): ProductResource
    {
        return new ProductResource($this->products->archive($product, $request->user())->load(['brand', 'tyreModel', 'spec']));
    }

    public function restore(Product $product): ProductResource
    {
        return new ProductResource($this->products->restore($product)->load(['brand', 'tyreModel', 'spec']));
    }

    public function movements(Request $request, Product $product): AnonymousResourceCollection
    {
        return MovementResource::collection(
            $product->movements()->with(['location', 'fromLocation', 'toLocation', 'user', 'approver', 'unit', 'product.brand', 'product.spec', 'product.tyreModel'])
                ->orderByDesc('id')->paginate(min((int) $request->input('per_page', 25), 100))
        );
    }

    public function units(Request $request, Product $product): AnonymousResourceCollection
    {
        $status = $request->input('status');

        return TyreUnitResource::collection(
            $product->units()->with(['location', 'inspector', 'images'])
                ->when($status === 'in_stock', fn ($q) => $q->whereIn('status', TyreUnit::PHYSICAL))
                ->when($status && $status !== 'in_stock' && $status !== 'all', fn ($q) => $q->where('status', $status))
                ->orderByRaw("CASE status WHEN 'available' THEN 0 WHEN 'reserved' THEN 1 WHEN 'pending_inspection' THEN 2 ELSE 3 END")
                ->orderBy('unit_code')->paginate(50)
        );
    }

    public function uploadImage(Request $request, Product $product, MediaService $media): JsonResponse
    {
        $request->validate(['image' => MediaService::imageRules()]);

        return (new MediaResource($media->store($product, $request->file('image'), 'images', $request->user())))->response()->setStatusCode(201);
    }
}
