<?php

namespace Tests\Feature;

use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function non_reseller_cannot_access_reseller_dashboard()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reseller');

        $response->assertStatus(403);
    }

    /** @test */
    public function plan_based_reseller_can_access_dashboard()
    {
        $user = User::factory()->create();
        Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'plan',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->get('/reseller');

        $response->assertStatus(200);
        $response->assertViewIs('reseller::dashboard');
        $response->assertViewHas('reseller');
        $response->assertViewHas('stats');
    }

    /** @test */
    public function traffic_based_reseller_can_access_dashboard()
    {
        $user = User::factory()->create();
        Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'window_starts_at' => now(),
            'window_ends_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($user)->get('/reseller');

        $response->assertStatus(200);
        $response->assertViewIs('reseller::dashboard');
    }

    /** @test */
    public function suspended_reseller_cannot_access_dashboard()
    {
        $user = User::factory()->create();
        Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'plan',
            'status' => 'suspended',
        ]);

        $response = $this->actingAs($user)->get('/reseller');

        $response->assertStatus(403);
    }
}
