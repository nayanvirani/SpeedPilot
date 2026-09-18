<?php

namespace App\Services\Ai;

use App\Models\AuditIssue;

class AiRecommendationService
{
    public function __construct(private readonly AiProviderInterface $provider)
    {
    }

    public function recommend(AuditIssue $issue): string
    {
        return $this->provider->recommend($issue);
    }
}
