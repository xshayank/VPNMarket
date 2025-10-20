<?php

namespace Database\Factories;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ResellerConfig>
 */
class ResellerConfigFactory extends Factory
{
    protected $model = ResellerConfig::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reseller_id' => Reseller::factory(),
            'external_username' => fake()->unique()->userName(),
            'traffic_limit_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
            'usage_bytes' => fake()->numberBetween(0, 50) * 1024 * 1024 * 1024, // 0-50 GB
            'expires_at' => now()->addDays(30),
            'status' => fake()->randomElement(['active', 'disabled', 'expired']),
            'panel_type' => fake()->randomElement(['marzban', 'marzneshin', 'xui']),
            'panel_user_id' => fake()->userName(),
            'subscription_url' => fake()->url(),
            'panel_id' => null,
            'created_by' => \App\Models\User::factory(),
            'disabled_at' => null,
        ];
    }

    /**
     * Indicate that the config is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'disabled_at' => null,
        ]);
    }

    /**
     * Indicate that the config is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disabled',
            'disabled_at' => now(),
        ]);
    }

    /**
     * Indicate that the config is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'expires_at' => now()->subDays(1),
        ]);
    }
}
