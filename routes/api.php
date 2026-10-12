<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StockOperationsController;
use App\Http\Controllers\Api\StocktakeController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TyreUnitController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| Private application API (same-origin SPA, Sanctum session cookies + CSRF).
| Every route is authorised server-side with `can:<permission>` or a policy.
*/
Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/password', [AuthController::class, 'changePassword']);
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);
        Route::get('lookups', [LookupController::class, 'index']);

        Route::get('dashboard', DashboardController::class)->middleware('can:dashboard.view');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/summary', [NotificationController::class, 'summary']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        // Inventory
        Route::middleware('can:inventory.view')->group(function () {
            Route::get('products', [ProductController::class, 'index']);
            Route::get('products/{product}', [ProductController::class, 'show']);
            Route::get('products/{product}/units', [ProductController::class, 'units']);
            Route::get('units/lookup', [TyreUnitController::class, 'lookup']);
            Route::get('units/{unit}', [TyreUnitController::class, 'show']);
        });
        Route::get('products/{product}/movements', [ProductController::class, 'movements'])->middleware('can:movements.view');
        Route::middleware('can:inventory.manage')->group(function () {
            Route::post('products', [ProductController::class, 'store']);
            Route::put('products/{product}', [ProductController::class, 'update']);
            Route::post('products/{product}/images', [ProductController::class, 'uploadImage']);
            Route::post('products/{product}/units', [TyreUnitController::class, 'store']);
            Route::put('units/{unit}', [TyreUnitController::class, 'update']);
            Route::post('units/{unit}/images', [TyreUnitController::class, 'uploadImage']);
        });
        Route::post('units/{unit}/inspect', [TyreUnitController::class, 'inspect'])->middleware('can:used.inspect');
        Route::middleware('can:inventory.archive')->group(function () {
            Route::post('products/{product}/archive', [ProductController::class, 'archive']);
            Route::post('products/{product}/restore', [ProductController::class, 'restore']);
        });
        Route::get('media/{media}', [MediaController::class, 'show']);
        Route::delete('media/{media}', [MediaController::class, 'destroy']);

        // Brands, models & locations
        Route::get('brands', [CatalogController::class, 'brands'])->middleware('can:inventory.view');
        Route::middleware('can:brands.manage')->group(function () {
            Route::post('brands', [CatalogController::class, 'storeBrand']);
            Route::put('brands/{brand}', [CatalogController::class, 'updateBrand']);
            Route::post('brands/{brand}/models', [CatalogController::class, 'storeModel']);
            Route::put('models/{model}', [CatalogController::class, 'updateModel']);
        });
        Route::get('locations', [CatalogController::class, 'locations'])->middleware('can:inventory.view');
        Route::middleware('can:locations.manage')->group(function () {
            Route::post('locations', [CatalogController::class, 'storeLocation']);
            Route::put('locations/{location}', [CatalogController::class, 'updateLocation']);
        });

        // Suppliers
        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('can:suppliers.view');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('can:suppliers.view');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('can:suppliers.manage');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('can:suppliers.manage');

        // Receiving
        Route::get('receipts', [ReceiptController::class, 'index'])->middleware('can:receiving.view');
        Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->middleware('can:receiving.view');
        Route::middleware('can:receiving.create')->group(function () {
            Route::post('receipts', [ReceiptController::class, 'store']);
            Route::put('receipts/{receipt}', [ReceiptController::class, 'update']);
            Route::delete('receipts/{receipt}', [ReceiptController::class, 'destroy']);
            Route::post('receipts/{receipt}/attachments', [ReceiptController::class, 'attach']);
        });
        Route::post('receipts/{receipt}/post', [ReceiptController::class, 'post'])->middleware('can:receiving.post');
        Route::post('receipts/{receipt}/reverse', [ReceiptController::class, 'reverse'])->middleware('can:receiving.reverse');

        // Sales & returns
        Route::middleware('can:sales.create')->group(function () {
            Route::get('sales', [SaleController::class, 'index']);
            Route::post('sales', [SaleController::class, 'store']);
            Route::get('sales/{sale}', [SaleController::class, 'show']);
            Route::put('sales/{sale}', [SaleController::class, 'update']);
            Route::delete('sales/{sale}', [SaleController::class, 'destroy']);
            Route::post('sales/{sale}/complete', [SaleController::class, 'complete']);
        });
        Route::post('sales/{sale}/void', [SaleController::class, 'void'])->middleware('can:sales.void');
        Route::middleware('can:sales.returns')->group(function () {
            Route::post('sales/{sale}/returns', [SaleController::class, 'storeReturn']);
            Route::get('returns/pending', [SaleController::class, 'pendingReturns']);
            Route::post('return-items/{item}/inspect', [SaleController::class, 'inspectReturn']);
        });

        // Reservations & quotations
        Route::middleware('can:reservations.manage')->group(function () {
            Route::get('reservations', [ReservationController::class, 'index']);
            Route::post('reservations', [ReservationController::class, 'store']);
            Route::get('reservations/{reservation}', [ReservationController::class, 'show']);
            Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
            Route::post('reservations/{reservation}/extend', [ReservationController::class, 'extend']);
        });
        Route::middleware('can:quotations.manage')->group(function () {
            Route::get('quotations', [QuotationController::class, 'index']);
            Route::post('quotations', [QuotationController::class, 'store']);
            Route::get('quotations/{quotation}', [QuotationController::class, 'show']);
            Route::post('quotations/{quotation}/cancel', [QuotationController::class, 'cancel']);
            Route::post('quotations/{quotation}/reservation', [QuotationController::class, 'toReservation']);
            Route::post('quotations/{quotation}/sale', [QuotationController::class, 'toSale']);
        });

        // Stock movements, transfers and adjustments
        Route::get('movements', [StockOperationsController::class, 'movements'])->middleware('can:movements.view');
        Route::get('transfers', [StockOperationsController::class, 'transfers'])->middleware('can:movements.view');
        Route::post('transfers', [StockOperationsController::class, 'storeTransfer'])->middleware('can:transfers.create');
        Route::get('adjustments', [StockOperationsController::class, 'adjustments'])->middleware('can:adjustments.request');
        Route::post('adjustments', [StockOperationsController::class, 'storeAdjustment'])->middleware('can:adjustments.request');
        Route::post('adjustments/{adjustment}/approve', [StockOperationsController::class, 'approveAdjustment'])->middleware('can:adjustments.approve');
        Route::post('adjustments/{adjustment}/reject', [StockOperationsController::class, 'rejectAdjustment'])->middleware('can:adjustments.approve');

        // Stocktaking (record-level access enforced by StocktakePolicy)
        Route::get('stocktakes', [StocktakeController::class, 'index']);
        Route::get('stocktakes/{stocktake}', [StocktakeController::class, 'show']);
        Route::get('stocktakes/{stocktake}/items', [StocktakeController::class, 'items']);
        Route::get('stocktakes/{stocktake}/products', [StocktakeController::class, 'searchProducts']);
        Route::post('stocktakes/{stocktake}/count', [StocktakeController::class, 'count']);
        Route::post('stocktakes/{stocktake}/submit', [StocktakeController::class, 'submit']);
        Route::middleware('can:stocktake.manage')->group(function () {
            Route::post('stocktakes', [StocktakeController::class, 'store']);
            Route::post('stocktakes/{stocktake}/start', [StocktakeController::class, 'start']);
            Route::put('stocktakes/{stocktake}/assignees', [StocktakeController::class, 'updateAssignees']);
            Route::post('stocktakes/{stocktake}/cancel', [StocktakeController::class, 'cancel']);
        });
        Route::middleware('can:stocktake.approve')->group(function () {
            Route::post('stocktakes/{stocktake}/review', [StocktakeController::class, 'review']);
            Route::post('stocktakes/{stocktake}/recount', [StocktakeController::class, 'recount']);
            Route::post('stocktakes/{stocktake}/approve', [StocktakeController::class, 'approve']);
            Route::post('stocktakes/{stocktake}/close', [StocktakeController::class, 'close']);
        });

        // Reports (per-report permission checked in ReportService)
        Route::get('reports', [ReportController::class, 'index']);
        Route::get('reports/{key}', [ReportController::class, 'show']);
        Route::get('reports/{key}/export', [ReportController::class, 'export']);

        // Import
        Route::middleware('can:inventory.import')->group(function () {
            Route::get('imports/template', [ImportController::class, 'template']);
            Route::get('imports', [ImportController::class, 'index']);
            Route::post('imports', [ImportController::class, 'store']);
            Route::get('imports/{import}', [ImportController::class, 'show']);
            Route::post('imports/{import}/preview', [ImportController::class, 'preview']);
            Route::post('imports/{import}/commit', [ImportController::class, 'commit']);
        });

        // Administration
        Route::get('users', [UserController::class, 'index'])->middleware('can:users.view');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('can:users.view');
        Route::middleware('can:users.manage')->group(function () {
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::post('users/{user}/activate', [UserController::class, 'activate']);
            Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        });
        Route::get('roles', [UserController::class, 'roles'])->middleware('can:users.view');
        Route::put('roles/{role}', [UserController::class, 'updateRole'])->middleware('can:roles.manage');
        Route::get('audit-logs', [AuditController::class, 'index'])->middleware('can:audit.view');
        Route::get('audit-logs/actions', [AuditController::class, 'actions'])->middleware('can:audit.view');
        Route::middleware('can:settings.manage')->group(function () {
            Route::get('settings', [SettingsController::class, 'show']);
            Route::put('settings', [SettingsController::class, 'update']);
            Route::post('settings/logo', [SettingsController::class, 'uploadLogo']);
            Route::delete('settings/logo', [SettingsController::class, 'removeLogo']);
        });
    });
});
