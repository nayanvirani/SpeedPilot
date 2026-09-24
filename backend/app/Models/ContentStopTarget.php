<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The persistent record of which script URLs are currently neutralized by
 * the content_for_header `replace` block in a shop's theme.liquid - see
 * AppImpact's content_for_header_match for why this can't just live on
 * app_impacts rows (those are recreated fresh on every scan).
 */
class ContentStopTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_installation_id',
        'script_url',
        'source_snippet',
    ];

    public function shopInstallation(): BelongsTo
    {
        return $this->belongsTo(ShopInstallation::class);
    }
}
