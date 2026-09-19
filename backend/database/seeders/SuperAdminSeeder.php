<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent on purpose (updateOrCreate) so it can run on every deploy via
 * preDeployCommand without creating duplicates or resetting the password
 * once the admin has changed it - only the very first run sets a password.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL');

        if (! $email) {
            Log::info('SuperAdminSeeder skipped: SUPER_ADMIN_EMAIL not set.');

            return;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update(['is_super_admin' => true]);

            return;
        }

        $password = env('SUPER_ADMIN_PASSWORD') ?? str()->random(24);

        User::create([
            'name' => 'Super Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'is_super_admin' => true,
        ]);
    }
}
