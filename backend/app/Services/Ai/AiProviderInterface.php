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

    /**
     * Free-form "why is my product page slow" Q&A grounded in this scan's
     * real data (issues, app impacts, category scores) - the conversational
     * counterpart to prioritize()'s fixed plan.
     */
    public function ask(Audit $audit, string $question): string;
}
