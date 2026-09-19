<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\ShopInstallation>
 */
class ShopInstallationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shop_domain' => $this->faker->unique()->domainWord().'.myshopify.com',
            'access_token' => 'test-access-token',
            'scope' => 'read_themes,write_themes',
            'plan' => null,
            'installed_at' => now(),
        ];
    }
}
