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
        'scope',
        'plan',
        'shopify_subscription_id',
        'installed_at',
        'uninstalled_at',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
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

    public function latestAudit(): ?Audit
    {
        return $this->audits()->latest()->first();
    }

    public function isActive(): bool
    {
        return $this->uninstalled_at === null;
    }
}
