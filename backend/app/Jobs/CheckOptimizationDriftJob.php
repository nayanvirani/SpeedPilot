<?php

namespace App\Jobs;

use App\Models\ShopInstallation;
use App\Services\Shopify\OptimizationDriftChecker;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ThemeAssetService;
use App\Services\SlackNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Before this job existed, OptimizationDriftChecker only ever ran from
 * ThemesPublishController - so a direct edit to a theme file (via Shopify's
 * code editor, a developer's own deploy, a theme CLI push) that silently
 * undid a SpeedPilot fix was invisible until/unless that specific theme was
 * next published. Confirmed live 2026-10-16: a fix on an UNPUBLISHED theme
 * got edited back out and sat showing "applied" indefinitely, since nothing
 * about editing an unpublished theme ever fires a publish webhook. This
 * dispatches the same checker daily for every shop with theme-editing
 * access, independent of any webhook.
 */
class CheckOptimizationDriftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $shopInstallationId,
    ) {
    }

    public function handle(SlackNotifier $slack): void
    {
        $shop = ShopInstallation::find($this->shopInstallationId);

        if (! $shop || ! $shop->isActive() || ! $shop->target_theme_id) {
            return;
        }

        $checker = new OptimizationDriftChecker(
            new ThemeAssetService(new ShopifyGraphQLClient($shop->shop_domain, $shop->access_token))
        );

        $flagged = $checker->check($shop);

        if ($flagged > 0) {
            $slack->send($shop, sprintf(
                ':warning: SpeedPilot: %d applied fix%s look%s reverted since last checked - review and '
                    .'re-apply on the Optimizations page.',
                $flagged,
                $flagged === 1 ? '' : 'es',
                $flagged === 1 ? 's' : '',
            ));
        }
    }
}
