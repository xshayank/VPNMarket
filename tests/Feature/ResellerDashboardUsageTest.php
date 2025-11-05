<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class ResellerDashboardUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Log to avoid output during tests
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('notice')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
    }

    public function test_dashboard_displays_updated_traffic_usage_after_sync(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Create a panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Create a traffic-based reseller
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create two configs with different usage
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses for both users
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser1' => Http::response([
                'username' => 'testuser1',
                'used_traffic' => 1073741824, // 1 GB
            ], 200),
            '*/api/users/testuser2' => Http::response([
                'username' => 'testuser2',
                'used_traffic' => 2147483648, // 2 GB
            ], 200),
        ]);

        // Run the sync job
        $job = new SyncResellerUsageJob;
        $job->handle();

        // Refresh configs to verify they were updated
        $config1->refresh();
        $config2->refresh();
        $this->assertEquals(1073741824, $config1->usage_bytes);
        $this->assertEquals(2147483648, $config2->usage_bytes);

        // Verify reseller traffic_used_bytes was updated
        $reseller->refresh();
        $expectedTotalBytes = 1073741824 + 2147483648; // 3 GB
        $this->assertEquals($expectedTotalBytes, $reseller->traffic_used_bytes);

        // Now access the dashboard as the reseller user
        $response = $this->actingAs($user)->get(route('reseller.dashboard'));

        $response->assertStatus(200);
        $response->assertViewHas('stats');

        $stats = $response->viewData('stats');

        // Verify the dashboard stats show the correct traffic usage
        $expectedUsageGb = round($expectedTotalBytes / (1024 * 1024 * 1024), 2); // 3.00 GB
        $this->assertEquals($expectedTotalBytes, $stats['traffic_consumed_bytes']);
        $this->assertEquals(10.0, $stats['traffic_total_gb']); // 10 GB total
        $this->assertEquals(7.0, $stats['traffic_remaining_gb']); // 7 GB remaining
    }

    public function test_dashboard_includes_settled_usage_bytes_in_total(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Create a panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Create a traffic-based reseller
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create a config with settled usage
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'meta' => [
                'settled_usage_bytes' => 1073741824, // 1 GB settled
            ],
        ]);

        // Mock HTTP response
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 536870912, // 512 MB current usage
            ], 200),
        ]);

        // Run the sync job
        $job = new SyncResellerUsageJob;
        $job->handle();

        // Refresh config
        $config->refresh();
        $this->assertEquals(536870912, $config->usage_bytes);

        // Verify reseller traffic_used_bytes includes settled usage
        $reseller->refresh();
        $expectedTotalBytes = 536870912 + 1073741824; // 512 MB + 1 GB = 1.5 GB
        $this->assertEquals($expectedTotalBytes, $reseller->traffic_used_bytes);

        // Access the dashboard
        $response = $this->actingAs($user)->get(route('reseller.dashboard'));

        $response->assertStatus(200);
        $stats = $response->viewData('stats');

        // Verify the dashboard includes settled usage
        $expectedUsageGb = round($expectedTotalBytes / (1024 * 1024 * 1024), 2); // 1.5 GB
        $this->assertEquals($expectedTotalBytes, $stats['traffic_consumed_bytes']);
    }

    public function test_dashboard_shows_zero_traffic_for_new_reseller(): void
    {
        // Create a user
        $user = User::factory()->create();

        // Create a new traffic-based reseller with no configs
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Access the dashboard
        $response = $this->actingAs($user)->get(route('reseller.dashboard'));

        $response->assertStatus(200);
        $stats = $response->viewData('stats');

        // Verify the dashboard shows zero usage
        $this->assertEquals(0, $stats['traffic_consumed_bytes']);
        $this->assertEquals(10.0, $stats['traffic_total_gb']);
        $this->assertEquals(10.0, $stats['traffic_remaining_gb']);
    }
}
