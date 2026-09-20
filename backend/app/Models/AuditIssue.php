<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'audit_id',
        'audit_page_id',
        'category',
        'severity',
        'title',
        'description',
        'fix_available',
        'risk_tier',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'fix_available' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function auditPage(): BelongsTo
    {
        return $this->belongsTo(AuditPage::class);
    }

    public function optimizations(): HasMany
    {
        return $this->hasMany(Optimization::class);
    }

    public function isSafe(): bool
    {
        return $this->risk_tier === 'safe';
    }
}
