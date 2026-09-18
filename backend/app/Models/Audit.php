<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Audit extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_installation_id',
        'score',
        'lcp',
        'inp',
        'cls',
        'fcp',
        'ttfb',
        'page_weight_bytes',
        'js_weight_bytes',
        'css_weight_bytes',
        'source',
        'raw_report',
        'status',
        'url',
    ];

    protected function casts(): array
    {
        return [
            'raw_report' => 'array',
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

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }
}
