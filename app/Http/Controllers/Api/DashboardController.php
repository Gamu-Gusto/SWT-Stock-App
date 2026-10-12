<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Services\Inventory\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard, ReservationService $reservations): JsonResponse
    {
        // Keep availability accurate even if the scheduled task is not configured yet.
        $reservations->expireDue();

        return response()->json(['data' => $dashboard->build($request->user())]);
    }
}
