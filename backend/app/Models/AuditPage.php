<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditPage extends Model
{
    protected $fillable = [
        'audit_id',
        'page_type',
        'url',
        'score',
        'lcp',
        'inp',
        'cls',
        'fcp',
        'ttfb',
        'page_weight_bytes',
        'js_weight_bytes',
        'css_weight_bytes',
        'screenshot',
        'status',
        'error_message',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AuditIssue::class);
    }
}
