<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Same behavior as Laravel's built-in 'encrypted' cast, except a value that
 * fails to decrypt is treated as "not set" (null) instead of throwing -
 * found the hard way after a raw write left ShopInstallation.storefront_password
 * as an empty string (not null) for one shop, which the built-in cast tried
 * to decrypt on every read and crashed with, taking down GET /api/settings -
 * and therefore the whole Settings page - for that shop, silently, since
 * the frontend had no reason to expect that endpoint could 500. A single
 * corrupted or legacy-written value must never be able to take an entire
 * endpoint down again.
 */
class SafeEncrypted implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            Log::warning('Encrypted attribute failed to decrypt - treating as unset', [
                'model' => $model::class,
                'key' => $key,
                'id' => $model->getKey(),
            ]);

            return null;
        }
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : Crypt::encryptString($value);
    }
}
