<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Supplier::query()
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('company_name', 'like', '%'.$request->string('q').'%')
                ->orWhere('contact_person', 'like', '%'.$request->string('q').'%')))
            ->when($request->input('status', 'active') !== 'all', fn ($q) => $q->where('status', $request->input('status', 'active')))
            ->withCount(['receipts' => fn ($q) => $q->where('status', 'posted')])
            ->withMax(['receipts' => fn ($q) => $q->where('status', 'posted')], 'delivery_date')
            ->orderBy('company_name');

        return response()->json($q->paginate(50));
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        $showCost = $request->user()->hasPermission('financials.view');
        $receipts = StockReceipt::query()->where('supplier_id', $supplier->id)->orderByDesc('delivery_date')->orderByDesc('id')->limit(100)
            ->get(['id', 'receipt_no', 'supplier_reference', 'delivery_date', 'status', 'total_quantity', 'total_cost', 'posted_at'])
            ->map(fn ($r) => array_filter($r->toArray() + [], fn ($v, $k) => $showCost || $k !== 'total_cost', ARRAY_FILTER_USE_BOTH));

        $products = DB::table('stock_receipt_items as i')->join('stock_receipts as r', 'r.id', '=', 'i.stock_receipt_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('tyre_specs as s', 's.product_id', '=', 'p.id')
            ->where('r.supplier_id', $supplier->id)->where('r.status', 'posted')
            ->selectRaw('p.id, p.sku, b.name as brand, p.name as model, s.size_display as size, p.`condition` as `condition`, SUM(i.quantity) as qty, MAX(r.delivery_date) as last_delivery'
                .($showCost ? ', SUM(i.line_total) as total_cost, MAX(i.unit_cost) as last_unit_cost' : ''))
            ->groupBy('p.id', 'p.sku', 'b.name', 'p.name', 's.size_display', 'p.condition')->orderByDesc('qty')->get();

        return response()->json(['data' => $supplier->toArray() + [
            'receipts' => $receipts,
            'products' => $products,
            'totals' => [
                'deliveries' => $receipts->where('status', 'posted')->count(),
                'quantity' => (int) $products->sum('qty'),
                'cost' => $showCost ? Money::normalize((string) $products->sum('total_cost')) : null,
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $supplier = Supplier::query()->create($this->validated($request) + ['status' => 'active']);
        Audit::log('supplier.created', $supplier, "Created supplier {$supplier->company_name}");

        return response()->json(['data' => $supplier], 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $this->validated($request, $supplier) + $request->validate(['status' => ['sometimes', Rule::in(['active', 'archived'])]]);
        $original = $supplier->getOriginal();
        $supplier->fill($data)->save();
        Audit::changes('supplier.updated', $supplier, $original, "Updated supplier {$supplier->company_name}");

        return response()->json(['data' => $supplier]);
    }

    protected function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'company_name' => [$supplier ? 'sometimes' : 'required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
