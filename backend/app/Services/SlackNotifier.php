<?php

namespace App\Services;

use App\Models\ShopInstallation;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Spec 4.20 notifications, Slack-only - Railway can't send outbound email,
 * and a merchant-configured incoming webhook needs no OAuth app review the
 * way a Slack app integration would. A failed or unconfigured webhook must
 * never break the job that triggered it (a monitoring run's only job is the
 * scan itself), so every send is fire-and-forget with errors swallowed.
 */
class SlackNotifier
{
    public function send(ShopInstallation $shop, string $text): void
    {
        if (! $shop->slack_webhook_url) {
            return;
        }

        try {
            (new Client(['timeout' => 5]))->post($shop->slack_webhook_url, [
                'json' => ['text' => $text],
            ]);
        } catch (Throwable $e) {
            Log::warning('Slack notification failed', [
                'shop' => $shop->shop_domain,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
