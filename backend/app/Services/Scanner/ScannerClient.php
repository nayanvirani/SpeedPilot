<?php

namespace App\Services\Scanner;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * HTTP client for the /scanner Node microservice (Playwright + Lighthouse).
 * Kept as a separate service on purpose - Lighthouse/Chromium is Node-only,
 * the Laravel backend just orchestrates and stores results.
 */
class ScannerClient
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('speedpilot.scanner_url'),
            'timeout' => 120, // Lighthouse runs are slow; this is a queued job, not a request
        ]);
    }

    /**
     * @return array<string, mixed> Lighthouse scores, CWV, and resource breakdown.
     */
    public function scan(string $url): array
    {
        try {
            $response = $this->http->post('/scan', [
                'json' => ['url' => $url],
            ]);
        } catch (RequestException $e) {
            throw new RuntimeException("Scanner request failed for {$url}: ".$e->getMessage(), previous: $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
