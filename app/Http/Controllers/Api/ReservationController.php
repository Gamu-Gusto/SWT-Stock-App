<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Inventory\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function __construct(protected ReservationService $reservations) {}

    public function index(Request $request): JsonResponse
    {
        $this->reservations->expireDue();
        $user = $request->user();
        $q = Reservation::query()->with(['user:id,name', 'items.product.brand', 'items.product.spec', 'items.product.tyreModel', 'items.unit'])
            ->when(! $user->hasPermission('reservations.view_all'), fn ($q) => $q->where('user_id', $user->id))
            ->when($request->input('status', 'active') !== 'all', fn ($q) => $q->where('status', $request->input('status', 'active')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('reservation_no', 'like', '%'.$request->string('q').'%')
                ->orWhere('customer_name', 'like', '%'.$request->string('q').'%')->orWhere('customer_phone', 'like', '%'.$request->string('q').'%')))
            ->orderBy($request->input('status', 'active') === 'active' ? 'expires_at' : 'id', $request->input('status', 'active') === 'active' ? 'asc' : 'desc');
        $page = $q->paginate(25);
        $page->getCollection()->transform(fn ($r) => $this->present($r));

        return response()->json($page);
    }

    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeView($request, $reservation);

        return response()->json(['data' => $this->present($reservation->load(['user', 'canceller', 'items.product.brand', 'items.product.spec', 'items.product.tyreModel', 'items.unit']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9 +()\-]+$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'expires_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required_without:items.*.tyre_unit_id', 'nullable', 'integer', Rule::exists('products', 'id')],
            'items.*.tyre_unit_id' => ['nullable', 'integer', Rule::exists('tyre_units', 'id')],
            'items.*.quantity' => ['required_without:items.*.tyre_unit_id', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $reservation = $this->reservations->create($data, $request->user());

        return $this->show($request, $reservation)->setStatusCode(201);
    }

    public function cancel(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeView($request, $reservation);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->reservations->cancel($reservation, $data['reason'] ?? null, $request->user());

        return $this->show($request, $reservation->refresh());
    }

    public function extend(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeView($request, $reservation);
        $data = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:168']]);
        $this->reservations->extend($reservation, $data['hours'], $request->user());

        return $this->show($request, $reservation->refresh());
    }

    protected function authorizeView(Request $request, Reservation $reservation): void
    {
        $user = $request->user();
        abort_unless($user->hasPermission('reservations.view_all') || $reservation->user_id === $user->id, 403);
    }

    protected function present(Reservation $r): array
    {
        return [
            'id' => $r->id,
            'reservation_no' => $r->reservation_no,
            'status' => $r->status,
            'customer_name' => $r->customer_name,
            'customer_phone' => $r->customer_phone,
            'notes' => $r->notes,
            'expires_at' => $r->expires_at?->toIso8601String(),
            'is_expiring_soon' => $r->status === 'active' && $r->expires_at?->lt(now()->addHours(4)),
            'user' => $r->user?->name,
            'user_id' => $r->user_id,
            'sale_id' => $r->sale_id,
            'quotation_id' => $r->quotation_id,
            'completed_at' => $r->completed_at?->toIso8601String(),
            'cancelled_at' => $r->cancelled_at?->toIso8601String(),
            'cancelled_by' => $r->relationLoaded('canceller') ? $r->canceller?->name : null,
            'cancel_reason' => $r->cancel_reason,
            'created_at' => $r->created_at?->toIso8601String(),
            'items' => $r->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product' => $i->product?->displayName(),
                'condition' => $i->product?->condition,
                'tyre_unit_id' => $i->tyre_unit_id,
                'unit_code' => $i->unit?->unit_code,
                'quantity' => $i->quantity,
                'fulfilled_qty' => $i->fulfilled_qty,
                'unit_price' => (string) $i->unit_price,
            ]),
        ];
    }
}
