<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\NodeController;
use App\Http\Controllers\Api\Admin\ProductModerationController;
use App\Http\Controllers\Api\Admin\RequestQueueController;
use App\Http\Controllers\Api\AdvisoryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MealOfferController;
use App\Http\Controllers\Api\PetController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\SuggestionController;
use App\Http\Middleware\SetRegion;
use Illuminate\Support\Facades\Route;

/*
 * Everything lives under /api/v1 so the mobile app and the admin tool can evolve without breaking each other.
 * Interactive docs: /docs/api (OpenAPI at /docs/api.json).
 */
Route::prefix('v1')->group(function () {
    // Public
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Authenticated (Sanctum bearer token)
    Route::middleware(['auth:sanctum', SetRegion::class])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Shared product catalogue (global)
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/lookup/{code}', [ProductController::class, 'lookup'])->where('code', '[0-9\-\s]+');
        Route::get('/tags', [ProductController::class, 'tags']);
        Route::get('/brand-nodes', [ProductController::class, 'nodes']);
        Route::patch('/brand-nodes/{brandNode}', [ProductController::class, 'updateNode'])->whereNumber('brandNode')->middleware('role:admin');
        Route::post('/brand-nodes/{brandNode}/merge', [ProductController::class, 'mergeNode'])->whereNumber('brandNode')->middleware('role:admin');
        Route::get('/brand-choices', [ProductController::class, 'choices']);
        Route::get('/products/audit-summary', [ProductController::class, 'auditSummary']);
        Route::get('/products/{product}', [ProductController::class, 'show'])->whereNumber('product');
        Route::get('/products/{product}/versions', [ProductController::class, 'versions'])->whereNumber('product');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->whereNumber('product');
        Route::put('/products/{product}/barcode', [ProductController::class, 'attachBarcode'])->whereNumber('product');
        Route::post('/products', [ProductController::class, 'store']);

        // Moderators and admins: the review queue, every contributor's products, the ladder, and the change history.
        Route::prefix('admin')->middleware('role:moderator')->group(function () {
            Route::get('/dashboard', DashboardController::class);
            Route::get('/products', [ProductModerationController::class, 'index']);
            Route::get('/products/export', [ProductModerationController::class, 'export']);
            Route::post('/products/bulk', [ProductModerationController::class, 'bulk']);
            Route::get('/products/{id}', [ProductModerationController::class, 'show'])->whereNumber('id');
            Route::post('/products/{id}/moderate', [ProductModerationController::class, 'moderate'])->whereNumber('id');
            Route::post('/products/{id}/place', [ProductModerationController::class, 'place'])->whereNumber('id');
            Route::post('/products/{id}/merge', [ProductModerationController::class, 'merge'])->whereNumber('id');
            Route::get('/requests', [RequestQueueController::class, 'index']);
            Route::post('/requests/resolve', [RequestQueueController::class, 'resolve']);
            Route::post('/requests/decline', [RequestQueueController::class, 'decline']);
            Route::get('/nodes', [NodeController::class, 'index']);
            Route::post('/nodes', [NodeController::class, 'store']);
            Route::delete('/nodes/{id}', [NodeController::class, 'destroy'])->whereNumber('id')->middleware('role:admin');
            Route::post('/nodes/{id}/retire', [NodeController::class, 'retire'])->whereNumber('id')->middleware('role:admin');
            Route::get('/audit-log', [AuditLogController::class, 'index']);
        });

        // Public Advisories: attributed third-party information, plus each user's own confirm/dismiss
        Route::get('/advisories', [AdvisoryController::class, 'index']);
        Route::put('/advisory-matches/{id}/review', [AdvisoryController::class, 'review'])->whereNumber('id');

        // Personal data (private to each user)
        Route::apiResource('pets', PetController::class)->except('show');
        Route::get('/pets/{id}/suggestions', [SuggestionController::class, 'show'])->whereNumber('id');
        Route::apiResource('inventory', InventoryController::class)->except('show');
        Route::get('/ratings', [RatingController::class, 'index']);
        Route::post('/ratings', [RatingController::class, 'store']);
        Route::delete('/ratings/{id}', [RatingController::class, 'destroy']);
        Route::get('/meal-offers', [MealOfferController::class, 'index']);
        Route::post('/meal-offers', [MealOfferController::class, 'store']);
        Route::patch('/meal-offers/{id}', [MealOfferController::class, 'update']);
    });
});
