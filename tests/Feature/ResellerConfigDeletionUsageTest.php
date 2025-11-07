<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class ResellerConfigDeletionUsageTest extends TestCase
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

    public function test_deleting_config_preserves_reseller_traffic_usage(): void
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
            'traffic_total_bytes' => 20 * 1024 * 1024 * 1024, // 20 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create two configs
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'usage_bytes' => 12 * 1024 * 1024 * 1024, // 12 GB used (exceeds limit)
            'expires_at' => now()->addDays(30),
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user2',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'usage_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB used
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/user1' => Http::response([
                'username' => 'user1',
                'used_traffic' => 12 * 1024 * 1024 * 1024, // 12 GB
            ], 200),
            '*/api/users/user2' => Http::response([
                'username' => 'user2',
                'used_traffic' => 3 * 1024 * 1024 * 1024, // 3 GB
            ], 200),
        ]);

        // Run sync job to update reseller total
        $job = new SyncResellerUsageJob;
        $job->handle();

        // Verify reseller total is 15 GB (12 + 3)
        $reseller->refresh();
        $this->assertEquals(15 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);

        // Now delete config1 (which has 12 GB usage)
        Http::fake([
            '*/api/users/user1' => Http::response([], 200), // Mock delete response
        ]);

        $config1->update(['status' => 'deleted']);
        $config1->delete(); // Soft delete

        // Verify config1 is soft-deleted
        $this->assertSoftDeleted($config1);

        // Mock HTTP for remaining config
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/user2' => Http::response([
                'username' => 'user2',
                'used_traffic' => 3 * 1024 * 1024 * 1024, // 3 GB (unchanged)
            ], 200),
        ]);

        // Run sync job again
        $job = new SyncResellerUsageJob;
        $job->handle();

        // CRITICAL: Reseller total should STILL be 15 GB (12 GB from deleted config + 3 GB from active config)
        $reseller->refresh();
        $this->assertEquals(15 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);
        $this->assertEquals(15.0, round($reseller->traffic_used_bytes / (1024 * 1024 * 1024), 2));
    }

    public function test_deleting_config_with_settled_usage_preserves_total(): void
    {
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 50 * 1024 * 1024 * 1024, // 50 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create config with both current and settled usage
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'active',
            'traffic_limit_bytes' => 20 * 1024 * 1024 * 1024,
            'usage_bytes' => 8 * 1024 * 1024 * 1024, // 8 GB current
            'expires_at' => now()->addDays(30),
            'meta' => [
                'settled_usage_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB settled
            ],
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/user1' => Http::response([
                'username' => 'user1',
                'used_traffic' => 8 * 1024 * 1024 * 1024, // 8 GB
            ], 200),
        ]);

        // Run sync job
        $job = new SyncResellerUsageJob;
        $job->handle();

        // Verify total is 13 GB (8 current + 5 settled)
        $reseller->refresh();
        $this->assertEquals(13 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);

        // Delete the config
        Http::fake([
            '*/api/users/user1' => Http::response([], 200),
        ]);

        $config->update(['status' => 'deleted']);
        $config->delete();

        // Run sync job again (no active configs)
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        ]);

        $job = new SyncResellerUsageJob;
        $job->handle();

        // CRITICAL: Total should STILL be 13 GB even though config is deleted
        $reseller->refresh();
        $this->assertEquals(13 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);
    }

    public function test_multiple_config_deletions_preserve_cumulative_usage(): void
    {
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create 3 configs
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'active',
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'usage_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'expires_at' => now()->addDays(30),
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user2',
            'status' => 'active',
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'usage_bytes' => 7 * 1024 * 1024 * 1024, // 7 GB
            'expires_at' => now()->addDays(30),
        ]);

        $config3 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user3',
            'status' => 'active',
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'usage_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB
            'expires_at' => now()->addDays(30),
        ]);

        // Initial sync
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/user1' => Http::response(['used_traffic' => 5 * 1024 * 1024 * 1024], 200),
            '*/api/users/user2' => Http::response(['used_traffic' => 7 * 1024 * 1024 * 1024], 200),
            '*/api/users/user3' => Http::response(['used_traffic' => 3 * 1024 * 1024 * 1024], 200),
        ]);

        $job = new SyncResellerUsageJob;
        $job->handle();

        // Total: 15 GB
        $reseller->refresh();
        $this->assertEquals(15 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);

        // Delete config1 (5 GB)
        Http::fake(['*/api/users/user1' => Http::response([], 200)]);
        $config1->update(['status' => 'deleted']);
        $config1->delete();

        // Sync again with remaining configs
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/user2' => Http::response(['used_traffic' => 7 * 1024 * 1024 * 1024], 200),
            '*/api/users/user3' => Http::response(['used_traffic' => 3 * 1024 * 1024 * 1024], 200),
        ]);

        $job = new SyncResellerUsageJob;
        $job->handle();

        // Total should still be 15 GB (5 deleted + 7 + 3)
        $reseller->refresh();
        $this->assertEquals(15 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);

        // Delete config2 (7 GB)
        Http::fake(['*/api/users/user2' => Http::response([], 200)]);
        $config2->update(['status' => 'deleted']);
        $config2->delete();

        // Sync with only config3
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/user3' => Http::response(['used_traffic' => 3 * 1024 * 1024 * 1024], 200),
        ]);

        $job = new SyncResellerUsageJob;
        $job->handle();

        // Total should still be 15 GB (5 + 7 deleted + 3)
        $reseller->refresh();
        $this->assertEquals(15 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);

        // Delete config3 (3 GB)
        Http::fake(['*/api/users/user3' => Http::response([], 200)]);
        $config3->update(['status' => 'deleted']);
        $config3->delete();

        // Sync with no active configs
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        ]);

        $job = new SyncResellerUsageJob;
        $job->handle();

        // Total should STILL be 15 GB (all 3 deleted but usage preserved)
        $reseller->refresh();
        $this->assertEquals(15 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);
    }

    public function test_get_current_traffic_used_bytes_includes_deleted_configs(): void
    {
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 50 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
        ]);

        $activeConfig = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'status' => 'active',
            'usage_bytes' => 4 * 1024 * 1024 * 1024, // 4 GB
        ]);

        $deletedConfig = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'status' => 'deleted',
            'usage_bytes' => 6 * 1024 * 1024 * 1024, // 6 GB
        ]);
        $deletedConfig->delete(); // Soft delete

        // getCurrentTrafficUsedBytes should return 10 GB (4 active + 6 deleted)
        $this->assertEquals(10 * 1024 * 1024 * 1024, $reseller->getCurrentTrafficUsedBytes());
    }

    public function test_no_double_counting_after_deletion(): void
    {
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 50 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'user1',
            'status' => 'active',
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'usage_bytes' => 9 * 1024 * 1024 * 1024, // 9 GB
            'expires_at' => now()->addDays(30),
        ]);

        // Initial sync
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/user1' => Http::response(['used_traffic' => 9 * 1024 * 1024 * 1024], 200),
        ]);

        $job = new SyncResellerUsageJob;
        $job->handle();

        $reseller->refresh();
        $this->assertEquals(9 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);

        // Delete config
        Http::fake(['*/api/users/user1' => Http::response([], 200)]);
        $config->update(['status' => 'deleted']);
        $config->delete();

        // Run sync multiple times
        Http::fake(['*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200)]);

        for ($i = 0; $i < 5; $i++) {
            $job = new SyncResellerUsageJob;
            $job->handle();
        }

        // Total should still be exactly 9 GB (no double counting)
        $reseller->refresh();
        $this->assertEquals(9 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);
    }

    public function test_deleting_config_updates_usage_from_remaining_active_configs(): void
    {
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 50 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        $toBeDeletedConfig = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'deleted_user',
            'status' => 'active',
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'usage_bytes' => 8 * 1024 * 1024 * 1024, // 8 GB
            'expires_at' => now()->addDays(30),
        ]);

        $activeConfig = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'active_user',
            'status' => 'active',
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
            'expires_at' => now()->addDays(30),
        ]);

        // Manually set reseller total (simulating after sync job ran)
        $reseller->update(['traffic_used_bytes' => 10 * 1024 * 1024 * 1024]); // 8 + 2 = 10 GB

        // Manually update active config usage (simulating panel usage increase without sync job)
        $activeConfig->update(['usage_bytes' => 5 * 1024 * 1024 * 1024]); // Increased to 5 GB

        // Delete one config
        $toBeDeletedConfig->update(['status' => 'deleted']);
        $toBeDeletedConfig->delete();

        // The aggregation with withTrashed() should include deleted config
        $totalUsageBytesFromDB = $reseller->configs()
            ->withTrashed()
            ->get()
            ->sum(function ($config) {
                return $config->usage_bytes + (int) data_get($config->meta, 'settled_usage_bytes', 0);
            });

        // Should be 8 (deleted) + 5 (active) = 13 GB
        $this->assertEquals(13 * 1024 * 1024 * 1024, $totalUsageBytesFromDB);

        // Update reseller record (as sync job would do)
        $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);
        $reseller->refresh();
        
        // Verify total is 13 GB (8 GB from deleted + 5 GB from active)
        $this->assertEquals(13 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);
    }
}
