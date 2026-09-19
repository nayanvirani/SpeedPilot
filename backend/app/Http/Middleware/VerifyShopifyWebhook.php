<?php

namespace App\Http\Middleware;

use App\Services\Shopify\WebhookVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the HMAC signature on every incoming Shopify webhook and drops
 * duplicate deliveries - Shopify retries webhooks and does not guarantee
 * exactly-once delivery, so without this a retried app_subscriptions/update
 * or app/uninstalled could be processed twice.
 */
class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! WebhookVerifier::isValid($request->getContent(), $request->header('X-Shopify-Hmac-Sha256', ''))) {
            abort(401, 'Invalid webhook signature.');
        }

        $webhookId = $request->header('X-Shopify-Webhook-Id');
        $cacheKey = $webhookId ? "shopify:webhook:{$webhookId}" : null;

        if ($cacheKey && Cache::has($cacheKey)) {
            return response()->json(['status' => 'duplicate_ignored']);
        }

        $response = $next($request);

        // Marked processed only after the handler actually completes without
        // throwing - caching this beforehand would mean a retry of a request
        // that failed on its first attempt gets silently swallowed here
        // forever instead of ever actually being processed.
        if ($cacheKey) {
            Cache::put($cacheKey, true, now()->addHours(24));
        }

        return $response;
    }
}
