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
        if (! $shop->target_theme_id) {
            return 0;
        }

        $optimizations = $shop->optimizations()
            ->where('status', 'applied')
            ->where('theme_id', $shop->target_theme_id)
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
