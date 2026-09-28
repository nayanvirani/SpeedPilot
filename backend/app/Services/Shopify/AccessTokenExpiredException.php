<?php

namespace App\Services\Shopify;

use RuntimeException;

/**
 * Shopify's offline access tokens now expire (see ShopifyOAuthService's
 * `'expiring' => 1` requirement) - RefreshExpiringAccessTokensJob renews
 * them proactively via the stored refresh_token on a schedule, independent
 * of whether the merchant opens the embedded app, so this should now be rare
 * (a token that expired in the gap between scheduled refreshes, or a shop
 * whose refresh_token itself already died - see that job for the 401 case).
 * Distinct from ThemeWriteAccessDeniedException (a permanent, account-wide
 * approval gate): this one self-heals on the next scheduled refresh tick, or
 * failing that, the next time the merchant opens the app re-provisions a
 * fresh token+refresh_token pair via VerifyShopifySessionToken.
 */
class AccessTokenExpiredException extends RuntimeException
{
}
