<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ConfigResetResellerAggregateTest extends TestCase
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

    public function test_config_reset_updates_reseller_aggregate_immediately_excluding_settled(): void
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
            'traffic_used_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB initially
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
            'usage_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB current
            'expires_at' => now()->addDays(30),
            'meta' => ['last_reset_at' => now()->subDays(2)->toDateTimeString()],
        ]);

        // Mock HTTP responses for reset
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser*' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 0,
            ], 200),
        ]);

        // Simulate the reset logic (same as in ConfigController)
        $toSettle = $config->usage_bytes;
        $meta = $config->meta ?? [];
        $currentSettled = (int) data_get($meta, 'settled_usage_bytes', 0);
        $newSettled = $currentSettled + $toSettle;
        $meta['settled_usage_bytes'] = $newSettled;
        $meta['last_reset_at'] = now()->toDateTimeString();

        // Reset local usage
        $config->update([
            'usage_bytes' => 0,
            'meta' => $meta,
        ]);

        // Recalculate and persist reseller aggregate after reset (excluding settled_usage_bytes)
        $totalUsageBytesFromDB = $reseller->configs()
            ->get()
            ->sum(function ($c) {
                return $c->usage_bytes;
            });
        $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);

        // Verify config usage was reset to 0
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes, 'Config usage should be reset to 0');

        // Verify settled usage was updated
        $this->assertEquals(5 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'), 'Settled usage should be 5 GB');

        // Verify reseller aggregate was updated to 0 (excluding settled usage)
        $reseller->refresh();
        $this->assertEquals(0, $reseller->traffic_used_bytes, 'Reseller aggregate should be 0 after reset (excluding settled usage)');
    }

    public function test_config_reset_with_multiple_configs_updates_aggregate_correctly(): void
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
            'traffic_used_bytes' => 8 * 1024 * 1024 * 1024, // 8 GB initially
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create config 1 with 5 GB usage
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'expires_at' => now()->addDays(30),
            'meta' => ['last_reset_at' => now()->subDays(2)->toDateTimeString()],
        ]);

        // Create config 2 with 3 GB usage
        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses for reset
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser1*' => Http::response([
                'username' => 'testuser1',
                'used_traffic' => 0,
            ], 200),
        ]);

        // Simulate reset of config1 only
        $toSettle = $config1->usage_bytes;
        $meta = $config1->meta ?? [];
        $currentSettled = (int) data_get($meta, 'settled_usage_bytes', 0);
        $meta['settled_usage_bytes'] = $currentSettled + $toSettle;
        $meta['last_reset_at'] = now()->toDateTimeString();
        $config1->update(['usage_bytes' => 0, 'meta' => $meta]);

        // Recalculate reseller aggregate (excluding settled_usage_bytes)
        $totalUsageBytesFromDB = $reseller->configs()
            ->get()
            ->sum(function ($c) {
                return $c->usage_bytes;
            });
        $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);

        // Verify config1 was reset
        $config1->refresh();
        $this->assertEquals(0, $config1->usage_bytes, 'Config1 usage should be reset to 0');
        $this->assertEquals(5 * 1024 * 1024 * 1024, data_get($config1->meta, 'settled_usage_bytes'), 'Config1 settled usage should be 5 GB');

        // Verify config2 unchanged
        $config2->refresh();
        $this->assertEquals(3 * 1024 * 1024 * 1024, $config2->usage_bytes, 'Config2 usage should remain 3 GB');

        // Verify reseller aggregate reflects only config2's current usage (3 GB)
        $reseller->refresh();
        $this->assertEquals(3 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes, 'Reseller aggregate should be 3 GB (config2 only, excluding settled)');
    }

    public function test_config_reset_for_eylandoo_zeros_meta_fields(): void
    {
        // Create a panel
        $panel = Panel::create([
            'name' => 'Test Eylandoo Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => ''],
        ]);

        // Create a reseller
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create Eylandoo config with meta fields
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'eylandoouser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
            'expires_at' => now()->addDays(30),
            'meta' => [
                'used_traffic' => 2 * 1024 * 1024 * 1024, // 2 GB
                'data_used' => 2 * 1024 * 1024 * 1024, // 2 GB
                'last_reset_at' => now()->subDays(2)->toDateTimeString(),
            ],
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/v1/users/eylandoouser*' => Http::response([
                'status' => 'success',
                'data' => ['username' => 'eylandoouser', 'status' => 'active'],
            ], 200),
        ]);

        // Simulate reset logic for Eylandoo
        $toSettle = $config->usage_bytes;
        $meta = $config->meta ?? [];
        $currentSettled = (int) data_get($meta, 'settled_usage_bytes', 0);
        $newSettled = $currentSettled + $toSettle;
        $meta['settled_usage_bytes'] = $newSettled;
        $meta['last_reset_at'] = now()->toDateTimeString();

        // For Eylandoo configs, also zero the meta usage fields
        if ($config->panel_type === 'eylandoo') {
            $meta['used_traffic'] = 0;
            $meta['data_used'] = 0;
        }

        $config->update(['usage_bytes' => 0, 'meta' => $meta]);

        // Recalculate reseller aggregate
        $totalUsageBytesFromDB = $reseller->configs()
            ->get()
            ->sum(function ($c) {
                return $c->usage_bytes;
            });
        $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);

        // Verify meta fields were zeroed for Eylandoo
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes, 'Config usage should be 0');
        $this->assertEquals(0, data_get($config->meta, 'used_traffic'), 'Eylandoo used_traffic should be 0');
        $this->assertEquals(0, data_get($config->meta, 'data_used'), 'Eylandoo data_used should be 0');
        $this->assertEquals(2 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'), 'Settled usage should be 2 GB');

        // Verify reseller aggregate was updated
        $reseller->refresh();
        $this->assertEquals(0, $reseller->traffic_used_bytes, 'Reseller aggregate should be 0 after reset');
    }
}
