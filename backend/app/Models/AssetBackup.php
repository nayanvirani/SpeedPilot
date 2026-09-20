<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetBackup extends Model
{
    use HasFactory;

    protected $fillable = [
        'optimization_id',
        'theme_id',
        'asset_key',
        'original_content',
        'updated_content',
        'checksum',
        'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'restored_at' => 'datetime',
        ];
    }

    public function optimization(): BelongsTo
    {
        return $this->belongsTo(Optimization::class);
    }
}
