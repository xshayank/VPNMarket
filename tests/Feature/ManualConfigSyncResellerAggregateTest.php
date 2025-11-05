<?php

namespace Tests\Feature;

use App\Console\Commands\SyncResellerConfigUsage;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ManualConfigSyncResellerAggregateTest extends TestCase
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

    public function test_manual_sync_command_updates_reseller_aggregate_immediately(): void
    {
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

        // Create a reseller with existing usage
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0, // Start at 0
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create a config with some existing usage
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'usage_bytes' => 500 * 1024 * 1024, // 500 MB existing
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses - user now has 1 GB used
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 1073741824, // 1 GB
                'data_limit' => 5368709120, // 5 GB
            ], 200),
        ]);

        // Run the manual sync command
        $this->artisan('reseller:usage:sync-one', ['--config' => $config->id])
            ->assertExitCode(0);

        // Assert the config usage was updated
        $config->refresh();
        $this->assertEquals(1073741824, $config->usage_bytes);

        // Assert reseller total usage was updated IMMEDIATELY (not 0)
        $reseller->refresh();
        $this->assertEquals(1073741824, $reseller->traffic_used_bytes, 'Reseller aggregate should be updated immediately after manual sync');
    }

    public function test_manual_sync_command_updates_aggregate_with_multiple_configs(): void
    {
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

        // Create a reseller
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create two configs
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB existing
            'expires_at' => now()->addDays(30),
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 500 * 1024 * 1024, // 500 MB existing
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses - config1 now has 2 GB
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser1' => Http::response([
                'username' => 'testuser1',
                'used_traffic' => 2 * 1024 * 1024 * 1024, // 2 GB
            ], 200),
        ]);

        // Run the manual sync command on config1
        $this->artisan('reseller:usage:sync-one', ['--config' => $config1->id])
            ->assertExitCode(0);

        // Assert config1 was updated
        $config1->refresh();
        $this->assertEquals(2 * 1024 * 1024 * 1024, $config1->usage_bytes);

        // Assert reseller aggregate includes both configs (2 GB + 500 MB = 2.5 GB)
        $reseller->refresh();
        $expectedTotal = (2 * 1024 * 1024 * 1024) + (500 * 1024 * 1024);
        $this->assertEquals($expectedTotal, $reseller->traffic_used_bytes, 'Reseller aggregate should sum all configs');
    }

    public function test_manual_sync_command_includes_settled_usage_in_aggregate(): void
    {
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

        // Create a reseller
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create a config with settled usage (from previous resets)
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 500 * 1024 * 1024, // 500 MB current
            'expires_at' => now()->addDays(30),
            'meta' => [
                'settled_usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB settled from resets
            ],
        ]);

        // Mock HTTP responses - user now has 1 GB used
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 1 * 1024 * 1024 * 1024, // 1 GB
            ], 200),
        ]);

        // Run the manual sync command
        $this->artisan('reseller:usage:sync-one', ['--config' => $config->id])
            ->assertExitCode(0);

        // Assert config usage was updated
        $config->refresh();
        $this->assertEquals(1 * 1024 * 1024 * 1024, $config->usage_bytes);

        // Assert reseller aggregate includes ONLY usage_bytes, NOT settled_usage_bytes (1 GB, not 3 GB)
        // This is the new behavior - settled usage is NOT counted toward reseller limit
        $reseller->refresh();
        $expectedTotal = 1 * 1024 * 1024 * 1024; // Only current usage, not settled
        $this->assertEquals($expectedTotal, $reseller->traffic_used_bytes, 'Reseller aggregate should NOT include settled usage');
    }

    public function test_manual_sync_command_handles_config_not_found(): void
    {
        // Run command with non-existent config ID
        $this->artisan('reseller:usage:sync-one', ['--config' => 99999])
            ->expectsOutput('Config with ID 99999 not found')
            ->assertExitCode(1);
    }

    public function test_manual_sync_command_requires_config_id(): void
    {
        // Run command without --config option
        $this->artisan('reseller:usage:sync-one')
            ->expectsOutput('Please provide a config ID using --config={id}')
            ->assertExitCode(1);
    }
}
