<?php

namespace App\Models;

use App\Services\CodeSnippetExtractor;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    protected $appends = ['snippet'];

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

    /**
     * Trimmed to just the changed region (see CodeSnippetExtractor) - the
     * full original_content/updated_content columns stay available on the
     * model for anyone who wants the whole file, but the Optimizations page
     * shows this by default so a merchant applying a fix by hand isn't
     * hunting for the one changed line in an entire theme file.
     */
    protected function snippet(): Attribute
    {
        return Attribute::get(function () {
            if ($this->updated_content === null) {
                return null;
            }

            return CodeSnippetExtractor::extract($this->original_content, $this->updated_content);
        });
    }
}
