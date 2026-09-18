<?php

namespace App\Services\Ai;

use App\Models\AuditIssue;

/**
 * Default binding for AiProviderInterface until a real provider key is added
 * (config('speedpilot.ai.provider') / AI_PROVIDER_API_KEY). Returns a templated,
 * still-useful recommendation from the issue's own category/title rather than
 * a generic API call, so Pro-tier "AI recommendations" aren't empty pre-launch.
 */
class PlaceholderAiProvider implements AiProviderInterface
{
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
