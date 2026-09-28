<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ApplySafeFixesJob;
use App\Jobs\RunAuditJob;
use App\Models\AppSetting;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\ShopInstallation;
use App\Services\PlanPolicy;
use App\Services\Scanner\StorefrontAccessChecker;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function store(Request $request, StorefrontAccessChecker $storefrontAccess)
    {
        if (AppSetting::get('maintenance_mode', '0') === '1') {
            return response()->json([
                'error' => 'SpeedPilot is undergoing maintenance. Please try again shortly.',
            ], 503);
        }

        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'url' => 'nullable|url',
        ]);

        // Refuse upfront rather than creating an audit that's guaranteed to
        // fail on every page - the reactive per-page check in the scanner
        // would produce the same "failed" result, just after wasting a full
        // scan run and showing the merchant a broken-looking dashboard.
        if (! ($data['url'] ?? null) && $storefrontAccess->blocksScan($shop)) {
            return response()->json([
                'error' => 'Your storefront is password-protected. Add your storefront password in '
                    .'Settings, then scan again.',
                'storefront_locked' => true,
            ], 422);
        }

        // A shop can only ever have one scan in flight - dispatching a
        // second one while the first is still running double-scans the same
        // storefront concurrently, which corrupts both runs' results (see
        // ShopInstallation::hasAuditInProgress()).
        if ($shop->hasAuditInProgress()) {
            return response()->json([
                'error' => 'A scan is already running - wait for it to finish before starting another.',
                'scan_in_progress' => true,
            ], 409);
        }

        // A null url means "full store scan" - RunAuditJob discovers which
        // pages to cover (plan-limited). An explicit url pins it to that one
        // page only, for spot-checking a specific page or a different
        // domain entirely (e.g. a password-protected dev store).
        $audit = $shop->audits()->create(['url' => $data['url'] ?? null, 'status' => 'pending']);

        RunAuditJob::dispatch($audit->id);

        return response()->json(['audit' => $audit], 202);
    }

    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        return response()->json([
            // The list view only ever renders the latest audit's summary -
            // pulling every page's full screenshot (a ~15KB base64 blob
            // each) for all 20 rows here would multiply a small response
            // into several MB for no reason. The full page data (including
            // screenshot) is what show() below is for.
            'audits' => $shop->audits()
                ->with(['pages:id,audit_id,page_type,url,device,score,status,error_message', 'issues'])
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $shop = $request->attributes->get('shop');

        $audit = Audit::where('shop_installation_id', $shop->id)
            ->with(['issues', 'appImpacts', 'pages', 'verifiesAudit:id,score', 'verificationAudit:id,score,verifies_audit_id,status'])
            ->findOrFail($id);

        return response()->json(['audit' => $audit]);
    }

    /**
     * Bulk "Fix Safe Issues" button on the audit page - applies every safe,
     * fixable issue on this audit in one go. Nothing here runs on its own;
     * a merchant has to click it. Runs synchronously so the merchant sees a
     * real result immediately rather than polling; maxIssues bounds how many
     * fixes one HTTP request can attempt (each is several sequential
     * Shopify API calls) so it can't run long enough to hit a gateway
     * timeout.
     */
    private const MAX_ISSUES_PER_REQUEST = 15;

    public function applySafeFixes(Request $request, int $id)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if (! (new PlanPolicy($shop))->canAutoFix()) {
            return response()->json(['error' => 'Automatic fixes are not available on your plan.'], 403);
        }

        $audit = Audit::where('shop_installation_id', $shop->id)->findOrFail($id);

        if (! $shop->target_theme_id) {
            return response()->json(['error' => 'Choose a target theme in Settings first.'], 422);
        }

        $issueIds = $audit->issues()->pluck('id');

        ApplySafeFixesJob::dispatchSync($shop->id, $audit->id, self::MAX_ISSUES_PER_REQUEST);

        $applied = $shop->optimizations()
            ->whereIn('audit_issue_id', $issueIds)
            ->where('status', 'applied')
            ->get(['id', 'type', 'asset_key']);

        return response()->json([
            'applied_count' => $applied->count(),
            'optimizations' => $applied,
        ]);
    }

    /**
     * The per-issue "Auto fix on theme" button - every safe, fixable issue
     * shows this right next to "Manual fix", and nothing is written unless
     * the merchant clicks it. Targets exactly this one issue via
     * ApplySafeFixesJob's onlyIssueId, so clicking one issue's button can
     * never apply a different one.
     */
    public function applySingleSafeFix(Request $request, int $issueId)
    {
        /** @var ShopInstallation $shop */
        $shop = $request->attributes->get('shop');

        if (! (new PlanPolicy($shop))->canAutoFix()) {
            return response()->json(['error' => 'Automatic fixes are not available on your plan.'], 403);
        }

        $issue = AuditIssue::whereHas('audit', fn ($q) => $q->where('shop_installation_id', $shop->id))
            ->where('risk_tier', 'safe')
            ->findOrFail($issueId);

        if (! $shop->target_theme_id) {
            return response()->json(['error' => 'Choose a target theme in Settings first.'], 422);
        }

        ApplySafeFixesJob::dispatchSync($shop->id, $issue->audit_id, onlyIssueId: $issue->id);

        $optimization = $shop->optimizations()
            ->where('audit_issue_id', $issue->id)
            ->where('status', 'applied')
            ->latest('applied_at')
            ->first(['id', 'type', 'asset_key']);

        if (! $optimization) {
            return response()->json([
                'error' => "Couldn't apply this automatically right now - use Manual fix instead, or Shopify's theme-write approval may still be pending.",
            ], 503);
        }

        return response()->json(['optimization' => $optimization]);
    }
}
