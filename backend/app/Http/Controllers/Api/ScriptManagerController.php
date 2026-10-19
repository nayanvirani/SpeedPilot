<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScriptPageRule;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Smart Script Manager: per-app, per-page-type load control, additive to
 * the simple global Disable/Delay buttons already on the App & Script
 * Impact page - see ScriptPageRule's migration docblock and
 * InterceptorController::serve() for how a rule here actually changes what
 * loads on the storefront.
 *
 * Also covers the shop's own theme-owned JS/CSS (target_type=theme_asset),
 * not just installed apps - auditing a real store (jewel-nests) found real
 * render-blocking/unused weight sitting in theme files with no delay
 * control offered at all, only ever third-party apps. A theme asset is
 * identified by its own render-blocking/unused-JS/CSS issues that don't
 * match any known app (RunAuditJob already tags every js/css issue with
 * matched_app_impact_id when it does match one) - keyed by normalized
 * basename rather than the live URL for the same reason app_name was
 * chosen over script_url originally: a theme asset's CDN URL carries a
 * content hash that rotates on every deploy.
 */
class ScriptManagerController extends Controller
{
    private const PAGE_TYPES = ['home', 'product', 'collection', 'cart', 'search', 'blog', 'custom'];

    public function index(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $apps = $shop->latestAudit()?->appImpacts()->where('is_platform', false)->get(['app_name'])
            ->pluck('app_name')->unique()->values() ?? collect();

        return response()->json([
            'apps' => $apps,
            'theme_assets' => $this->themeAssetCandidates($shop),
            'page_types' => self::PAGE_TYPES,
            'triggers' => ScriptPageRule::TRIGGERS,
            'default_page_type' => ScriptPageRule::DEFAULT_PAGE_TYPE,
            'rules' => $shop->scriptPageRules()->orderBy('target_type')->orderBy('app_name')->orderBy('asset_identifier')->orderBy('page_type')->get(),
        ]);
    }

    public function upsert(Request $request)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if (! (new PlanPolicy($shop))->hasPaidPlan()) {
            return response()->json(['error' => 'Smart Script Manager is not available on the Free plan.'], 403);
        }

        $data = $request->validate([
            'target_type' => ['nullable', Rule::in(ScriptPageRule::TARGET_TYPES)],
            'app_name' => 'required_if:target_type,app|nullable|string',
            'asset_identifier' => 'required_if:target_type,theme_asset|nullable|string',
            'page_type' => ['required', Rule::in([...self::PAGE_TYPES, ScriptPageRule::DEFAULT_PAGE_TYPE])],
            'trigger' => ['required', Rule::in(ScriptPageRule::TRIGGERS)],
            'delay_seconds' => 'nullable|integer|min:1|max:60',
        ]);

        $targetType = $data['target_type'] ?? ScriptPageRule::TARGET_APP;

        $rule = $shop->scriptPageRules()->updateOrCreate(
            [
                'target_type' => $targetType,
                'app_name' => $targetType === ScriptPageRule::TARGET_APP ? $data['app_name'] : null,
                'asset_identifier' => $targetType === ScriptPageRule::TARGET_THEME_ASSET ? $data['asset_identifier'] : null,
                'page_type' => $data['page_type'],
            ],
            ['trigger' => $data['trigger'], 'delay_seconds' => $data['delay_seconds'] ?? null],
        );

