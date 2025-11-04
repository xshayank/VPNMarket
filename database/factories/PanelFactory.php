<?php

namespace Database\Factories;

use App\Models\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Panel>
 */
class PanelFactory extends Factory
{
    protected $model = Panel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'url' => fake()->url(),
            'panel_type' => fake()->randomElement(['marzban', 'marzneshin', 'xui', 'eylandoo']),
            'username' => fake()->userName(),
            'password' => fake()->password(),
            'api_token' => null,
            'extra' => [],
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the panel is of type marzneshin.
     */
    public function marzneshin(): static
    {
        return $this->state(fn (array $attributes) => [
            'panel_type' => 'marzneshin',
            'extra' => [
                'node_hostname' => 'https://node.example.com',
            ],
        ]);
    }

    /**
     * Indicate that the panel is of type marzban.
     */
    public function marzban(): static
    {
        return $this->state(fn (array $attributes) => [
            'panel_type' => 'marzban',
            'extra' => [
                'node_hostname' => 'https://node.example.com',
            ],
        ]);
    }

    /**
     * Indicate that the panel is of type xui.
     */
    public function xui(): static
    {
        return $this->state(fn (array $attributes) => [
            'panel_type' => 'xui',
        ]);
    }

    /**
     * Indicate that the panel is of type eylandoo.
     */
    public function eylandoo(): static
    {
        return $this->state(fn (array $attributes) => [
            'panel_type' => 'eylandoo',
            'api_token' => fake()->uuid(),
            'extra' => [
                'node_hostname' => 'https://node.example.com',
            ],
        ]);
    }
}
