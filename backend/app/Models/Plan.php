<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Plan extends Model
{
    protected $fillable = [
        'key', 'name', 'price', 'trial_days', 'script_rule_limit', 'auto_fix_limit',
        'history_days', 'auto_fixes', 'medium_risk_fixes', 'high_risk_recommendations',
        'ai_recommendations', 'monitoring', 'active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'auto_fixes' => 'boolean',
            'medium_risk_fixes' => 'boolean',
            'high_risk_recommendations' => 'boolean',
            'ai_recommendations' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public static function findByKey(?string $key): ?self
    {
        if (! $key) {
            return null;
        }

        return Cache::remember("plan:{$key}", now()->addMinutes(10), function () use ($key) {
            return static::where('key', $key)->first();
        });
    }

    protected static function booted(): void
    {
        static::saved(fn (self $plan) => Cache::forget("plan:{$plan->key}"));
        static::deleted(fn (self $plan) => Cache::forget("plan:{$plan->key}"));
    }
}
