<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Audit extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_installation_id',
        'verifies_audit_id',
        'score',
        'category_scores',
        'lcp',
        'inp',
        'cls',
        'fcp',
        'ttfb',
        'tbt',
        'speed_index',
        'page_weight_bytes',
        'js_weight_bytes',
        'css_weight_bytes',
        'image_weight_bytes',
        'request_count',
        'source',
        'raw_report',
        'status',
        'url',
    ];

    protected function casts(): array
    {
        return [
            'raw_report' => 'array',
            'category_scores' => 'array',
        ];
    }

    public function shopInstallation(): BelongsTo
    {
        return $this->belongsTo(ShopInstallation::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AuditIssue::class);
    }

    public function appImpacts(): HasMany
    {
        return $this->hasMany(AppImpact::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(AuditPage::class);
    }

    /** The earlier audit this one is an automatic re-scan verification of, if any. */
    public function verifiesAudit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'verifies_audit_id');
    }

    /** The follow-up verification re-scan of this audit, if one was triggered. */
    public function verificationAudit(): HasOne
    {
        return $this->hasOne(self::class, 'verifies_audit_id');
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }
}
