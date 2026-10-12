<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\SaleReturnItem;
use App\Services\Inventory\ReturnService;
use App\Services\Inventory\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function __construct(protected SalesService $sales) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $q = Sale::query()->with('user:id,name')->withCount('items')
            // Without sales.view_all an employee only sees their own transactions.
            ->when(! $user->hasPermission('sales.view_all'), fn ($q) => $q->where('user_id', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('user_id') && $user->hasPermission('sales.view_all'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->input('payment_method')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('sale_no', 'like', '%'.$request->string('q').'%')
                ->orWhere('customer_name', 'like', '%'.$request->string('q').'%')->orWhere('customer_phone', 'like', '%'.$request->string('q').'%')))
            ->orderByDesc('id');

        return SaleResource::collection($q->paginate(min($request->integer('per_page', 25) ?: 25, 100)));
    }

    public function show(Request $request, Sale $sale): SaleResource
    {
        $this->authorize('view', $sale);

        return new SaleResource($sale->load(['items.location', 'user', 'voider', 'returns.items']));
    }

    public function store(SaleRequest $request): JsonResponse
    {
        $sale = $this->sales->create($request->validated(), $request->user());

        return (new SaleResource($sale->load(['items.location', 'user'])))->response()->setStatusCode(201);
    }

    public function update(SaleRequest $request, Sale $sale): SaleResource
    {
        $this->authorize('update', $sale);

        return new SaleResource($this->sales->updateDraft($sale, $request->validated(), $request->user())->load(['items.location', 'user']));
    }

    public function complete(Request $request, Sale $sale): SaleResource
    {
        $this->authorize('update', $sale);
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(Sale::PAYMENT_METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:80'],
            'amount_paid' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        return new SaleResource($this->sales->complete($sale, $data, $request->user())->load(['items.location', 'user']));
    }

    public function destroy(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('update', $sale);
        $this->sales->deleteDraft($sale);

        return response()->json(['message' => 'Draft sale deleted.']);
    }

    public function void(Request $request, Sale $sale): SaleResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        return new SaleResource($this->sales->void($sale, $data['reason'], $request->user())->load(['items.location', 'user', 'voider']));
    }

    public function storeReturn(Request $request, Sale $sale, ReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);
        $returns->create($sale, $data['items'], $data['reason'], $request->user(), $data['idempotency_key'] ?? null);

        return (new SaleResource($sale->fresh()->load(['items.location', 'user', 'returns.items'])))->response()->setStatusCode(201);
    }

    /** Returned items awaiting inspection. */
    public function pendingReturns(): JsonResponse
    {
        $items = SaleReturnItem::query()->where('outcome', 'pending')
            ->with(['saleReturn.sale:id,sale_no,customer_name', 'product.brand', 'product.spec', 'product.tyreModel', 'unit'])
            ->orderBy('id')->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'return_no' => $i->saleReturn->return_no, 'sale_no' => $i->saleReturn->sale?->sale_no,
                'sale_id' => $i->saleReturn->sale_id, 'customer_name' => $i->saleReturn->sale?->customer_name,
                'reason' => $i->saleReturn->reason, 'product' => $i->product->displayName(), 'condition' => $i->product->condition,
                'unit_code' => $i->unit?->unit_code, 'quantity' => $i->quantity, 'returned_at' => $i->saleReturn->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $items]);
    }

    public function inspectReturn(Request $request, SaleReturnItem $item, ReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['restocked', 'damaged', 'written_off'])],
            'restock_location_id' => ['nullable', 'required_if:outcome,restocked', 'integer', Rule::exists('inventory_locations', 'id')->where('is_sellable', true)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $item = $returns->inspect($item, $data['outcome'], $data['restock_location_id'] ?? null, $data['notes'] ?? null, $request->user());

        return response()->json(['data' => $item, 'message' => 'Inspection recorded.']);
    }
}
