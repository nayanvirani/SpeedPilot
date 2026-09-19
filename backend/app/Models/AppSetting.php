<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Simple key-value store for the handful of app-wide settings that make
 * sense to change without a redeploy (maintenance mode, support contact,
 * PSI quota override). Everything else stays in config/speedpilot.php and
 * env vars - this table is deliberately small.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public const DEFAULTS = [
        'maintenance_mode' => '0',
        'support_email' => '',
        'psi_daily_quota' => '',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("app_setting:{$key}", function () use ($key, $default) {
            return static::query()->where('key', $key)->value('value') ?? $default;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("app_setting:{$key}");
    }

    public static function allSettings(): array
    {
        $stored = static::query()->pluck('value', 'key')->toArray();

        return array_merge(self::DEFAULTS, $stored);
    }
}
