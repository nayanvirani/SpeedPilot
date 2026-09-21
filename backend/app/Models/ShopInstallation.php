<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'access_token' => 'encrypted',
            'storefront_password' => 'encrypted',
            'slack_webhook_url' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'theme_write_blocked_at' => 'datetime',
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

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'shop_installation_id');
    }

    public function latestAudit(): ?Audit
    {
        return $this->audits()->latest('id')->first();
    }

    public function isActive(): bool
    {
        return $this->uninstalled_at === null;
    }
}
