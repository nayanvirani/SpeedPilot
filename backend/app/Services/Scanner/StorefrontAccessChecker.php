<?php

namespace App\Services\Scanner;

use App\Models\ShopInstallation;
use GuzzleHttp\Client;
use Throwable;

/**
 * A cheap, pre-scan twin of the check lighthouseRunner.js already does
 * reactively (mid-Lighthouse-run, by inspecting the final URL after
 * Shopify's redirect). This runs *before* dispatching a scan at all, so a
 * password-protected store with no saved password never gets as far as
 * creating an audit that's doomed to fail on every single page - which is
 * exactly what happened during Shopify's own app review: the reviewer's
 * test store was password-protected, the app auto-scanned on install with
 * no password saved, and every page came back "failed" with no scores at
 * all on first load.
 */
class StorefrontAccessChecker
{
    /**
     * Fails open (returns false) on any network hiccup - this must never be
     * the reason a legitimate scan doesn't run.
     */
    public function isPasswordProtected(string $shopDomain): bool
    {
        try {
            $response = (new Client(['timeout' => 8]))->get("https://{$shopDomain}/", [
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
                return str_contains($response->getHeaderLine('Location'), '/password');
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The single guard every scan-dispatch site should call first. Only
     * actually makes the HTTP check when no password is saved yet - a
     * store with a saved password is trusted (the scanner's own unlock
     * flow will use it), so this never adds latency to the common case.
     * Persists storefront_locked_at either way, so the Dashboard reflects
     * the current state without re-checking on every page load.
     */
    public function blocksScan(ShopInstallation $shop): bool
    {
        if ($shop->storefront_password) {
            if ($shop->storefront_locked_at) {
                $shop->update(['storefront_locked_at' => null]);
            }

            return false;
        }

        $locked = $this->isPasswordProtected($shop->shop_domain);

        if ($locked && ! $shop->storefront_locked_at) {
            $shop->update(['storefront_locked_at' => now()]);
        } elseif (! $locked && $shop->storefront_locked_at) {
            $shop->update(['storefront_locked_at' => null]);
        }

        return $locked;
    }
}
