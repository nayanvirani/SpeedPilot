<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\FaqController as AdminFaqController;
use App\Http\Controllers\Admin\PageController as AdminPageController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\EmbeddedAppController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ShopifyOAuthController;
use App\Http\Controllers\Storefront\InterceptorController;
use App\Http\Controllers\Webhooks\AppSubscriptionsUpdateController;
use App\Http\Controllers\Webhooks\AppUninstalledController;
use App\Http\Controllers\Webhooks\GdprController;
use App\Http\Controllers\Webhooks\ThemesPublishController;
use Illuminate\Support\Facades\Route;

// OAuth install/callback - no session token yet, this *establishes* it.
Route::get('/auth/install', [ShopifyOAuthController::class, 'install']);
Route::get('/auth/callback', [ShopifyOAuthController::class, 'callback']);

// Public static content, editable from /admin/pages and /admin/faq without
// a redeploy - must be registered before the embedded-app catch-all below.
Route::get('/privacy', fn () => app(PageController::class)->show('privacy'))->name('privacy');
Route::get('/faq', [FaqController::class, 'index'])->name('faq');

// Public, unauthenticated - fetched directly by storefront visitors'
// browsers via the <script src> tag "Advanced delay (experimental)" writes
// into theme.liquid. See InterceptorController's docblock for why the
// engine lives here instead of inline in the theme.
Route::get('/storefront/interceptor.js', [InterceptorController::class, 'serve'])
    ->middleware('throttle:interceptor')
    ->name('storefront.interceptor');

// Shopify App Proxy target - https://{shop}/apps/speedpilot/* forwards here.
// /proxy/ping is a bare connectivity smoke test (no HMAC check) for
// confirming the proxy config actually resolves for an existing install
// before anything real is built on top of it. Real proxy routes (sw.js
// etc.) come later, HMAC-verified via AppProxyVerifier.
Route::get('/proxy/ping', fn () => response()->json(['ok' => true, 'query' => request()->query()]));

// Webhooks - HMAC-verified and deduped by shopify.webhook, never App-Bridge
// session tokens.
Route::middleware('shopify.webhook')->group(function () {
    Route::post('/webhooks/app/uninstalled', AppUninstalledController::class);
    Route::post('/webhooks/app_subscriptions/update', AppSubscriptionsUpdateController::class);
    Route::post('/webhooks/themes/publish', ThemesPublishController::class);
    Route::post('/webhooks/customers/data_request', [GdprController::class, 'customersDataRequest']);
    Route::post('/webhooks/customers/redact', [GdprController::class, 'customersRedact']);
    Route::post('/webhooks/shop/redact', [GdprController::class, 'shopRedact']);
});

// Super-admin panel - session-based Laravel auth, separate from the
// per-shop embedded app (which auths via App Bridge session tokens instead).
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])->name('login.attempt');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

    Route::middleware('super_admin')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/stores', [AdminStoreController::class, 'index'])->name('stores.index');
        Route::get('/stores/{store}', [AdminStoreController::class, 'show'])->name('stores.show');
        Route::post('/stores/{store}/resync-plan', [AdminStoreController::class, 'resyncPlan'])->name('stores.resync-plan');
        Route::resource('plans', AdminPlanController::class)->except('show');
        Route::get('/subscriptions', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('/settings', [AdminSettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');
        Route::get('/profile', [AdminProfileController::class, 'edit'])->name('profile.edit');
        Route::post('/profile/password', [AdminProfileController::class, 'updatePassword'])->name('profile.password');
        Route::get('/pages', [AdminPageController::class, 'index'])->name('pages.index');
        Route::get('/pages/{page}/edit', [AdminPageController::class, 'edit'])->name('pages.edit');
        Route::put('/pages/{page}', [AdminPageController::class, 'update'])->name('pages.update');
        Route::resource('faq', AdminFaqController::class)->parameters(['faq' => 'item'])->except('show');
    });
});

// Embedded Shopify Admin app shell (or the public landing page when visited
// directly - see EmbeddedAppController). Anything not matched above renders
// this, so the client-side router can take over on a hard refresh. Must stay
// last - a catch-all route would otherwise shadow everything above it.
Route::get('/{any?}', EmbeddedAppController::class)
    ->where('any', '^(?!api|auth|admin|webhooks).*$')
    ->name('embedded.app');
