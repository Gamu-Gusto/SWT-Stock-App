<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Quotation;
use App\Models\Sale;
use App\Services\Inventory\SalesService;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuotationController extends Controller
{
    public function __construct(protected QuotationService $quotations) {}

    public function index(Request $request): JsonResponse
    {
        // Expire open quotations past their validity date.
        Quotation::query()->where('status', 'open')->whereDate('valid_until', '<', today())->update(['status' => 'expired']);

        $q = Quotation::query()->with('user:id,name')->withCount('items')
            ->when(! $request->user()->hasPermission('sales.view_all'), fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('quotation_no', 'like', '%'.$request->string('q').'%')
                ->orWhere('customer_name', 'like', '%'.$request->string('q').'%')->orWhere('customer_phone', 'like', '%'.$request->string('q').'%')))
            ->orderByDesc('id');

        return response()->json($q->paginate(25));
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeView($request, $quotation);
        $quotation->load(['items', 'user']);

        return response()->json(['data' => $quotation->toArray() + [
            'whatsapp_url' => $this->quotations->whatsappLink($quotation),
            'user_name' => $quotation->user?->name,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 +()\-]+$/'],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'vehicle' => ['nullable', 'string', 'max:150'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'items' => ['array', 'max:30'],
            'items.*.product_id' => ['required_without:items.*.tyre_unit_id', 'nullable', 'integer', Rule::exists('products', 'id')],
            'items.*.tyre_unit_id' => ['nullable', 'integer', Rule::exists('tyre_units', 'id')],
            'items.*.quantity' => ['required_without:items.*.tyre_unit_id', 'nullable', 'integer', 'min:1', 'max:100'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'services' => ['array', 'max:20'],
            'services.*.service_code' => ['required', Rule::in(array_keys(SalesService::SERVICE_CODES))],
            'services.*.description' => ['nullable', 'string', 'max:150'],
            'services.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'services.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'services.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        $quotation = $this->quotations->create($data, $request->user());

        return $this->show($request, $quotation)->setStatusCode(201);
    }

    public function cancel(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeView($request, $quotation);
        $this->quotations->cancel($quotation);

        return $this->show($request, $quotation->refresh());
    }

    public function toReservation(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeView($request, $quotation);
        abort_unless($request->user()->hasPermission('reservations.manage'), 403);
        $data = $request->validate([
            'customer_name' => [$quotation->customer_name ? 'nullable' : 'required', 'string', 'max:150'],
            'customer_phone' => [$quotation->customer_phone ? 'nullable' : 'required', 'string', 'max:30'],
            'expires_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);
        $reservation = $this->quotations->toReservation($quotation, $data, $request->user());

        return response()->json(['data' => ['reservation_id' => $reservation->id, 'reservation_no' => $reservation->reservation_no]], 201);
    }

    public function toSale(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeView($request, $quotation);
        abort_unless($request->user()->hasPermission('sales.create'), 403);
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(Sale::PAYMENT_METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:80'],
            'amount_paid' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);
        $sale = $this->quotations->toSale($quotation, $data, $request->user());

        return (new SaleResource($sale->load(['items', 'user'])))->response()->setStatusCode(201);
    }

    protected function authorizeView(Request $request, Quotation $quotation): void
    {
        abort_unless($request->user()->hasPermission('sales.view_all') || $quotation->user_id === $request->user()->id, 403);
    }
}
