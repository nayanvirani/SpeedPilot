<?php

namespace App\Services\Scanner;

use App\Exceptions\StorefrontPasswordException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * HTTP client for the /scanner Node microservice (Playwright + Lighthouse).
 * Kept as a separate service on purpose - Lighthouse/Chromium is Node-only,
 * the Laravel backend just orchestrates and stores results.
 */
class ScannerClient
{
    // The scanner proactively recycles its own process between scans once
    // memory climbs too high (confirmed live: it was genuinely crashing with
    // a Node heap-out-of-memory error, taking down whatever page was
    // in-flight - "Connection closed"/"Application failed to respond" for
    // the merchant, on every page unlucky enough to be scanning at that
    // moment). A brief connection failure right at that boundary is
    // therefore expected, self-resolving noise, not a real scan problem -
    // retrying after Railway's restart (a few seconds) is the fix, not
    // surfacing it as a failed page.
    private const MAX_ATTEMPTS = 3;

    private const RETRY_DELAY_SECONDS = 5;

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
    public function scan(string $url, ?string $storefrontPassword = null, string $device = 'mobile'): array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->http->post('/scan', [
                    'json' => array_filter([
                        'url' => $url,
                        'storefrontPassword' => $storefrontPassword,
                        'device' => $device,
                    ]),
                ]);

                return json_decode((string) $response->getBody(), true) ?? [];
            } catch (RequestException $e) {
                $body = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : null;

                if (($body['code'] ?? null) === 'PASSWORD_PROTECTED') {
                    // The scanner returns a clean, merchant-readable message
                    // in its JSON error body - Guzzle's own exception
                    // message just dumps a truncated raw response, far less
                    // useful surfaced in the UI.
                    throw new StorefrontPasswordException($body['message'], previous: $e);
                }

                // Either the scanner explicitly said it's mid-recycle
                // (code=RECYCLING, a clean response), or the connection
                // itself failed with no response at all (a crash/restart
                // mid-request - "Connection closed", the exact failure mode
                // confirmed live). Both are the same transient condition;
                // only these two get retried - a real scan failure (e.g.
                // Lighthouse itself erroring on a genuinely broken page)
                // still fails immediately, unchanged.
                $isTransient = ($body['code'] ?? null) === 'RECYCLING' || $e->getResponse() === null;

                if ($isTransient && $attempt < self::MAX_ATTEMPTS) {
                    Log::info('Scanner unavailable mid-recycle, retrying', ['url' => $url, 'attempt' => $attempt]);
                    sleep(self::RETRY_DELAY_SECONDS);

                    continue;
                }

                $message = $body['message'] ?? "Scanner request failed for {$url}: ".$e->getMessage();

                throw new RuntimeException($message, previous: $e);
            }
        }

        // Unreachable (the loop above always returns or throws), but keeps
        // static analysis happy about a guaranteed return type.
        throw new RuntimeException("Scanner request failed for {$url}: exhausted retries");
    }
}
