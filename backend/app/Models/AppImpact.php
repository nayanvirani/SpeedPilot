<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppImpact extends Model
{
    use HasFactory;

    protected $fillable = [
        'audit_id',
        'audit_page_id',
        'app_name',
        'script_url',
        'requests',
        'size_bytes',
        'estimated_blocking_ms',
        'impact_level',
        'status',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
