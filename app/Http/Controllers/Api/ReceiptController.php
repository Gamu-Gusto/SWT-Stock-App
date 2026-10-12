<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Services\Inventory\ReceivingService;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReceiptController extends Controller
{
    public function __construct(protected ReceivingService $receiving) {}

    public function index(Request $request): JsonResponse
    {
        $q = StockReceipt::query()->with(['supplier:id,company_name', 'creator:id,name', 'poster:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('delivery_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('delivery_date', '<=', $request->date('date_to')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->whereLike('receipt_no', '%'.$request->string('q').'%')
                ->orWhereLike('supplier_reference', '%'.$request->string('q').'%')))
            ->orderByDesc('id');
        $page = $q->paginate(min($request->integer('per_page', 25) ?: 25, 100));
        $showCost = $this->showCost($request);
        $page->getCollection()->transform(fn ($r) => $this->present($r, $showCost, false));

        return response()->json($page);
    }

    public function show(Request $request, StockReceipt $receipt): JsonResponse
    {
        $receipt->load(['supplier', 'items.product.brand', 'items.product.tyreModel', 'items.product.spec', 'items.location', 'attachments', 'creator', 'poster', 'reverser']);

        return response()->json(['data' => $this->present($receipt, $this->showCost($request), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $receipt = $this->receiving->saveDraft($this->validated($request), $request->user());
        if ($request->boolean('post')) {
            $this->authorize('receiving.post');
            $receipt = $this->receiving->post($receipt, $request->user(), $request->boolean('allow_duplicate_reference'));
        }

        return $this->show($request, $receipt)->setStatusCode(201);
    }

    public function update(Request $request, StockReceipt $receipt): JsonResponse
    {
        $this->receiving->saveDraft($this->validated($request), $request->user(), $receipt);

        return $this->show($request, $receipt->refresh());
    }

    public function post(Request $request, StockReceipt $receipt): JsonResponse
    {
        $this->receiving->post($receipt, $request->user(), $request->boolean('allow_duplicate_reference'));

        return $this->show($request, $receipt->refresh());
    }

    public function reverse(Request $request, StockReceipt $receipt): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $this->receiving->reverse($receipt, $data['reason'], $request->user());

        return $this->show($request, $receipt->refresh());
    }

    public function destroy(StockReceipt $receipt): JsonResponse
    {
        $this->receiving->deleteDraft($receipt);

        return response()->json(['message' => 'Draft deleted.']);
    }

    public function attach(Request $request, StockReceipt $receipt, MediaService $media): JsonResponse
    {
        $request->validate(['file' => MediaService::documentRules()]);

        return (new MediaResource($media->store($receipt, $request->file('file'), 'documents', $request->user())))->response()->setStatusCode(201);
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('status', 'active')],
            'supplier_reference' => ['nullable', 'string', 'max:80'],
            'delivery_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'items' => ['array', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('status', 'active')],
            'items.*.location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')->where('is_active', true)],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'items.*.unit_details' => ['nullable', 'array', 'max:100'],
            'items.*.unit_details.*.tread_depth_mm' => ['nullable', 'numeric', 'between:0,30'],
            'items.*.unit_details.*.grade' => ['nullable', 'string', 'max:20'],
            'items.*.unit_details.*.dot_code' => ['nullable', 'string', 'max:20'],
            'items.*.unit_details.*.defects' => ['nullable', 'string', 'max:500'],
            'items.*.unit_details.*.cost_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'items.*.unit_details.*.selling_price' => ['required_with:items.*.unit_details', 'numeric', 'min:0', 'decimal:0,2'],
            'items.*.unit_details.*.status' => ['nullable', Rule::in(['pending_inspection', 'available', 'rejected'])],
        ]);
        foreach ($data['items'] ?? [] as $i => $item) {
            $product = Product::query()->find($item['product_id']);
            if ($product && ! $product->isUnitTracked() && empty($item['quantity'])) {
                abort(response()->json(['message' => 'Enter the quantity received for every line.', 'errors' => ["items.{$i}.quantity" => ['Quantity is required.']]], 422));
            }
            if (! $request->user()->hasPermission('used.inspect')) {
                foreach ($item['unit_details'] ?? [] as $j => $d) {
                    $data['items'][$i]['unit_details'][$j]['status'] = 'pending_inspection';
                }
            }
        }

        return $data;
    }

    protected function showCost(Request $request): bool
    {
        // Receiving staff enter costs from the supplier invoice, so they can see receipt costs.
        return $request->user()->hasPermission('financials.view') || $request->user()->hasPermission('receiving.create');
    }

    protected function present(StockReceipt $r, bool $showCost, bool $detail): array
    {
        $data = [
            'id' => $r->id, 'receipt_no' => $r->receipt_no, 'status' => $r->status,
            'supplier' => $r->supplier ? ['id' => $r->supplier->id, 'name' => $r->supplier->company_name] : null,
            'supplier_id' => $r->supplier_id, 'supplier_reference' => $r->supplier_reference,
            'delivery_date' => $r->delivery_date?->toDateString(), 'notes' => $r->notes,
            'total_quantity' => $r->total_quantity, 'total_cost' => $showCost ? (string) $r->total_cost : null,
            'created_by' => $r->creator?->name, 'posted_by' => $r->poster?->name, 'posted_at' => $r->posted_at?->toIso8601String(),
            'reversed_at' => $r->reversed_at?->toIso8601String(), 'reversal_reason' => $r->reversal_reason,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
        if ($detail) {
            $data['reversed_by'] = $r->reverser?->name;
            $data['items'] = $r->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'product' => $i->product?->displayName(), 'sku' => $i->product?->sku,
                'condition' => $i->product?->condition, 'tracking' => $i->product?->tracking,
                'location_id' => $i->location_id, 'location' => $i->location?->name, 'quantity' => $i->quantity,
                'unit_cost' => $showCost ? (string) $i->unit_cost : null, 'line_total' => $showCost ? (string) $i->line_total : null,
                'unit_details' => $i->unit_details ? array_map(fn ($d) => $showCost ? $d : array_diff_key($d, ['cost_price' => 1]), $i->unit_details) : null,
                'current_stock' => $i->product?->qty_on_hand,
            ]);
            $data['attachments'] = MediaResource::collection($r->attachments);
        }

        return $data;
    }
}
