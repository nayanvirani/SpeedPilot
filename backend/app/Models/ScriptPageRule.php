<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptPageRule extends Model
{
    public const DEFAULT_PAGE_TYPE = 'default';

    public const TRIGGERS = ['never', 'immediate', 'interaction', 'timeout', 'scroll'];

    public const TARGET_APP = 'app';

    public const TARGET_THEME_ASSET = 'theme_asset';

    public const TARGET_TYPES = [self::TARGET_APP, self::TARGET_THEME_ASSET];

    protected $fillable = [
        'shop_installation_id',
        'target_type',
        'app_name',
        'asset_identifier',
        'page_type',
        'trigger',
        'delay_seconds',
    ];

    public function shopInstallation(): BelongsTo
    {
        return $this->belongsTo(ShopInstallation::class);
    }
}
