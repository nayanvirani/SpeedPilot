<?php

namespace App\Services\Ai;

use Anthropic\Client;
use App\Models\Audit;
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

    public function prioritize(Audit $audit): string
    {
        $issues = $audit->issues()->get();

        if ($issues->isEmpty()) {
            return 'No issues were found on this scan - nothing to prioritize.';
        }

        try {
            $message = $this->client->messages->create(
                model: $this->model,
                maxTokens: 400,
                system: 'You are a Shopify theme performance expert. Given a list of performance '
                    .'issues found on one store scan, recommend which to fix first and in what order, '
                    .'for a merchant, not a developer. Weigh severity, whether a fix is already '
                    .'available, and whether fixing one issue is likely to help others. Give a short '
                    .'numbered list (5 items max) with a one-sentence reason each. No preamble, no '
                    .'markdown headers - a plain numbered list only.',
                messages: [
                    ['role' => 'user', 'content' => $this->planPromptFor($issues)],
                ],
            );

            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    return trim($block->text);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Anthropic prioritization failed, falling back to placeholder', [
                'audit_id' => $audit->id,
                'message' => $e->getMessage(),
            ]);
        }

        return (new PlaceholderAiProvider)->prioritize($audit);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AuditIssue>  $issues
     */
    private function planPromptFor($issues): string
    {
        $lines = $issues->map(fn (AuditIssue $i) => sprintf(
            '- [%s/%s] %s (category: %s, fix available: %s)',
            $i->severity,
            $i->risk_tier,
            $i->title,
            $i->category,
            $i->fix_available ? 'yes' : 'no',
        ));

        return "Issues found on this scan:\n".$lines->implode("\n");
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
