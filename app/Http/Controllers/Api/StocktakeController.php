<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\User;
use App\Services\Inventory\StocktakeScope;
use App\Services\Inventory\StocktakeService;
use App\Services\Inventory\TyreSearchService;
use App\Support\AppSettings;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StocktakeController extends Controller
{
    public function __construct(protected StocktakeService $stocktakes) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $seeAll = $user->hasPermission('stocktake.manage') || $user->hasPermission('stocktake.approve');
        $q = Stocktake::query()->with(['assignees:id,name', 'creator:id,name', 'approver:id,name'])
            ->withCount(['items', 'items as counted_count' => fn ($q) => $q->whereNotNull('counted_qty')->where('status', '!=', 'recount_requested'),
                'items as variance_count' => fn ($q) => $q->whereNotNull('variance')->where('variance', '!=', 0)])
            ->when(! $seeAll, fn ($q) => $q->whereHas('assignees', fn ($a) => $a->whereKey($user->id))->whereIn('status', [Stocktake::IN_PROGRESS, Stocktake::SUBMITTED]))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->whereIn('status', explode(',', $request->input('status'))))
            ->orderByDesc('id');
        $page = $q->paginate(25);
        $page->getCollection()->transform(fn ($s) => $this->summary($s));

        return response()->json($page);
    }

    public function show(Request $request, Stocktake $stocktake, AppSettings $settings): JsonResponse
    {
        $this->authorize('view', $stocktake);
        $stocktake->load(['assignees:id,name', 'creator:id,name', 'submitter:id,name', 'reviewer:id,name', 'approver:id,name'])
            ->loadCount(['items', 'items as counted_count' => fn ($q) => $q->whereNotNull('counted_qty')->where('status', '!=', 'recount_requested'),
                'items as variance_count' => fn ($q) => $q->whereNotNull('variance')->where('variance', '!=', 0)]);
        $data = $this->summary($stocktake) + [
            'lock_mode' => $settings->get('stocktake_lock_mode'),
            'can_count' => Gate::allows('count', $stocktake),
            'can_view_expected' => Gate::allows('viewExpected', $stocktake),
            'can_approve' => $request->user()->hasPermission('stocktake.approve'),
            'can_manage' => $request->user()->hasPermission('stocktake.manage'),
        ];

        return response()->json(['data' => $data]);
    }

    /** Lines of a stocktake. Expected quantities are withheld from blind counters. */
    public function items(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->authorize('view', $stocktake);
        $showExpected = Gate::allows('viewExpected', $stocktake);
        $showCost = $request->user()->hasPermission('financials.view');
        $q = $stocktake->items()->with(['product.brand', 'product.tyreModel', 'product.spec', 'location', 'unit', 'counter:id,name'])
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->input('filter') === 'uncounted', fn ($q) => $q->whereIn('status', ['pending', 'recount_requested']))
            ->when($request->input('filter') === 'counted', fn ($q) => $q->where('status', 'counted'))
            ->when($request->input('filter') === 'variance' && $showExpected, fn ($q) => $q->whereNotNull('variance')->where('variance', '!=', 0))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->whereHas('product', fn ($p) => $p->where('sku', 'like', $term)->orWhere('name', 'like', $term)
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $term))
                    ->orWhereHas('spec', fn ($s) => $s->where('size_display', 'like', $term)))
                    ->orWhereHas('unit', fn ($u) => $u->where('unit_code', 'like', $term)));
            })
            ->join('inventory_locations as l', 'l.id', '=', 'stocktake_items.location_id')
            ->select('stocktake_items.*')
            ->orderBy('l.sort_order')->orderBy('stocktake_items.id');

        $during = $showExpected ? $this->movementsDuringCount($stocktake) : [];
        $items = $q->get()->map(fn (StocktakeItem $i) => $this->presentItem($i, $stocktake, $showExpected, $showCost, $during));

        $totals = null;
        if ($showExpected) {
            $all = $stocktake->items()->get();
            $totals = [
                'lines' => $all->count(),
                'counted' => $all->whereNotNull('counted_qty')->where('status', '!=', 'recount_requested')->count(),
                'shortage_qty' => $all->where('variance', '<', 0)->sum('variance'),
                'excess_qty' => $all->where('variance', '>', 0)->sum('variance'),
                'cost_impact' => $showCost ? Money::add('0', ...$all->pluck('cost_impact')->filter()->map(fn ($v) => (string) $v)->all()) : null,
            ];
        }

        return response()->json(['data' => $items, 'totals' => $totals]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(Stocktake::TYPES)],
            'brand_ids' => ['array'],
            'brand_ids.*' => ['integer', Rule::exists('brands', 'id')],
            'width' => ['nullable', 'integer'],
            'profile' => ['nullable', 'integer'],
            'rim' => ['nullable', 'numeric'],
            'location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')],
            'is_blind' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'assignee_ids' => ['array'],
            'assignee_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);
        foreach ($data['assignee_ids'] ?? [] as $id) {
            $assignee = User::query()->find($id);
            abort_unless($assignee?->hasPermission('stocktake.count') || $assignee?->hasPermission('stocktake.manage'), 422, "{$assignee?->name} does not have permission to count stock.");
        }
        $stocktake = $this->stocktakes->create($data, $request->user());
        if ($request->boolean('start')) {
            $this->stocktakes->start($stocktake, $request->user());
        }

        return response()->json(['data' => ['id' => $stocktake->id, 'stocktake_no' => $stocktake->stocktake_no]], 201);
    }

    public function updateAssignees(Request $request, Stocktake $stocktake): JsonResponse
    {
        $data = $request->validate(['assignee_ids' => ['required', 'array'], 'assignee_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)]]);
        abort_if(in_array($stocktake->status, [Stocktake::APPROVED, Stocktake::CLOSED, Stocktake::CANCELLED], true), 409, 'This stocktake is finished.');
        $stocktake->assignees()->sync($data['assignee_ids']);
        Audit::log('stocktake.assignees_changed', $stocktake, "Counters changed on {$stocktake->stocktake_no}", null, ['assignee_ids' => $data['assignee_ids']]);

        return response()->json(['message' => 'Counters updated.']);
    }

    public function start(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->stocktakes->start($stocktake, $request->user());

        return $this->show($request, $stocktake, app(AppSettings::class));
    }

    public function count(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->authorize('count', $stocktake);
        $data = $request->validate([
            'item_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'unit_code' => ['nullable', 'string', 'max:30'],
            'location_id' => ['required_without:item_id', 'nullable', 'integer', Rule::exists('inventory_locations', 'id')],
            'counted_qty' => ['required', 'integer', 'min:0', 'max:100000'],
            'replace' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $item = $this->stocktakes->count($stocktake, $data, $request->user());

        return response()->json(['data' => $this->presentItem($item, $stocktake, Gate::allows('viewExpected', $stocktake), $request->user()->hasPermission('financials.view')), 'message' => 'Count saved.']);
    }

    /** Product search restricted to tyres relevant to the stocktake – usable by counters without inventory access. */
    public function searchProducts(Request $request, Stocktake $stocktake, TyreSearchService $search): JsonResponse
    {
        $this->authorize('count', $stocktake);
        $request->validate(['q' => ['required', 'string', 'max:100']]);
        $productIds = StocktakeScope::productQuery($stocktake)->pluck('id');
        $results = $search->query(['q' => $request->input('q')])->whereIn('products.id', $productIds)->limit(20)->get();

        return response()->json(['data' => $results->map(fn ($p) => [
            'id' => $p->id, 'sku' => $p->sku, 'display_name' => $p->displayName(), 'condition' => $p->condition, 'tracking' => $p->tracking,
            'size' => $p->spec?->size_display,
        ])]);
    }

    public function submit(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->authorize('count', $stocktake);
        $this->stocktakes->submit($stocktake, $request->user(), $request->boolean('uncounted_as_zero'));

        return response()->json(['message' => 'Stocktake submitted for review.']);
    }

    public function review(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->stocktakes->startReview($stocktake, $request->user());

        return $this->show($request, $stocktake, app(AppSettings::class));
    }

    public function recount(Request $request, Stocktake $stocktake): JsonResponse
    {
        $data = $request->validate(['item_ids' => ['required', 'array', 'min:1'], 'item_ids.*' => ['integer'], 'reason' => ['required', 'string', 'max:255']]);
        $this->stocktakes->requestRecount($stocktake, $data['item_ids'], $data['reason'], $request->user());

        return response()->json(['message' => 'Recount requested.']);
    }

    public function approve(Request $request, Stocktake $stocktake): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'decisions' => ['array'],
            'decisions.*.item_id' => ['required', 'integer'],
            'decisions.*.approve' => ['required', 'boolean'],
            'decisions.*.reason' => ['nullable', 'string', 'max:500'],
        ]);
        $decisions = collect($data['decisions'] ?? [])->keyBy('item_id')->all();
        $this->stocktakes->approve($stocktake, $decisions, $data['notes'] ?? null, $request->user());

        return response()->json(['message' => 'Stocktake approved and adjustments posted.']);
    }

    public function close(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->stocktakes->close($stocktake, $request->user());

        return response()->json(['message' => 'Stocktake closed.']);
    }

    public function cancel(Request $request, Stocktake $stocktake): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->stocktakes->cancel($stocktake, $data['reason'], $request->user());

        return response()->json(['message' => 'Stocktake cancelled.']);
    }

    protected function summary(Stocktake $s): array
    {
        return [
            'id' => $s->id,
            'stocktake_no' => $s->stocktake_no,
            'title' => $s->title,
            'type' => $s->type,
            'scope' => $s->scope,
            'scope_label' => StocktakeScope::describe($s),
            'is_blind' => $s->is_blind,
            'status' => $s->status,
            'notes' => $s->notes,
            'assignees' => $s->assignees->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'created_by' => $s->creator?->name,
            'created_at' => $s->created_at?->toIso8601String(),
            'started_at' => $s->started_at?->toIso8601String(),
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'submitted_by' => $s->relationLoaded('submitter') ? $s->submitter?->name : null,
            'reviewed_by' => $s->relationLoaded('reviewer') ? $s->reviewer?->name : null,
            'approved_by' => $s->approver?->name,
            'approved_at' => $s->approved_at?->toIso8601String(),
            'approval_notes' => $s->approval_notes,
            'adjustment_reference' => $s->adjustment_reference,
            'closed_at' => $s->closed_at?->toIso8601String(),
            'items_count' => $s->items_count ?? null,
            'counted_count' => $s->counted_count ?? null,
            // Even the number of differing lines is withheld from blind counters.
            'variance_count' => Gate::allows('viewExpected', $s) ? ($s->variance_count ?? null) : null,
        ];
    }

    /**
     * Net stock movements (excluding stocktake adjustments) since the count started, keyed
     * "product:location" and "unit:{id}". Shown to reviewers so no sale or receipt is silently ignored.
     *
     * @return array<string,int>
     */
    protected function movementsDuringCount(Stocktake $stocktake): array
    {
        if (! $stocktake->started_at) {
            return [];
        }
        $rows = StockMovement::query()->where('id', '>', (int) $stocktake->start_movement_id)
            ->where('type', '!=', StockMovement::STOCKTAKE_ADJUSTMENT)
            ->whereIn('product_id', $stocktake->items()->select('product_id'))
            ->selectRaw('product_id, location_id, tyre_unit_id, SUM(quantity_change) as qty')
            ->groupBy('product_id', 'location_id', 'tyre_unit_id')->get();
        $out = [];
        foreach ($rows as $r) {
            $key = $r->product_id.':'.$r->location_id;
            $out[$key] = ($out[$key] ?? 0) + (int) $r->qty;
            if ($r->tyre_unit_id) {
                $out['unit:'.$r->tyre_unit_id.':'.$r->location_id] = (int) $r->qty;
            }
        }

        return $out;
    }

    protected function presentItem(StocktakeItem $i, Stocktake $stocktake, bool $showExpected, bool $showCost, ?array $during = null): array
    {
        $during ??= $showExpected ? $this->movementsDuringCount($stocktake) : [];
        $data = [
            'id' => $i->id,
            'product_id' => $i->product_id,
            // Brand and model only; the size is shown separately as a size chip.
            'product' => trim(($i->product?->brand?->name ?? '').' '.($i->product?->tyreModel?->name ?? $i->product?->name ?? '')),
            'sku' => $i->product?->sku,
            'condition' => $i->product?->condition,
            'size' => $i->product?->spec?->size_display,
            'location_id' => $i->location_id,
            'location' => $i->location?->name,
            'tyre_unit_id' => $i->tyre_unit_id,
            'unit_code' => $i->unit?->unit_code,
            'counted_qty' => $i->counted_qty,
            'count_version' => $i->count_version,
            'status' => $i->status,
            'is_unexpected' => $i->is_unexpected,
            'recount_reason' => $i->recount_reason,
            'adjustment_reason' => $i->adjustment_reason,
            'counted_by' => $i->counter?->name,
            'counted_at' => $i->counted_at?->toIso8601String(),
        ];
        if ($showExpected) {
            $data += [
                'snapshot_qty' => $i->snapshot_qty,
                'system_qty_at_count' => $i->system_qty_at_count,
                'variance' => $i->variance,
                'movements_during_count' => $i->tyre_unit_id
                    ? ($during['unit:'.$i->tyre_unit_id.':'.$i->location_id] ?? 0)
                    : ($during[$i->product_id.':'.$i->location_id] ?? 0),
            ];
        }
        if ($showCost && $showExpected) {
            $data['unit_cost'] = $i->unit_cost !== null ? (string) $i->unit_cost : null;
            $data['cost_impact'] = $i->cost_impact !== null ? (string) $i->cost_impact : null;
        }

        return $data;
    }
}
