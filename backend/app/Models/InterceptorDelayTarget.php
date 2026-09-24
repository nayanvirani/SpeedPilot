<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The persistent record of which script URLs a shop's theme.liquid
 * interceptor snippet currently watches for - see AppImpact's delay_method
 * for why this can't just live on app_impacts rows (those are recreated
 * fresh on every scan).
 */
class InterceptorDelayTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_installation_id',
        'script_url',
    ];

    public function shopInstallation(): BelongsTo
    {
        return $this->belongsTo(ShopInstallation::class);
    }
}
