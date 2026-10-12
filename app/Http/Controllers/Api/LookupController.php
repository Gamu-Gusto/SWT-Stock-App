<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\InventoryLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\SalesService;
use App\Support\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small reference lists used throughout the app (brands, locations, suppliers, settings).
 */
class LookupController extends Controller
{
    public function index(Request $request, AppSettings $settings): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'settings' => $settings->publicValues(),
            'brands' => Brand::query()->whereNull('archived_at')->orderByDesc('is_preferred')->orderBy('name')
                ->with(['models' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
                ->get(['id', 'name', 'is_active', 'is_preferred'])
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'is_active' => $b->is_active, 'is_preferred' => $b->is_preferred,
                    'models' => $b->models->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])]),
            'locations' => InventoryLocation::query()->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'code', 'name', 'type', 'is_sellable', 'is_active']),
            'suppliers' => $user->hasPermission('suppliers.view') || $user->hasPermission('receiving.create') || $user->hasPermission('inventory.manage')
                ? Supplier::query()->where('status', 'active')->orderBy('company_name')->get(['id', 'company_name'])
                : [],
            'employees' => $user->hasPermission('users.view') || $user->hasPermission('stocktake.manage') || $user->hasPermission('reports.sales')
                ? User::query()->where('is_active', true)->orderBy('name')->with('role:id,name,label')->get(['id', 'name', 'role_id'])
                    ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role?->label])
                : [],
            'service_codes' => SalesService::SERVICE_CODES,
            'tyre_types' => self::TYRE_TYPES,
            'movement_types' => StockMovement::TYPES,
        ]);
    }

    public const TYRE_TYPES = [
        'passenger' => 'Passenger',
        'suv_4x4' => 'SUV / 4x4',
        'light_truck' => 'Light truck / Commercial (C)',
        'performance' => 'Performance',
        'all_terrain' => 'All terrain (A/T)',
        'mud_terrain' => 'Mud terrain (M/T)',
        'highway_terrain' => 'Highway terrain (H/T)',
        'run_flat' => 'Run flat',
        'other' => 'Other',
    ];
}
