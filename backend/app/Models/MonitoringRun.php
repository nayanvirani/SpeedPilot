<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_installation_id',
        'audit_id',
        'run_at',
        'trend_delta',
    ];

    protected function casts(): array
    {
        return [
            'run_at' => 'datetime',
        ];
    }

    public function shopInstallation(): BelongsTo
    {
        return $this->belongsTo(ShopInstallation::class);
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
