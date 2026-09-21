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
     * makes the live HTTP check when no password is saved yet - a store
     * with a saved password is trusted on the strength of the *last actual
     * scan attempt*, not re-verified here on every page load.
     *
     * That trust is conditional, though: RunAuditJob sets storefront_locked_at
     * whenever the scanner itself reports the saved password didn't unlock
     * the store (wrong password), and only clears it after a scan actually
     * completes. Until a real scan proves the saved password works, a
     * previously-flagged lock stays in force here too - otherwise a wrong
     * password would silently pass this guard, get "trusted", and dispatch
     * another doomed scan, which is exactly the failure mode that got this
     * app rejected in the first place.
     */
    public function blocksScan(ShopInstallation $shop): bool
    {
        if ($shop->storefront_password) {
            return (bool) $shop->storefront_locked_at;
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
