<?php

namespace App\Services\Monitoring;

use App\Models\Audit;
use App\Models\AuditIssue;

/**
 * The Free-plan upgrade hook: "your score is 55 - fixing what's safe to fix
 * could get you to ~75." A calibrated point estimate, not a real second
 * scan - unlike AppImpactController::projectRemoval() (which blocks a
 * specific app's URLs and re-measures for real), there's no equivalent
 * cheap way to simulate "as if every safe/medium fix were already applied"
 * without actually writing a preview theme and re-scanning it, which this
 * free, no-theme-access tier deliberately never does. The per-severity
 * point values below are a deliberately conservative guess at typical
 * Lighthouse performance-score impact, the same spirit as
 * RevenueImpactEstimator's calibration - always presented as an estimate,
 * never a guarantee, and capped well short of implying a perfect 100.
 */
class PotentialScoreEstimator
{
    private const POINTS_PER_SEVERITY = [
        'critical' => 7,
        'high' => 4,
        'medium' => 2,
        'low' => 1,
    ];

    private const MAX_GAIN = 35;

    /**
     * @return int|null  null when there's nothing fixable to project, or
     *                    the current score is already unknown/maxed out.
     */
    public function estimate(Audit $audit): ?int
    {
        if ($audit->score === null || $audit->score >= 100) {
            return null;
        }

        $fixableIssues = $audit->issues()
            ->whereIn('risk_tier', ['safe', 'medium'])
            ->get();

        if ($fixableIssues->isEmpty()) {
            return null;
        }

        $rawGain = $fixableIssues->sum(
            fn (AuditIssue $issue) => self::POINTS_PER_SEVERITY[$issue->severity] ?? 0
        );

        $gain = min($rawGain, self::MAX_GAIN, 100 - $audit->score);

        return $gain > 0 ? $audit->score + $gain : null;
    }
}
