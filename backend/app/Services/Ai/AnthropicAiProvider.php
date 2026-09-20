<?php

namespace App\Services\Ai;

use Anthropic\Client;
use App\Models\AuditIssue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real AI recommendations, gated behind config('speedpilot.ai.provider') ===
 * 'anthropic' and an API key (see SpeedPilotServiceProvider). Falls back to
 * PlaceholderAiProvider's templated text on any failure - a rate limit or a
 * transient API error shouldn't turn a recommendation into an error page.
 */
class AnthropicAiProvider implements AiProviderInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly string $model,
    ) {
    }

    public function recommend(AuditIssue $issue): string
    {
        try {
            $message = $this->client->messages->create(
                model: $this->model,
                maxTokens: 300,
                system: 'You are a Shopify theme performance expert. Given one '
                    .'Lighthouse-detected performance issue, give a specific, actionable '
                    .'recommendation in 2-3 sentences aimed at a merchant, not a developer. '
                    .'No preamble, no markdown, no headers - plain sentences only.',
                messages: [
                    ['role' => 'user', 'content' => $this->promptFor($issue)],
                ],
            );

            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    return trim($block->text);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Anthropic recommendation failed, falling back to placeholder', [
                'issue_id' => $issue->id,
                'message' => $e->getMessage(),
            ]);
        }

        return (new PlaceholderAiProvider)->recommend($issue);
    }

    private function promptFor(AuditIssue $issue): string
    {
        return "Issue category: {$issue->category}\n"
            ."Title: {$issue->title}\n"
            .'Description: '.($issue->description ?? 'none provided')."\n"
            ."Severity: {$issue->severity}\n"
            ."Risk tier: {$issue->risk_tier}\n"
            .'Already eligible for an automatic safe fix: '.($issue->fix_available ? 'yes' : 'no');
    }
}
