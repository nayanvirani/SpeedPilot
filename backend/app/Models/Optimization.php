<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Optimization extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_installation_id',
        'audit_issue_id',
        'app_impact_id',
        'type',
        'risk_tier',
        'status',
        'applied_at',
        'theme_id',
        'asset_key',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function shopInstallation(): BelongsTo
    {
        return $this->belongsTo(ShopInstallation::class);
    }

    public function auditIssue(): BelongsTo
    {
        return $this->belongsTo(AuditIssue::class);
    }

    public function appImpact(): BelongsTo
    {
        return $this->belongsTo(AppImpact::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(AssetBackup::class);
    }

    public function isRollbackable(): bool
    {
        return $this->status === 'applied' && $this->backups()->whereNull('restored_at')->exists();
    }
}
