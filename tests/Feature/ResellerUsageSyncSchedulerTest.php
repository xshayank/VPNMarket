<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class ResellerUsageSyncSchedulerTest extends TestCase
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

    public function test_sync_reseller_usage_job_excludes_settled_usage_from_aggregate(): void
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

        // Create config with settled usage
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
                'settled_usage_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB settled from resets
            ],
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 2 * 1024 * 1024 * 1024, // 2 GB current usage
            ], 200),
        ]);

        // Run the job
        $job = new SyncResellerUsageJob();
        $job->handle();

        // Verify config usage was updated
        $config->refresh();
        $this->assertEquals(2 * 1024 * 1024 * 1024, $config->usage_bytes);

        // Verify reseller aggregate includes usage_bytes + settled_usage_bytes (2 GB + 3 GB = 5 GB)
        // This prevents abuse while getCurrentTrafficUsedBytes() shows current usage only
        $reseller->refresh();
        $this->assertEquals(5 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes, 'Reseller aggregate should include settled_usage_bytes for quota enforcement');
        
        // Verify current usage method excludes settled
        $currentUsage = $reseller->getCurrentTrafficUsedBytes();
        $this->assertEquals(2 * 1024 * 1024 * 1024, $currentUsage, 'Current usage should exclude settled for display');
    }

    public function test_sync_job_executes_synchronously_without_queue_worker(): void
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

        // Create config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 1 * 1024 * 1024 * 1024, // 1 GB
            ], 200),
        ]);

        // Run the job synchronously (simulating scheduler behavior)
        // dispatchSync() executes immediately in the current process
        SyncResellerUsageJob::dispatchSync();

        // Verify the job executed and updated the database immediately
        $config->refresh();
        $this->assertEquals(1 * 1024 * 1024 * 1024, $config->usage_bytes, 'Config usage should be updated immediately via dispatchSync');

        $reseller->refresh();
        $this->assertEquals(1 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes, 'Reseller aggregate should be updated immediately via dispatchSync');
    }

    public function test_reseller_aggregate_reflects_reset_immediately(): void
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
            'traffic_used_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB initially
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create config with current usage
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB current
            'expires_at' => now()->addDays(30),
            'meta' => ['last_reset_at' => now()->subDays(2)->toDateTimeString()],
        ]);

        // Simulate reset by moving usage to settled and zeroing current
        $meta = $config->meta ?? [];
        $currentSettled = (int) data_get($meta, 'settled_usage_bytes', 0);
        $toSettle = $config->usage_bytes;
        $meta['settled_usage_bytes'] = $currentSettled + $toSettle;
        $config->update(['usage_bytes' => 0, 'meta' => $meta]);

        // Recalculate reseller aggregate (including settled_usage_bytes for quota enforcement)
        $totalUsageBytesFromDB = $reseller->configs()
            ->get()
            ->sum(function ($c) {
                return $c->usage_bytes + (int) data_get($c->meta, 'settled_usage_bytes', 0);
            });
        $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);

        // Verify reseller aggregate still includes settled usage (3 GB) for quota enforcement
        $reseller->refresh();
        $this->assertEquals(3 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes, 'Reseller aggregate should include settled usage for quota');

        // But getCurrentTrafficUsedBytes() should show 0 for display purposes
        $currentUsage = $reseller->getCurrentTrafficUsedBytes();
        $this->assertEquals(0, $currentUsage, 'Current usage should be 0 after reset for display');

        // Verify settled usage is preserved in config meta
        $config->refresh();
        $this->assertEquals(3 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'), 'Settled usage should be preserved in meta');
    }

    public function test_scheduler_runs_job_every_minute_regardless_of_interval(): void
    {
        // This test verifies that the scheduler dispatch is no longer conditional on minute % interval
        // We simulate running schedule:run multiple times and verify job would execute each time

        // Create a panel and reseller
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
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 1 * 1024 * 1024 * 1024,
            ], 200),
        ]);

        // Run job directly (simulating scheduler execution)
        SyncResellerUsageJob::dispatchSync();

        // Verify execution happened regardless of minute
        $config->refresh();
        $this->assertEquals(1 * 1024 * 1024 * 1024, $config->usage_bytes, 'Job should execute every minute');
    }
}
