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
    public function scan(string $url, ?string $storefrontPassword = null): array
    {
        try {
            $response = $this->http->post('/scan', [
                'json' => array_filter([
                    'url' => $url,
                    'storefrontPassword' => $storefrontPassword,
                ]),
            ]);
        } catch (RequestException $e) {
            // The scanner returns a clean, merchant-readable message in its
            // JSON error body (e.g. "this store is password-protected") -
            // Guzzle's own exception message just dumps a truncated raw
            // response, which is far less useful surfaced in the UI.
            $body = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : null;

            throw new RuntimeException($body['message'] ?? "Scanner request failed for {$url}: ".$e->getMessage(), previous: $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
