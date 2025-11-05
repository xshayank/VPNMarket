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

class EylandooUsageMetaPersistenceTest extends TestCase
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

    public function test_sync_job_persists_meta_fields_for_eylandoo_configs(): void
    {
        // Create an Eylandoo panel
        $panel = Panel::create([
            'name' => 'Eylandoo Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-key',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.eylandoo.example.com'],
        ]);

        // Create a reseller
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create an Eylandoo config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'meta' => [], // Empty meta initially
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/v1/users/testuser' => Http::response([
                'userInfo' => [
                    'username' => 'testuser',
                    'total_traffic_bytes' => 2147483648, // 2 GB
                    'is_active' => true,
                ],
            ], 200),
        ]);

        // Run the sync job
        $job = new SyncResellerUsageJob;
        $job->handle();

        // Assert the config usage was updated
        $config->refresh();
        $this->assertEquals(2147483648, $config->usage_bytes);

        // Assert meta fields were persisted
        $this->assertNotNull($config->meta);
        $this->assertArrayHasKey('used_traffic', $config->meta);
        $this->assertArrayHasKey('data_used', $config->meta);
        $this->assertEquals(2147483648, $config->meta['used_traffic']);
        $this->assertEquals(2147483648, $config->meta['data_used']);
    }

    public function test_sync_job_updates_reseller_aggregate_for_eylandoo(): void
    {
        $panel = Panel::create([
            'name' => 'Eylandoo Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-key',
            'is_active' => true,
            'extra' => [],
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0, // Initially 0
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create multiple Eylandoo configs
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'user1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'user2',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP - user1 uses 1 GB, user2 uses 2 GB
        Http::fake([
            '*/api/v1/users/user1' => Http::response([
                'userInfo' => [
                    'username' => 'user1',
                    'total_traffic_bytes' => 1073741824, // 1 GB
                ],
            ], 200),
            '*/api/v1/users/user2' => Http::response([
                'userInfo' => [
                    'username' => 'user2',
                    'total_traffic_bytes' => 2147483648, // 2 GB
                ],
            ], 200),
        ]);

        $job = new SyncResellerUsageJob;
        $job->handle();

        // Assert reseller aggregate is correct (1 GB + 2 GB = 3 GB)
        $reseller->refresh();
        $this->assertEquals(3221225472, $reseller->traffic_used_bytes);
    }

    public function test_sync_job_does_not_persist_meta_for_non_eylandoo_configs(): void
    {
        // Create a Marzneshin panel
        $panel = Panel::create([
            'name' => 'Marzneshin Panel',
            'url' => 'https://marzneshin.example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => ''],
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create a Marzneshin config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
            'meta' => null, // No meta initially
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 1073741824, // 1 GB
            ], 200),
        ]);

        $job = new SyncResellerUsageJob;
        $job->handle();

        // Assert usage was updated
        $config->refresh();
        $this->assertEquals(1073741824, $config->usage_bytes);

        // Assert meta was NOT updated (or doesn't have used_traffic/data_used)
        $meta = $config->meta ?? [];
        $this->assertArrayNotHasKey('used_traffic', $meta);
        $this->assertArrayNotHasKey('data_used', $meta);
    }

    public function test_reset_usage_zeros_meta_fields_for_eylandoo(): void
    {
        $user = User::factory()->create();
        
        $panel = Panel::create([
            'name' => 'Eylandoo Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-key',
            'is_active' => true,
            'extra' => [],
        ]);

        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2147483648, // 2 GB used
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2147483648, // 2 GB used
            'expires_at' => now()->addDays(30),
            'meta' => [
                'used_traffic' => 2147483648,
                'data_used' => 2147483648,
            ],
        ]);

        // Mock HTTP for reset
        Http::fake([
            '*/api/v1/users/testuser/reset_traffic' => Http::response([], 200),
        ]);

        // Bypass policy authorization by calling controller method directly
        $controller = new \Modules\Reseller\Http\Controllers\ConfigController;
        $request = \Illuminate\Http\Request::create(
            route('reseller.configs.resetUsage', $config),
            'POST'
        );
        $request->setUserResolver(function () use ($user) {
            return $user;
        });
        
        // Mock Gate to allow authorization
        \Illuminate\Support\Facades\Gate::shouldReceive('authorize')->andReturn(true);
        
        $response = $controller->resetUsage($request, $config);

        // Assert config usage was reset
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);

        // Assert meta fields were zeroed
        $this->assertNotNull($config->meta);
        $this->assertArrayHasKey('used_traffic', $config->meta);
        $this->assertArrayHasKey('data_used', $config->meta);
        $this->assertEquals(0, $config->meta['used_traffic']);
        $this->assertEquals(0, $config->meta['data_used']);

        // Assert settled_usage_bytes was updated
        $this->assertArrayHasKey('settled_usage_bytes', $config->meta);
        $this->assertEquals(2147483648, $config->meta['settled_usage_bytes']);
    }

    public function test_reset_usage_recalculates_reseller_aggregate(): void
    {
        $user = User::factory()->create();
        
        $panel = Panel::create([
            'name' => 'Eylandoo Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-key',
            'is_active' => true,
            'extra' => [],
        ]);

        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 3221225472, // 3 GB (will be recalculated)
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Config 1: 2 GB current usage
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'user1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2147483648, // 2 GB
            'expires_at' => now()->addDays(30),
            'meta' => [],
        ]);

        // Config 2: 1 GB current usage
        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'user2',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1073741824, // 1 GB
            'expires_at' => now()->addDays(30),
            'meta' => [],
        ]);

        // Mock HTTP for reset
        Http::fake([
            '*/api/v1/users/user1/reset_traffic' => Http::response([], 200),
        ]);

        // Bypass policy authorization
        $controller = new \Modules\Reseller\Http\Controllers\ConfigController;
        $request = \Illuminate\Http\Request::create(
            route('reseller.configs.resetUsage', $config1),
            'POST'
        );
        $request->setUserResolver(function () use ($user) {
            return $user;
        });
        
        \Illuminate\Support\Facades\Gate::shouldReceive('authorize')->andReturn(true);
        
        $controller->resetUsage($request, $config1);

        // Assert config1 usage is 0, settled is 2 GB
        $config1->refresh();
        $this->assertEquals(0, $config1->usage_bytes);
        $this->assertEquals(2147483648, $config1->meta['settled_usage_bytes']);

        // Assert reseller aggregate is recalculated:
        // config1: 0 (current) + 2 GB (settled) = 2 GB
        // config2: 1 GB (current) + 0 (settled) = 1 GB
        // Total = 3 GB
        $reseller->refresh();
        $this->assertEquals(3221225472, $reseller->traffic_used_bytes);
    }
}
