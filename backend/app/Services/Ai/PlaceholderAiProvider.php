<?php

namespace App\Services\Ai;

use App\Models\Audit;
use App\Models\AuditIssue;

/**
 * Default binding for AiProviderInterface until a real provider key is added
 * (config('speedpilot.ai.provider') / AI_PROVIDER_API_KEY). Returns a templated,
 * still-useful recommendation from the issue's own category/title rather than
 * a generic API call, so Pro-tier "AI recommendations" aren't empty pre-launch.
 */
class PlaceholderAiProvider implements AiProviderInterface
{
    private const SEVERITY_WEIGHT = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];

    public function prioritize(Audit $audit): string
    {
        $issues = $audit->issues()->get();

        if ($issues->isEmpty()) {
            return 'No issues were found on this scan - nothing to prioritize.';
        }

        $ranked = $issues->sortBy(fn (AuditIssue $i) => [
            -(self::SEVERITY_WEIGHT[$i->severity] ?? 0),
            $i->fix_available ? 0 : 1,
            $i->risk_tier === 'safe' ? 0 : ($i->risk_tier === 'medium' ? 1 : 2),
        ])->values()->take(5);

        $lines = $ranked->map(fn (AuditIssue $i, int $n) => sprintf(
            '%d. %s (%s severity%s)',
            $n + 1,
            $i->title,
            $i->severity,
            $i->fix_available ? ', fix available' : '',
        ));

        return "Recommended order, highest-impact and easiest wins first:\n".$lines->implode("\n");
    }

    public function recommend(AuditIssue $issue): string
    {
        return match ($issue->category) {
            'image' => "Compress and serve \"{$issue->title}\" via Shopify's CDN ".
                'image transforms (width/format params) rather than the original upload; '.
                'add explicit width/height to prevent layout shift.',
            'js' => "Defer or delay \"{$issue->title}\" until after first user interaction ".
                'if it is not required for above-the-fold rendering.',
            'css' => "Split \"{$issue->title}\" so only above-the-fold rules load render-blocking; ".
                'defer the rest.',
            'theme' => "Review \"{$issue->title}\" in the theme editor - this typically needs a ".
                'manual Liquid change rather than an automated fix.',
            'third_party' => "Consider delaying \"{$issue->title}\" until after page load, or ".
                'excluding it from pages where it is not needed.',
            default => "Review \"{$issue->title}\" - no automated recommendation available yet.",
        };
    }
}
