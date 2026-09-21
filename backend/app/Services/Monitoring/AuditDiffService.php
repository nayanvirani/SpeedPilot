<?php

namespace App\Services\Monitoring;

use App\Models\Audit;
use Illuminate\Support\Collection;

/**
 * Diffs two complete audits for the same shop to answer "what actually
 * changed since the last scan" - the substance behind a regression alert,
 * the Monitoring page's "What changed" card, and the monthly report.
 *
 * Only ever produces correlational data (a new script/issue coincided with
 * a score drop) - every consumer of this output must phrase findings as a
 * "possible contributor", never a confirmed cause. This service doesn't
 * enforce that in code (it's a copy/wording concern for callers), just
 * documents it here since it's easy to accidentally overclaim downstream.
 */
class AuditDiffService
{
    /**
     * @return array{
     *   score_delta: ?int,
     *   metrics: array<string, array{previous: ?float, current: ?float, delta: ?float}>,
     *   weights: array<string, array{previous: ?int, current: ?int, delta: ?int}>,
     *   new_third_party_scripts: array<int, array>,
     *   removed_third_party_scripts: array<int, array>,
     *   new_issues: array<int, array>,
     *   resolved_issues: array<int, array>,
     *   page_type_deltas: array<int, array{page_type: string, previous_score: ?int, current_score: ?int, delta: ?int}>,
     * }
     */
    public function diff(Audit $previous, Audit $current): array
    {
        $scripts = $this->scriptDiff($previous, $current);
        $issues = $this->issueDiff($previous, $current);

        return [
            'score_delta' => $this->intDelta($previous->score, $current->score),
            'metrics' => [
                'lcp' => $this->metricDelta($previous->lcp, $current->lcp),
                'inp' => $this->metricDelta($previous->inp, $current->inp),
                'cls' => $this->metricDelta($previous->cls, $current->cls),
                'fcp' => $this->metricDelta($previous->fcp, $current->fcp),
                'ttfb' => $this->metricDelta($previous->ttfb, $current->ttfb),
                'tbt' => $this->metricDelta($previous->tbt, $current->tbt),
                'speed_index' => $this->metricDelta($previous->speed_index, $current->speed_index),
            ],
            'weights' => [
                'page_weight_bytes' => $this->intPairDelta($previous->page_weight_bytes, $current->page_weight_bytes),
                'js_weight_bytes' => $this->intPairDelta($previous->js_weight_bytes, $current->js_weight_bytes),
                'css_weight_bytes' => $this->intPairDelta($previous->css_weight_bytes, $current->css_weight_bytes),
                'image_weight_bytes' => $this->intPairDelta($previous->image_weight_bytes, $current->image_weight_bytes),
                'request_count' => $this->intPairDelta($previous->request_count, $current->request_count),
            ],
            'new_third_party_scripts' => $scripts['new'],
            'removed_third_party_scripts' => $scripts['removed'],
            'new_issues' => $issues['new'],
            'resolved_issues' => $issues['resolved'],
            'page_type_deltas' => $this->pageTypeScoreDeltas($previous, $current),
        ];
    }

    /**
     * @return array{new: array<int, array>, removed: array<int, array>}
     */
    private function scriptDiff(Audit $previous, Audit $current): array
    {
        $key = fn ($impact) => $impact->script_url ?: $impact->app_name;

        // Platform scripts (cdn.shopify.com, shop.app, etc.) are Shopify's
        // own behavior, not a merchant-installed app - excluded the same way
        // App & Script Impact already excludes them, so they never trigger a
        // false "new third-party script" alert.
        //
        // ->toBase() matters here: appImpacts is an Eloquent\Collection, and
        // Eloquent\Collection::only() has entirely different semantics from
        // the base Collection's only() - it treats the argument as *model
        // primary keys*, silently ignoring the custom keyBy($key) below and
        // breaking this diff. Converting to a plain Support\Collection
        // before keyBy makes only() work the way this code actually needs.
        $prevByKey = $previous->appImpacts->reject(fn ($i) => $i->is_platform)->toBase()->keyBy($key);
        $currByKey = $current->appImpacts->reject(fn ($i) => $i->is_platform)->toBase()->keyBy($key);

        $summarize = fn ($impact) => [
            'app_name' => $impact->app_name,
            'script_url' => $impact->script_url,
            'size_bytes' => $impact->size_bytes,
            'estimated_blocking_ms' => $impact->estimated_blocking_ms,
            'impact_level' => $impact->impact_level,
        ];

        $newKeys = $currByKey->keys()->diff($prevByKey->keys());
        $removedKeys = $prevByKey->keys()->diff($currByKey->keys());

        return [
            'new' => $currByKey->only($newKeys)->map($summarize)->values()->all(),
            'removed' => $prevByKey->only($removedKeys)->map($summarize)->values()->all(),
        ];
    }

