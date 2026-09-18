<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptimizedTheme extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_installation_id',
        'duplicate_theme_id',
        'source_theme_id',
        'last_synced_at',
        'diverged',
        'divergence_meta',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'diverged' => 'boolean',
            'divergence_meta' => 'array',
        ];
    }

    public function shopInstallation(): BelongsTo
    {
        return $this->belongsTo(ShopInstallation::class);
    }
}
