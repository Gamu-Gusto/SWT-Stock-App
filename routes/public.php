<?php

use App\Http\Controllers\Api\PublicAvailabilityController;
use Illuminate\Support\Facades\Route;

/*
| Public tyre availability API (prefix: /api/public). No session, no authentication, rate limited.
| Returns 404 until enabled in Settings → Public website API. See docs/API.md.
*/
Route::get('tyres/availability', PublicAvailabilityController::class);