    /**
     * @return array{new: array<int, array>, resolved: array<int, array>}
     */
    private function issueDiff(Audit $previous, Audit $current): array
    {
        $key = fn ($issue) => $issue->category.'|'.$issue->title;

        // Same ->toBase() reasoning as scriptDiff() above - issues is also
        // an Eloquent\Collection.
        $prevByKey = $previous->issues->toBase()->keyBy($key);
        $currByKey = $current->issues->toBase()->keyBy($key);

        $summarize = fn ($issue) => [
            'category' => $issue->category,
            'title' => $issue->title,
            'severity' => $issue->severity,
            'risk_tier' => $issue->risk_tier,
        ];

        return [
            'new' => $currByKey->only($currByKey->keys()->diff($prevByKey->keys()))->map($summarize)->values()->all(),
            'resolved' => $prevByKey->only($prevByKey->keys()->diff($currByKey->keys()))->map($summarize)->values()->all(),
        ];
    }

    /**
     * @return array<int, array{page_type: string, previous_score: ?int, current_score: ?int, delta: ?int}>
     */
    private function pageTypeScoreDeltas(Audit $previous, Audit $current): array
    {
        $avg = function (Collection $pages) {
            $scores = $pages->pluck('score')->filter(fn ($s) => $s !== null);

            return $scores->count() > 0 ? $scores->avg() : null;
        };

        $prevByType = $previous->pages->groupBy('page_type')->map($avg);
        $currByType = $current->pages->groupBy('page_type')->map($avg);

        return $currByType->keys()->merge($prevByType->keys())->unique()
            ->map(function (string $type) use ($prevByType, $currByType) {
                $prev = $prevByType->get($type);
                $curr = $currByType->get($type);
                $prevScore = $prev !== null ? (int) round($prev) : null;
                $currScore = $curr !== null ? (int) round($curr) : null;

                return [
                    'page_type' => $type,
                    'previous_score' => $prevScore,
                    'current_score' => $currScore,
                    'delta' => ($prevScore !== null && $currScore !== null) ? $currScore - $prevScore : null,
                ];
            })
            ->values()
            ->all();
    }

    private function intDelta(?int $previous, ?int $current): ?int
    {
        return ($previous !== null && $current !== null) ? $current - $previous : null;
    }

    /**
     * @return array{previous: ?int, current: ?int, delta: ?int}
     */
    private function intPairDelta(?int $previous, ?int $current): array
    {
        return [
            'previous' => $previous,
            'current' => $current,
            'delta' => $this->intDelta($previous, $current),
        ];
    }

    /**
     * @return array{previous: ?float, current: ?float, delta: ?float}
     */
    private function metricDelta(mixed $previous, mixed $current): array
    {
        // Decimal columns come back from Eloquent as numeric strings (no
        // explicit cast on Audit) - cast before subtracting, same as
        // RunAuditJob does when building category scores.
        $previous = $previous !== null ? (float) $previous : null;
        $current = $current !== null ? (float) $current : null;

        return [
            'previous' => $previous,
            'current' => $current,
            'delta' => ($previous !== null && $current !== null) ? $current - $previous : null,
        ];
    }
}
