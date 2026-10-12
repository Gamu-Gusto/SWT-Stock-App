<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MovementResource;
use App\Models\AdjustmentRequest;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\TransferService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Stock movement history, location transfers and adjustment requests.
 */
class StockOperationsController extends Controller
{
    public function movements(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['nullable', Rule::in(array_keys(StockMovement::TYPES))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);
        $q = StockMovement::query()
            ->with(['product.brand', 'product.tyreModel', 'product.spec', 'unit', 'location', 'fromLocation', 'toLocation', 'user', 'approver'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('reference'), fn ($q) => $q->where('reference_no', 'like', '%'.$request->string('reference').'%'))
            ->when($request->filled('direction'), fn ($q) => $q->where('quantity_change', $request->input('direction') === 'in' ? '>' : '<', 0))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->orderByDesc('id');

        return MovementResource::collection($q->paginate(min($request->integer('per_page', 50) ?: 50, 200)));
    }

    public function transfers(Request $request): JsonResponse
    {
        return response()->json(StockTransfer::query()->with(['fromLocation:id,name', 'toLocation:id,name', 'user:id,name', 'items.product.brand', 'items.product.spec', 'items.product.tyreModel', 'items.unit'])
            ->orderByDesc('id')->paginate(25)->through(fn ($t) => [
                'id' => $t->id, 'transfer_no' => $t->transfer_no, 'from' => $t->fromLocation?->name, 'to' => $t->toLocation?->name,
                'notes' => $t->notes, 'user' => $t->user?->name, 'created_at' => $t->created_at?->toIso8601String(),
                'items' => $t->items->map(fn ($i) => ['product' => $i->product?->displayName(), 'unit_code' => $i->unit?->unit_code, 'quantity' => $i->quantity]),
            ]));
    }

    public function storeTransfer(Request $request, TransferService $transfers): JsonResponse
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')],
            'to_location_id' => ['required', 'integer', 'different:from_location_id', Rule::exists('inventory_locations', 'id')],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required_without:items.*.tyre_unit_id', 'nullable', 'integer', Rule::exists('products', 'id')],
            'items.*.tyre_unit_id' => ['nullable', 'integer', Rule::exists('tyre_units', 'id')],
            'items.*.quantity' => ['required_without:items.*.tyre_unit_id', 'nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $transfer = $transfers->transfer($data['from_location_id'], $data['to_location_id'], $data['items'], $data['notes'] ?? null, $request->user(), $data['idempotency_key'] ?? null);

        return response()->json(['data' => ['id' => $transfer->id, 'transfer_no' => $transfer->transfer_no], 'message' => "Transfer {$transfer->transfer_no} completed."], 201);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $user = $request->user();
        $showCost = $user->hasPermission('financials.view');

        return response()->json(AdjustmentRequest::query()
            ->with(['product.brand', 'product.spec', 'product.tyreModel', 'unit', 'location:id,name', 'requester:id,name', 'decider:id,name', 'supplier:id,company_name'])
            ->when(! $user->hasPermission('adjustments.approve'), fn ($q) => $q->where('requested_by', $user->id))
            ->when($request->input('status', 'pending') !== 'all', fn ($q) => $q->where('status', $request->input('status', 'pending')))
            ->orderByDesc('id')->paginate(25)->through(fn ($a) => [
                'id' => $a->id, 'adjustment_no' => $a->adjustment_no, 'type' => $a->type, 'status' => $a->status,
                'product_id' => $a->product_id, 'product' => $a->product?->displayName(), 'condition' => $a->product?->condition,
                'unit_code' => $a->unit?->unit_code, 'location' => $a->location?->name, 'supplier' => $a->supplier?->company_name,
                'quantity_change' => $a->quantity_change, 'reason' => $a->reason,
                'estimated_cost' => $showCost ? Money::mul($a->unit?->cost_price ?? $a->product?->cost_price ?? 0, abs($a->quantity_change)) : null,
                'requested_by' => $a->requester?->name, 'requested_by_id' => $a->requested_by, 'decided_by' => $a->decider?->name,
                'decided_at' => $a->decided_at?->toIso8601String(), 'decision_notes' => $a->decision_notes, 'created_at' => $a->created_at?->toIso8601String(),
            ]));
    }

    public function storeAdjustment(Request $request, AdjustmentService $adjustments): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(AdjustmentRequest::TYPES)],
            'product_id' => ['required_without:tyre_unit_id', 'nullable', 'integer', Rule::exists('products', 'id')],
            'tyre_unit_id' => ['nullable', 'integer', Rule::exists('tyre_units', 'id')],
            'location_id' => ['required_without:tyre_unit_id', 'nullable', 'integer', Rule::exists('inventory_locations', 'id')],
            'supplier_id' => ['nullable', 'required_if:type,supplier_return', 'integer', Rule::exists('suppliers', 'id')],
            'quantity_change' => ['required_without:tyre_unit_id', 'nullable', 'integer', 'not_in:0', 'between:-10000,10000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);
        $adjustment = $adjustments->request($data, $request->user());

        return response()->json(['data' => $adjustment, 'message' => "Adjustment {$adjustment->adjustment_no} submitted for approval."], 201);
    }

    public function approveAdjustment(Request $request, AdjustmentRequest $adjustment, AdjustmentService $adjustments): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $adjustments->approve($adjustment, $request->user(), $data['notes'] ?? null);

        return response()->json(['message' => "Adjustment {$adjustment->adjustment_no} approved and posted."]);
    }

    public function rejectAdjustment(Request $request, AdjustmentRequest $adjustment, AdjustmentService $adjustments): JsonResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'min:3', 'max:500']]);
        $adjustments->reject($adjustment, $request->user(), $data['notes']);

        return response()->json(['message' => "Adjustment {$adjustment->adjustment_no} rejected."]);
    }
}
