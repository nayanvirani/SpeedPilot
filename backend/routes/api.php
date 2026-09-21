<?php

use App\Http\Controllers\Api\AiRecommendationController;
use App\Http\Controllers\Api\AppImpactController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\FixCodeController;
use App\Http\Controllers\Api\MediumFixController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\OptimizationController;
use App\Http\Controllers\Api\RumEventController;
use App\Http\Controllers\Api\ShopSettingsController;
use Illuminate\Support\Facades\Route;

// Public, shop-scoped ingestion from the storefront Theme App Extension -
// no App Bridge session token exists for anonymous visitor traffic.
Route::post('/rum-events', [RumEventController::class, 'store'])
    ->middleware('throttle:rum');

// Everything else is embedded-admin traffic authenticated via App Bridge
// session token (VerifyShopifySessionToken resolves it to $request->shop).
Route::middleware('shopify.session')->group(function () {
    Route::post('/audits', [AuditController::class, 'store']);
    Route::get('/audits', [AuditController::class, 'index']);
    Route::get('/audits/{id}', [AuditController::class, 'show']);
    Route::post('/audits/{id}/apply-safe-fixes', [AuditController::class, 'applySafeFixes']);
    Route::get('/audit-issues/{id}/recommendation', [AiRecommendationController::class, 'show']);
    Route::get('/audits/{id}/priority-plan', [AiRecommendationController::class, 'prioritize']);
    Route::post('/audit-issues/{id}/medium-fix/preview', [MediumFixController::class, 'preview']);
    Route::post('/audit-issues/{id}/medium-fix/apply', [MediumFixController::class, 'apply']);
    Route::get('/audit-issues/{id}/fix-code', [FixCodeController::class, 'forIssue']);

    Route::get('/app-impacts', [AppImpactController::class, 'index']);
    Route::patch('/app-impacts/{id}', [AppImpactController::class, 'updateStatus']);
    Route::get('/app-impacts/{id}/fix-code', [FixCodeController::class, 'forAppImpact']);

    Route::get('/optimizations', [OptimizationController::class, 'index']);
    Route::post('/optimizations/{id}/rollback', [OptimizationController::class, 'rollback']);

    Route::get('/monitoring/trend', [MonitoringController::class, 'trend']);
    Route::get('/rum-events/summary', [RumEventController::class, 'summary']);

    Route::get('/billing/plans', [BillingController::class, 'plans']);
    Route::get('/billing/current', [BillingController::class, 'current']);

    Route::get('/settings', [ShopSettingsController::class, 'show']);
    Route::put('/settings/storefront-password', [ShopSettingsController::class, 'updateStorefrontPassword']);
    Route::put('/settings/scan-preferences', [ShopSettingsController::class, 'updateScanPreferences']);
    Route::put('/settings/slack-webhook', [ShopSettingsController::class, 'updateSlackWebhook']);
    Route::get('/themes', [ShopSettingsController::class, 'listThemes']);
    Route::put('/settings/target-theme', [ShopSettingsController::class, 'updateTargetTheme']);
});
