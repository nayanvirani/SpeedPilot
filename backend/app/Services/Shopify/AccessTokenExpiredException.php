<?php

namespace App\Services\Shopify;

use RuntimeException;

/**
 * Shopify's offline access tokens now expire (see ShopifyOAuthService's
 * `'expiring' => 1` requirement) and only get refreshed via Token Exchange,
 * which needs a live App Bridge session token - VerifyShopifySessionToken
 * does this automatically on every authenticated HTTP request, so a shop
 * stays fresh as long as the merchant opens the embedded app periodically.
 * A background job (the daily monitoring schedule, or any queued job with
 * no live request behind it) has no session token to exchange and can't
 * self-heal this the way a live request can - distinct from
 * ThemeWriteAccessDeniedException (a permanent, account-wide approval gate)
 * because this one resolves itself the next time the merchant opens the app,
 * with no action needed from us beyond not crashing in the meantime.
 */
class AccessTokenExpiredException extends RuntimeException
{
}
