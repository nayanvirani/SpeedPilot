<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShopInstallation extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_domain',
        'access_token',
        'access_token_expires_at',
        'refresh_token',
        'refresh_token_expires_at',
        'storefront_password',
        'target_theme_id',
        'target_theme_mode',
        'theme_write_blocked_at',
        'needs_reauth_at',
        'storefront_locked_at',
        'interceptor_token',
        'interceptor_delay_ms',
        'interceptor_trigger',
        'scan_frequency',
        'scan_devices',
        'slack_webhook_url',
        'avg_order_value',
        'monthly_orders',
        'speed_budget_lcp_seconds',
        'speed_budget_breached_at',
        'scope',
        'plan',
        'plan_expires_at',
        'shopify_subscription_id',
        'installed_at',
        'uninstalled_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'storefront_password',
        'slack_webhook_url',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => SafeEncrypted::class,
            'refresh_token' => SafeEncrypted::class,
            'storefront_password' => SafeEncrypted::class,
            'slack_webhook_url' => SafeEncrypted::class,
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'theme_write_blocked_at' => 'datetime',
            'needs_reauth_at' => 'datetime',
            'storefront_locked_at' => 'datetime',
            'interceptor_delay_ms' => 'integer',
            'plan_expires_at' => 'datetime',
            'avg_order_value' => 'float',
            'monthly_orders' => 'integer',
            'speed_budget_lcp_seconds' => 'float',
            'speed_budget_breached_at' => 'datetime',
        ];
    }

    /**
     * A cancelled subscription doesn't cut a merchant off immediately -
     * they already paid for the current billing period, so access lasts
     * until plan_expires_at regardless of whether Shopify currently
     * reports the subscription as active or cancelled. Renewing simply
     * pushes plan_expires_at forward again the next time a sync runs; no
     * separate "reactivate" handling needed anywhere.
     *
     * A set plan with an unknown (null) expiry counts as having access,
     * not as expired - this is what a currently-paying shop looks like the
     * moment plan_expires_at is introduced (nothing has backfilled it yet)
     * or if a sync ever legitimately can't determine a period end. Only an
     * expiry date that's actually in the past ever revokes access; the
     * absence of one never does.
     *
     * An explicit, env-configured allowlist (config('shopify.test_shops'))
     * of our own test/dev stores always passes, independent of any of the
     * above - exists because Shopify Managed Pricing itself can get stuck
     * (confirmed live: confirming a plan creates no subscription and fires
     * no webhook for a specific shop+app pairing), which must never be able
     * to block testing the app we're building. A real shop's access always
     * still depends on a real subscription.
     */
    public function hasPlanAccess(): bool
    {
        if (in_array($this->shop_domain, config('shopify.test_shops'), true)) {
            return true;
        }

        return $this->plan !== null
            && ($this->plan_expires_at === null || $this->plan_expires_at->isFuture());
    }

    public function needsFreshAccessToken(): bool
    {
        // A missing expires_at is never "fine forever" - both token-issuance
        // paths now always request an expiring token, so a null here means
        // a stale row from before that fix (a deprecated permanent token),
        // not a token that legitimately doesn't expire.
        return ! $this->access_token
            || ! $this->access_token_expires_at
            || $this->access_token_expires_at->isPast();
    }

    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class);
    }

    public function optimizations(): HasMany
    {
        return $this->hasMany(Optimization::class);
    }

    public function optimizedTheme(): HasMany
    {
        return $this->hasMany(OptimizedTheme::class);
    }

    public function rumEvents(): HasMany
    {
        return $this->hasMany(RumEvent::class);
    }

    public function monitoringRuns(): HasMany
    {
        return $this->hasMany(MonitoringRun::class);
    }

    public function scriptRules(): HasMany
    {
        return $this->hasMany(ScriptRule::class);
    }

    public function interceptorDelayTargets(): HasMany
    {
        return $this->hasMany(InterceptorDelayTarget::class);
    }

    public function contentStopTargets(): HasMany
    {
        return $this->hasMany(ContentStopTarget::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'shop_installation_id');
    }

    public function latestAudit(): ?Audit
    {
        return $this->audits()->latest('id')->first();
    }

    /**
     * The single guard every scan-dispatch site (manual "Scan My Store",
     * auto-scan-on-install, the themes/publish webhook, and scheduled
     * monitoring) should call before creating a new audit - a shop can only
     * ever have one scan in flight at a time. Without this, an install-time
     * webhook and the auto-first-scan can race and dispatch two concurrent
     * RunAuditJob runs for the same shop, each competing for the same
     * scanner resources and corrupting each other's results.
     */
    public function hasAuditInProgress(): bool
    {
        return $this->audits()->whereIn('status', ['pending', 'running'])->exists();
    }

    /**
     * Opaque identifier the theme.liquid interceptor tag uses to fetch its
     * shop-specific script from /storefront/interceptor.js - an anonymous
     * storefront visitor's browser requests that URL directly, so this is
     * effectively public; using an opaque token instead of the shop domain
     * just stops trivial enumeration of another shop's delay list.
     */
    public function interceptorToken(): string
    {
        if (! $this->interceptor_token) {
            $this->update(['interceptor_token' => Str::random(40)]);
        }

        return $this->interceptor_token;
    }

    public function isActive(): bool
    {
        return $this->uninstalled_at === null;
    }
}
