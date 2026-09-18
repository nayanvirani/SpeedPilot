<?php

namespace App\Services\Shopify;

class WebhookVerifier
{
    /**
     * Webhook HMAC is base64 HMAC-SHA256 over the raw request body - never the
     * parsed/re-encoded payload, since re-serializing JSON can change byte order
     * and silently break verification.
     */
    public static function isValid(string $rawBody, string $hmacHeader): bool
    {
        $computed = base64_encode(hash_hmac('sha256', $rawBody, config('shopify.api_secret'), true));

        return hash_equals($computed, $hmacHeader);
    }
}
