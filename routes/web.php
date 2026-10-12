<?php

use App\Http\Controllers\BrandingController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::get('/setup', [SetupController::class, 'show']);
Route::post('/setup', [SetupController::class, 'store']); // rate limited inside the controller (no DB yet)

Route::get('/branding', [BrandingController::class, 'show']);
Route::get('/branding/logo', [BrandingController::class, 'logo']);

// Named route used by Laravel's password reset e-mail; rendered by the SPA.
Route::view('/reset-password/{token}', 'app')->name('password.reset');

// Single-page application: every other path renders the Vue app (authentication is enforced by the API).
Route::view('/{any?}', 'app')->where('any', '^(?!api/|build/|setup|branding|up$).*$');
