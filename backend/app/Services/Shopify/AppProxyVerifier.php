<?php

namespace App\Services\Shopify;

class AppProxyVerifier
{
    /**
     * App Proxy's signature algorithm is NOT the same as webhook HMAC
     * (WebhookVerifier): exclude 'signature' itself, join each remaining
     * key=value pair with NO delimiter (not '&'), sort by key, HMAC-SHA256
     * with the app's client secret, compare as HEX (not base64).
     *
     * @param  array<string, mixed>  $query
     */
    public static function isValid(array $query): bool
    {
        if (! isset($query['signature'])) {
            return false;
        }

        $signature = $query['signature'];
        unset($query['signature']);

        ksort($query);

        $pairs = [];
        foreach ($query as $key => $value) {
            $value = is_array($value) ? implode(',', $value) : (string) $value;
            $pairs[] = "{$key}={$value}";
        }

        $computed = hash_hmac('sha256', implode('', $pairs), config('shopify.api_secret'));

        return hash_equals($computed, (string) $signature);
    }
}
