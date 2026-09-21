<?php

namespace App\Services\Ai;

use App\Models\Audit;
use App\Models\AuditIssue;

interface AiProviderInterface
{
    public function recommend(AuditIssue $issue): string;

    /**
     * Spec 4.17: prioritize this scan's issues and recommend an optimization
     * order, using the actual scan data as context - not a per-issue
     * recommendation, a scan-wide plan.
     */
    public function prioritize(Audit $audit): string;
}
