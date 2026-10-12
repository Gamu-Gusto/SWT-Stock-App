<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\AppSettings;
use App\Support\TyreSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Optional public tyre availability API for the public website (disabled by default).
 *
 * Exposes ONLY approved fields: brand, model, size, condition, availability status (or exact
 * available quantity when configured) and the public selling price when enabled. Never exposes
 * costs, margins, suppliers, employees, movements or audit data.
 */
class PublicAvailabilityController extends Controller
{
    public function __construct(protected AppSettings $settings) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->settings->get('public_api_enabled') || $this->settings->get('public_api_visibility') === 'hidden') {
            return $this->cors($request, response()->json(['message' => 'Tyre availability is not published.', 'data' => []], 404));
        }

        $q = $request->validate([
            'width' => ['nullable', 'integer', 'between:'.TyreSize::MIN_WIDTH.','.TyreSize::MAX_WIDTH],
            'profile' => ['nullable', 'integer', 'between:'.TyreSize::MIN_PROFILE.','.TyreSize::MAX_PROFILE],
            'rim' => ['nullable', 'numeric', 'between:'.TyreSize::MIN_RIM.','.TyreSize::MAX_RIM],
            'brand' => ['nullable', 'string', 'max:60'],
            'condition' => ['nullable', Rule::in(['new', 'used'])],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'type' => ['nullable', 'string', 'max:30'],
        ]);
        if (empty($q['width']) && empty($q['rim'])) {
            return $this->cors($request, response()->json(['message' => 'Provide at least a width or rim size.', 'data' => []], 422));
        }

        $showQty = $this->settings->get('public_api_visibility') === 'quantity';
        $showPrice = (bool) $this->settings->get('public_api_show_prices');
        $wanted = (int) ($q['quantity'] ?? 1);

        $products = Product::query()->where('products.status', 'active')->where('is_public', true)
            ->join('tyre_specs', 'tyre_specs.product_id', '=', 'products.id')
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->where('brands.is_active', true)
            ->when(! empty($q['width']), fn ($x) => $x->where('tyre_specs.width', $q['width']))
            ->when(! empty($q['profile']), fn ($x) => $x->where('tyre_specs.profile', $q['profile']))
            ->when(! empty($q['rim']), fn ($x) => $x->where('tyre_specs.rim', TyreSize::rimText($q['rim'])))
            ->when(! empty($q['brand']), fn ($x) => $x->whereRaw('LOWER(brands.name) = ?', [mb_strtolower($q['brand'])]))
            ->when(! empty($q['condition']), fn ($x) => $x->where('products.condition', $q['condition']))
            ->when(! empty($q['type']), fn ($x) => $x->where('tyre_specs.tyre_type', $q['type']))
            ->whereRaw('products.qty_on_hand > products.qty_reserved')
            ->select('products.*')->with(['brand', 'tyreModel', 'spec'])
            ->orderByDesc('brands.is_preferred')->orderBy('brands.name')->limit(100)->get();

        $data = $products->map(function (Product $p) use ($showQty, $showPrice, $wanted) {
            $available = $p->availableQty();
            $row = [
                'brand' => $p->brand?->name,
                'model' => $p->tyreModel?->name ?? $p->name,
                'size' => $p->spec?->size_display,
                'width' => $p->spec?->width,
                'profile' => $p->spec?->profile,
                'rim' => TyreSize::rimText($p->spec?->rim ?? 0),
                'load_index' => $p->spec?->load_index,
                'speed_rating' => $p->spec?->speed_rating,
                'condition' => $p->condition,
                'availability' => $available >= $wanted ? ($p->stockStatus() === 'low_stock' ? 'limited' : 'in_stock') : 'insufficient',
            ];
            if ($showQty) {
                $row['available_quantity'] = $available;
            }
            if ($showPrice) {
                $row['price'] = $p->effectivePrice();
                $row['currency'] = 'ZAR';
            }

            return $row;
        })->values();

        return $this->cors($request, response()->json([
            'data' => $data,
            'meta' => ['count' => $data->count(), 'generated_at' => now()->toIso8601String(), 'note' => 'Availability is indicative; confirm with the store before visiting.'],
        ])->header('Cache-Control', 'public, max-age=60'));
    }

    protected function cors(Request $request, JsonResponse $response): JsonResponse
    {
        $origin = $request->headers->get('Origin');
        $allowed = array_filter(array_map(fn ($o) => rtrim(trim($o), '/'), explode(',', (string) $this->settings->get('public_api_allowed_origins'))));
        if ($origin && in_array(rtrim($origin, '/'), $allowed, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'GET');
        }

        return $response;
    }
}
