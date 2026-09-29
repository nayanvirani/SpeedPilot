<?php

namespace App\Services\Shopify;

/**
 * A small, curated map from app name to functional category, used only to
 * flag when a merchant has two apps doing the same customer-facing job
 * (e.g. two review widgets) - not a general app taxonomy. Deliberately
 * excludes analytics/tracking apps (GTM + Facebook + Klaviyo running
 * together is normal, not redundant) and stays silent (returns null)
 * rather than guessing at anything not on this list - a false "these
 * overlap" flag on two genuinely different apps is worse than staying
 * quiet on an app we don't recognize.
 */
class AppCategoryClassifier
{
    /** @var array<string, array<int, string>> */
    private const CATEGORIES = [
        'reviews' => ['judge.me', 'loox', 'yotpo', 'stamped', 'okendo', 'ali reviews', 'rivyo', 'fera'],
        'chat/support' => ['tidio', 'gorgias', 'zendesk', 'reamaze', 'hellocall', 'livechat', 'tawk', 'chatra', 'gladly'],
        'popups/email-capture' => ['privy', 'justuno', 'optimonk', 'wisepops', 'adoric', 'poptin'],
        'loyalty/rewards' => ['smile.io', 'loyaltylion', 'yotpo loyalty', 'growave', 'rise.ai'],
        'upsell/cross-sell' => ['bold upsell', 'reconvert', 'zipify', 'honeycomb upsell', 'aftersell', 'vitals'],
        'search/filter' => ['searchanise', 'boost ai search', 'instant search', 'algolia', 'smart search'],
    ];

    public function classify(string $appName): ?string
    {
        $needle = strtolower($appName);

        foreach (self::CATEGORIES as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($needle, $pattern)) {
                    return $category;
                }
            }
        }

        return null;
    }
}
