<?php

namespace App\Services\Ai;

use App\Models\Audit;
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

    public function prioritize(Audit $audit): string
    {
        return $this->provider->prioritize($audit);
    }
}
