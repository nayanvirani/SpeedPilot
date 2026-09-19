<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\ShopifyOAuthController;
use App\Http\Controllers\Webhooks\AppUninstalledController;
use App\Http\Controllers\Webhooks\GdprController;
use App\Http\Controllers\Webhooks\ThemesPublishController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

// OAuth install/callback - no session token yet, this *establishes* it.
Route::get('/auth/install', [ShopifyOAuthController::class, 'install']);
Route::get('/auth/callback', [ShopifyOAuthController::class, 'callback']);

// Webhooks - HMAC-verified against the raw body inside each controller,
// never App-Bridge session tokens.
Route::post('/webhooks/app/uninstalled', AppUninstalledController::class);
Route::post('/webhooks/themes/publish', ThemesPublishController::class);
Route::post('/webhooks/customers/data_request', [GdprController::class, 'customersDataRequest']);
Route::post('/webhooks/customers/redact', [GdprController::class, 'customersRedact']);
Route::post('/webhooks/shop/redact', [GdprController::class, 'shopRedact']);

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
        Route::get('/settings', [AdminSettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');
    });
});
