<?php

namespace App\Services\Scanner;

use App\Models\AppSetting;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

/**
 * PageSpeed Insights API wrapper for on-demand spot checks. Free-tier PSI keys
 * have low daily quotas, so scheduled/bulk monitoring must use the self-hosted
 * Playwright runner (ScannerClient) instead - this is reserved for one-off
 * merchant-triggered spot checks, rate-limited per shop per day.
 */
class PsiClient
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 30]);
    }

    public function underQuota(string $shopDomain): bool
    {
        $key = "psi_quota:{$shopDomain}:".now()->format('Y-m-d');
        $used = (int) Cache::get($key, 0);

        $override = AppSetting::get('psi_daily_quota', '');
        $quota = $override !== '' ? (int) $override : config('speedpilot.psi.daily_quota');

        return $used < $quota;
    }

    public function spotCheck(string $shopDomain, string $url): ?array
    {
        $apiKey = config('speedpilot.psi.api_key');

        if (! $apiKey) {
            return null; // no key configured yet - lab data from ScannerClient still covers the audit
        }

        $key = "psi_quota:{$shopDomain}:".now()->format('Y-m-d');
        Cache::add($key, 0, now()->endOfDay());
        Cache::increment($key);

        $response = $this->http->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', [
            'query' => [
                'url' => $url,
                'key' => $apiKey,
                'category' => 'PERFORMANCE',
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }
}
