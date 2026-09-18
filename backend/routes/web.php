<?php

use App\Http\Controllers\ShopifyOAuthController;
use App\Http\Controllers\Webhooks\AppUninstalledController;
use App\Http\Controllers\Webhooks\GdprController;
use App\Http\Controllers\Webhooks\ThemesPublishController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['app' => 'SpeedPilot API']));

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
