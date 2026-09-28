<?php

namespace App\Services\Shopify;

use App\Models\AssetBackup;
use App\Models\Optimization;

/**
 * Every write to a live theme file goes through here first. This is the
 * "versioned-asset table" the spec calls out as underlying every safe-tier
 * fix - without it, "Rollback" would be a support ticket instead of a button.
 */
class AssetBackupService
{
    public function backup(
        Optimization $optimization,
        string $themeId,
        string $assetKey,
        string $originalContent,
        ?string $updatedContent = null,
    ): AssetBackup {
        return $optimization->backups()->create([
            'theme_id' => $themeId,
            'asset_key' => $assetKey,
            'original_content' => $originalContent,
            'updated_content' => $updatedContent,
            'checksum' => hash('sha256', $originalContent),
        ]);
    }

    public function restore(Optimization $optimization, ThemeAssetService $themeAssets): bool
    {
        $backup = $optimization->backups()->whereNull('restored_at')->latest()->first();

        if (! $backup) {
            return false;
        }

        $themeAssets->write($backup->theme_id, $backup->asset_key, $backup->original_content);

        $backup->update(['restored_at' => now()]);
        $optimization->update(['status' => 'rolled_back']);

        return true;
    }

    /**
     * The inverse of restore() - writes the same fixed content back after a
     * rollback, instead of making the merchant hunt down the original issue
     * on the audit page and click "Auto fix on theme" again to get the same
     * result. Reuses the most recently rolled-back backup row rather than
     * creating a new one, since nothing about the fix itself changed.
     */
    public function reapply(Optimization $optimization, ThemeAssetService $themeAssets): bool
    {
        $backup = $optimization->backups()->whereNotNull('restored_at')->latest('restored_at')->first();

        if (! $backup || $backup->updated_content === null) {
            return false;
        }

        $themeAssets->write($backup->theme_id, $backup->asset_key, $backup->updated_content);

        $backup->update(['restored_at' => null]);
        $optimization->update(['status' => 'applied', 'applied_at' => now()]);

        return true;
    }
}
