<?php

namespace Tests\Feature;

use App\Jobs\ApplySafeFixesJob;
use App\Jobs\RunAuditJob;
use App\Models\ShopInstallation;
use App\Services\Scanner\PsiClient;
use App\Services\Scanner\ScannerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Safety-critical path #1: a scan must correctly persist score/CWV/issues and
 * only queue safe fixes for shops whose plan allows auto-fixing.
 */
class RunAuditJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_audit_metrics_issues_and_app_impacts(): void
    {
        Queue::fake();

        $shop = ShopInstallation::factory()->create(['plan' => 'pro']);

        $scanner = Mockery::mock(ScannerClient::class);
        $scanner->shouldReceive('scan')->once()->andReturn([
            'score' => 87,
            'metrics' => ['lcp' => 1.8, 'inp' => 120, 'cls' => 0.02, 'fcp' => 0.9, 'ttfb' => 0.3],
            'weight' => ['page_bytes' => 1_200_000, 'js_bytes' => 400_000, 'css_bytes' => 90_000],
            'issues' => [[
                'category' => 'image',
                'severity' => 'high',
                'title' => 'Missing width/height on hero image',
                'riskTier' => 'safe',
                'fixAvailable' => true,
                'meta' => ['asset_key' => 'sections/hero.liquid'],
            ]],
            'thirdParty' => [[
                'name' => 'Reviews App',
                'url' => 'https://reviews.example/widget.js',
                'requests' => 8,
                'bytes' => 420_000,
                'blockingMs' => 150,
            ]],
        ]);
        $this->app->instance(ScannerClient::class, $scanner);

        $psi = Mockery::mock(PsiClient::class);
        $psi->shouldReceive('underQuota')->andReturn(false);
        $this->app->instance(PsiClient::class, $psi);

        $audit = $shop->audits()->create(['url' => "https://{$shop->shop_domain}", 'status' => 'pending']);

        (new RunAuditJob($audit->id))->handle(
            $this->app->make(ScannerClient::class),
            $this->app->make(PsiClient::class),
        );

        $audit->refresh();

        $this->assertSame('complete', $audit->status);
        $this->assertSame(87, $audit->score);
        $this->assertCount(1, $audit->issues);
        $this->assertSame('safe', $audit->issues->first()->risk_tier);
        $this->assertCount(1, $audit->appImpacts);
        $this->assertSame('high', $audit->appImpacts->first()->impact_level);

        Queue::assertPushed(ApplySafeFixesJob::class);
    }

    public function test_it_does_not_queue_fixes_for_an_unsubscribed_shop(): void
    {
        Queue::fake();

        $shop = ShopInstallation::factory()->create(['plan' => null]);

        $scanner = Mockery::mock(ScannerClient::class);
        $scanner->shouldReceive('scan')->once()->andReturn([
            'score' => 60,
            'metrics' => [],
            'weight' => [],
            'issues' => [],
            'thirdParty' => [],
        ]);
        $this->app->instance(ScannerClient::class, $scanner);

        $psi = Mockery::mock(PsiClient::class);
        $psi->shouldReceive('underQuota')->andReturn(false);
        $this->app->instance(PsiClient::class, $psi);

        $audit = $shop->audits()->create(['url' => "https://{$shop->shop_domain}", 'status' => 'pending']);

        (new RunAuditJob($audit->id))->handle(
            $this->app->make(ScannerClient::class),
            $this->app->make(PsiClient::class),
        );

        Queue::assertNotPushed(ApplySafeFixesJob::class);
    }
}
