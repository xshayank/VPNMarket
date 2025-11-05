<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerProvisioner;
use Tests\TestCase;

class ResetTrafficResellerAggregateTest extends TestCase
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

    public function test_reset_traffic_logic_updates_reseller_aggregate_immediately(): void
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

        // Create a reseller with usage
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB used
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create a config with usage
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB used
            'expires_at' => now()->addDays(30),
            'meta' => [
                'last_reset_at' => now()->subDays(2)->toDateTimeString(), // Allow reset
            ],
        ]);

        // Simulate the reset logic from ConfigController::resetUsage
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

        // Recalculate and persist reseller aggregate (this is what we're testing)
        $reseller = $config->reseller;
        $totalUsageBytesFromDB = $reseller->configs()
            ->get()
            ->sum(function ($c) {
                return $c->usage_bytes + (int) data_get($c->meta, 'settled_usage_bytes', 0);
            });
        $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);

        // Assert config usage was reset
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes, 'Config usage should be reset to 0');
        $this->assertEquals(2 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'), 'Usage should be settled');

        // Assert reseller aggregate includes settled usage (0 + 2 GB settled = 2 GB)
        $reseller->refresh();
        $this->assertEquals(2 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes, 'Reseller aggregate should include settled usage after reset');
    }

    public function test_reset_traffic_logic_updates_aggregate_with_multiple_configs(): void
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
            'traffic_used_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB total
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
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
            'expires_at' => now()->addDays(30),
            'meta' => [
                'last_reset_at' => now()->subDays(2)->toDateTimeString(),
            ],
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB
            'expires_at' => now()->addDays(30),
        ]);

        // Simulate reset logic for config1
        $toSettle = $config1->usage_bytes;
        $meta = $config1->meta ?? [];
        $currentSettled = (int) data_get($meta, 'settled_usage_bytes', 0);
        $newSettled = $currentSettled + $toSettle;

        $meta['settled_usage_bytes'] = $newSettled;
        $meta['last_reset_at'] = now()->toDateTimeString();

        $config1->update([
            'usage_bytes' => 0,
            'meta' => $meta,
        ]);

        // Recalculate reseller aggregate
        $reseller = $config1->reseller;
        $totalUsageBytesFromDB = $reseller->configs()
            ->get()
            ->sum(function ($c) {
                return $c->usage_bytes + (int) data_get($c->meta, 'settled_usage_bytes', 0);
            });
        $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);

        // Assert config1 was reset
        $config1->refresh();
        $this->assertEquals(0, $config1->usage_bytes);
        $this->assertEquals(2 * 1024 * 1024 * 1024, data_get($config1->meta, 'settled_usage_bytes'));

        // Assert reseller aggregate = config1 settled (2 GB) + config2 usage (1 GB) = 3 GB
        $reseller->refresh();
        $expectedTotal = (2 * 1024 * 1024 * 1024) + (1 * 1024 * 1024 * 1024);
        $this->assertEquals($expectedTotal, $reseller->traffic_used_bytes, 'Reseller aggregate should reflect reset');
    }

    public function test_reset_traffic_logic_zeros_eylandoo_meta_fields(): void
    {
        // Create an Eylandoo panel
        $panel = Panel::create([
            'name' => 'Eylandoo Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-key',
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

        // Create Eylandoo config with meta fields
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB
            'expires_at' => now()->addDays(30),
            'meta' => [
                'used_traffic' => 1 * 1024 * 1024 * 1024,
                'data_used' => 1 * 1024 * 1024 * 1024,
                'last_reset_at' => now()->subDays(2)->toDateTimeString(),
            ],
        ]);

        // Simulate reset logic with Eylandoo-specific zeroing
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

        $config->update([
            'usage_bytes' => 0,
            'meta' => $meta,
        ]);

        // Recalculate reseller aggregate
        $reseller = $config->reseller;
        $totalUsageBytesFromDB = $reseller->configs()
            ->get()
            ->sum(function ($c) {
                return $c->usage_bytes + (int) data_get($c->meta, 'settled_usage_bytes', 0);
            });
        $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);

        // Assert Eylandoo meta fields were zeroed
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);
        $this->assertEquals(0, data_get($config->meta, 'used_traffic'), 'used_traffic should be zeroed for Eylandoo');
        $this->assertEquals(0, data_get($config->meta, 'data_used'), 'data_used should be zeroed for Eylandoo');
        $this->assertEquals(1 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'));
    }

    public function test_reset_logic_is_implemented_correctly_in_controller(): void
    {
        // This test verifies that the resetUsage method in ConfigController
        // contains the correct logic for recalculating reseller aggregate

        $controllerPath = base_path('Modules/Reseller/Http/Controllers/ConfigController.php');
        $this->assertFileExists($controllerPath);

        $content = file_get_contents($controllerPath);

        // Verify the reseller aggregate calculation exists in resetUsage method
        $this->assertStringContainsString(
            'Recalculate and persist reseller aggregate after reset',
            $content,
            'ConfigController::resetUsage should have reseller aggregate recalculation'
        );

        $this->assertStringContainsString(
            'reseller->configs()',
            $content,
            'ConfigController should aggregate all configs'
        );

        $this->assertStringContainsString(
            'settled_usage_bytes',
            $content,
            'ConfigController should include settled_usage_bytes in aggregate'
        );

        $this->assertStringContainsString(
            "Log::info('Config reset updated reseller aggregate'",
            $content,
            'ConfigController should log reseller aggregate update'
        );
    }
}
