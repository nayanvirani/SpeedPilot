<?php

namespace App\Services\Ai;

use App\Models\AuditIssue;

interface AiProviderInterface
{
    public function recommend(AuditIssue $issue): string;
}
