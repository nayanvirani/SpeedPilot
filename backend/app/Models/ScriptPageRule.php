<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptPageRule extends Model
{
    public const DEFAULT_PAGE_TYPE = 'default';

    public const TRIGGERS = ['never', 'immediate', 'interaction', 'timeout', 'scroll'];

    protected $fillable = [
        'shop_installation_id',
        'app_name',
        'page_type',
        'trigger',
        'delay_seconds',
    ];

    public function shopInstallation(): BelongsTo
    {
        return $this->belongsTo(ShopInstallation::class);
    }
}
