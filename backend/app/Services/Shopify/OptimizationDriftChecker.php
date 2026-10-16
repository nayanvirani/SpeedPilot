<?php

namespace App\Services\Shopify;

use App\Models\ShopInstallation;

/**
 * Detects when a previously-applied fix no longer matches what's actually
 * live - a theme republish moved the merchant to different file content, or
 * someone edited the file directly and reverted the change. Never rewrites
 * anything itself; only flags it (reverted_at) so the Optimizations page can
 * offer the existing "Re-apply" button, matching this app's rule that
 * nothing above "safe, one click" ever writes without a merchant pressing
 * something.
 *
 * Checks every applied optimization against ITS OWN recorded theme_id, not
 * just whichever theme currently happens to be the shop's target - a real
 * live incident (confirmed 2026-10-16) showed an optimization applied to
 * theme A get silently edited back out after the fact, while the shop's
 * target theme had since moved on to theme B; the old restriction here
 * would never have looked at theme A again to notice. A separate, lighter
 * problem - theme_id no longer matching the CURRENT target at all (the
 * fix is fine, just not on the theme shown in the app) - is surfaced by
 * OptimizationController::index() instead, not here, since that's not
 * "drift" in this checker's sense and nothing here could fix it anyway.
 */
class OptimizationDriftChecker
{
    public function __construct(private readonly ThemeAssetService $themeAssets)
    {
    }

    /**
     * @return int how many optimizations were newly flagged as reverted
     */
    public function check(ShopInstallation $shop): int
    {
        $optimizations = $shop->optimizations()
            ->where('status', 'applied')
            ->whereNull('reverted_at')
            ->with(['backups' => fn ($q) => $q->whereNull('restored_at')->latest()])
            ->get();

        $flagged = 0;

        foreach ($optimizations as $optimization) {
            $backup = $optimization->backups->first();

            // No live (unrestored) backup to compare against - either
            // already rolled back through the normal path, or a legacy row
            // from before backups were tracked. Nothing to detect here.
            if (! $backup || $backup->updated_content === null) {
                continue;
            }

            $current = $this->themeAssets->read($optimization->theme_id, $optimization->asset_key);

            // A missing file (renamed/deleted section) is its own kind of
            // drift, same as mismatched content - both mean the fix isn't
            // actually in effect on the live theme anymore.
            if ($current === $backup->updated_content) {
                continue;
            }

            $optimization->update(['reverted_at' => now()]);

            // reapply() already expects to find its target via the most
            // recently restored backup - marking this one the same way a
            // real rollback would have means the existing Re-apply button
            // and its endpoint work unchanged, with no special-casing for
            // "reverted by drift" vs "rolled back by the merchant".
            $backup->update(['restored_at' => now()]);

            $flagged++;
        }

        return $flagged;
    }
}
