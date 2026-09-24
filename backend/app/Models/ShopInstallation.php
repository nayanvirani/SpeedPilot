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
        'storefront_password',
        'target_theme_id',
        'target_theme_mode',
        'theme_write_blocked_at',
        'storefront_locked_at',
        'interceptor_token',
        'scan_frequency',
        'scan_devices',
        'slack_webhook_url',
        'scope',
        'plan',
        'shopify_subscription_id',
        'installed_at',
        'uninstalled_at',
    ];

    protected $hidden = [
        'access_token',
        'storefront_password',
        'slack_webhook_url',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => SafeEncrypted::class,
            'storefront_password' => SafeEncrypted::class,
            'slack_webhook_url' => SafeEncrypted::class,
            'access_token_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'theme_write_blocked_at' => 'datetime',
            'storefront_locked_at' => 'datetime',
        ];
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
