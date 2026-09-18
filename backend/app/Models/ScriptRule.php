<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_installation_id',
        'script_pattern',
        'action',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function shopInstallation(): BelongsTo
    {
        return $this->belongsTo(ShopInstallation::class);
    }

    public function matches(string $scriptUrl): bool
    {
        return str_contains($scriptUrl, $this->script_pattern);
    }
}
