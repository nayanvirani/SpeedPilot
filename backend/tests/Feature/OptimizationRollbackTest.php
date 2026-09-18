<?php

namespace Tests\Feature;

use App\Models\ShopInstallation;
use App\Services\Shopify\AssetBackupService;
use App\Services\Shopify\ThemeAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Safety-critical path #2: rolling back a safe-tier fix must restore the
 * exact original file content and mark the optimization/backup accordingly -
 * this is what makes "Rollback" a single action instead of a support ticket.
 */
class OptimizationRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_restores_original_content_and_marks_backup_restored(): void
    {
        $shop = ShopInstallation::factory()->create();

        $optimization = $shop->optimizations()->create([
            'type' => 'image_dimensions',
            'risk_tier' => 'safe',
            'status' => 'applied',
            'theme_id' => '123456789',
            'asset_key' => 'sections/hero.liquid',
            'applied_at' => now(),
        ]);

        $originalContent = '<img src="hero.jpg">';

        $backupService = new AssetBackupService;
        $backup = $backupService->backup($optimization, '123456789', 'sections/hero.liquid', $originalContent);

        $themeAssets = Mockery::mock(ThemeAssetService::class);
        $themeAssets->shouldReceive('write')
            ->once()
            ->with('123456789', 'sections/hero.liquid', $originalContent);

        $restored = $backupService->restore($optimization, $themeAssets);

        $this->assertTrue($restored);
        $this->assertSame('rolled_back', $optimization->refresh()->status);
        $this->assertNotNull($backup->refresh()->restored_at);
    }

    public function test_rollback_returns_false_when_nothing_to_restore(): void
    {
        $shop = ShopInstallation::factory()->create();

        $optimization = $shop->optimizations()->create([
            'type' => 'image_dimensions',
            'risk_tier' => 'safe',
            'status' => 'rolled_back',
        ]);

        $themeAssets = Mockery::mock(ThemeAssetService::class);
        $themeAssets->shouldNotReceive('write');

        $restored = (new AssetBackupService)->restore($optimization, $themeAssets);

        $this->assertFalse($restored);
    }
}
