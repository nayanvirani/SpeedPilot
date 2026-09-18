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
    public function backup(Optimization $optimization, string $themeId, string $assetKey, string $originalContent): AssetBackup
    {
        return $optimization->backups()->create([
            'theme_id' => $themeId,
            'asset_key' => $assetKey,
            'original_content' => $originalContent,
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
}