        return response()->json(['rule' => $rule]);
    }

    /**
     * Theme-owned JS/CSS worth offering a delay rule for - js/css issues
     * from the latest audit that never matched a known app, grouped by
     * normalized basename (one row per real file, not one per page it
     * showed up on). A handful of low-signal single-digit-byte issues
     * aren't worth surfacing as "manage this file's delay," so this is
     * capped to the heaviest few.
     *
     * @return array<int, array{identifier: string, label: string, requests: int, wasted_bytes: int, sample_url: ?string}>
     */
    private function themeAssetCandidates(ShopInstallation $shop): array
    {
        $audit = $shop->latestAudit();

        if (! $audit) {
            return [];
        }

        // JS only, deliberately not CSS - confirmed live on jewel-nests,
        // real damage: delaying a theme-owned stylesheet
        // (component-list-social.css) via this same mechanism left the
        // page rendering unstyled until the delay triggered, then visibly
        // snapping into its real layout - CLS 2.72, "poor" is 0.25.
        // Delaying a third-party app's own script loses that app's
        // functionality until it loads; delaying the theme's own CSS
        // breaks the page's fundamental visual structure in the meantime -
        // a categorically bigger risk that a script delay just doesn't
        // carry, so CSS isn't offered as a theme-asset candidate at all,
        // not even with a warning.
        $issues = $audit->issues()
            ->where('category', 'js')
            ->whereNull('meta->matched_app_impact_id')
            ->get();

        $byIdentifier = [];

        foreach ($issues as $issue) {
            $url = $issue->meta['url'] ?? $issue->meta['script_src'] ?? $issue->meta['css_url'] ?? null;

            // Lighthouse hands back a synthetic, non-URL identifier for CSS
            // with no real file (an inline <style> block) - confirmed live,
            // one showed up as literal CSS text ("_affirm-bom-shield{color:
            // inherit}…"). Nothing for the interceptor engine to watch
            // without a real src/href, so this isn't a usable candidate.
            if (! $url || ! preg_match('#^https?://#i', $url)) {
                continue;
            }

            // SpeedPilot's own storefront endpoints (Advanced Delay's
            // interceptor.js, instant-navigation's prefetch.js) - matched
            // by path, same exclusion domAnalyzer.js's isSpeedPilotAsset()
            // applies at detection time, repeated here as a second,
            // independent filter so a stale issue persisted from before
            // that fix (or any other future path this list is built from)
            // still can't surface "manage this file's delay" for the
            // app's own infrastructure script - offering a delay control
            // for code SpeedPilot itself depends on to EXIST makes no
            // sense regardless of why the issue is in the data.
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            if ($path === '/storefront/interceptor.js' || $path === '/storefront/prefetch.js') {
                continue;
            }

            $identifier = self::normalizedBasename($url);

            if (! $identifier) {
                continue;
            }

            $byIdentifier[$identifier] ??= ['identifier' => $identifier, 'requests' => 0, 'wasted_bytes' => 0, 'sample_url' => $url];
            $byIdentifier[$identifier]['requests']++;
            $byIdentifier[$identifier]['wasted_bytes'] += $issue->meta['evidence']['wasted_bytes'] ?? 0;
            $byIdentifier[$identifier]['sample_url'] ??= $url;
        }

        $candidates = array_values($byIdentifier);
        usort($candidates, fn ($a, $b) => $b['wasted_bytes'] <=> $a['wasted_bytes']);

        return array_slice(array_map(fn ($c) => [...$c, 'label' => $c['identifier']], $candidates), 0, 20);
    }

    /**
     * Mirrors the scanner's own normalizedBasename() (scanner/lib/
     * domAnalyzer.js) exactly, so a file this flags is matched by the same
     * identity on both ends - strips a trailing content hash/version from
     * the filename, case-insensitively, leaving a stable cross-deploy key.
     */
    public static function normalizedBasename(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $last = $path ? basename($path) : null;

        if (! $last) {
            return null;
        }

        $stripped = preg_replace('/[.-][a-f0-9]{6,}(?=\.[a-z]+$)/i', '', $last);
        $stripped = preg_replace('/[.-]v?\d+(\.\d+)*(?=\.[a-z]+$)/i', '', $stripped);

        return strtolower($stripped);
    }

    public function destroy(Request $request, int $id)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $shop->scriptPageRules()->where('id', $id)->delete();

        return response()->json([], 204);
    }
}
