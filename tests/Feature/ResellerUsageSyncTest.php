<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\MarzneshinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class ResellerUsageSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Log to avoid output during tests
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
    }

    public function test_sync_job_fetches_usage_from_marzneshin_using_getuser(): void
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
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create a config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 1073741824, // 1 GB
                'data_limit' => 5368709120, // 5 GB
            ], 200),
        ]);

        // Run the sync job
        $job = new SyncResellerUsageJob();
        $job->handle();

        // Assert the config usage was updated
        $config->refresh();
        $this->assertEquals(1073741824, $config->usage_bytes);

        // Assert reseller total usage was updated
        $reseller->refresh();
        $this->assertEquals(1073741824, $reseller->traffic_used_bytes);
    }

    public function test_sync_job_uses_exact_panel_id_when_available(): void
    {
        // Create two panels of the same type
        $panel1 = Panel::create([
            'name' => 'Panel 1',
            'url' => 'https://panel1.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node1.com'],
        ]);

        $panel2 = Panel::create([
            'name' => 'Panel 2',
            'url' => 'https://panel2.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node2.com'],
        ]);

        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create config with specific panel_id (panel2)
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel2->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP - panel2 should be called, not panel1
        Http::fake([
            'https://panel2.com/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            'https://panel2.com/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 2147483648, // 2 GB
            ], 200),
            'https://panel1.com/*' => Http::response(['error' => 'Should not be called'], 500),
        ]);

        $job = new SyncResellerUsageJob();
        $job->handle();

        // Assert the correct panel was used
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'panel2.com');
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'panel1.com');
        });

        $config->refresh();
        $this->assertEquals(2147483648, $config->usage_bytes);
    }

    public function test_per_config_overrun_allowed_when_setting_enabled(): void
    {
        // Enable config overrun setting
        Setting::create([
            'key' => 'reseller.allow_config_overrun',
            'value' => 'true',
        ]);

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
            'traffic_limit_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB limit
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP - config exceeds its own limit
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 2 * 1024 * 1024 * 1024, // 2 GB used (exceeds 1 GB limit)
            ], 200),
        ]);

        $job = new SyncResellerUsageJob();
        $job->handle();

        // Config should remain active when overrun is allowed
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertEquals(2 * 1024 * 1024 * 1024, $config->usage_bytes);
    }

    public function test_reseller_quota_exhausted_triggers_auto_disable_with_rate_limiting(): void
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

        // Reseller with low quota
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB total
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create multiple configs
        $configs = [];
        for ($i = 0; $i < 5; $i++) {
            $configs[] = ResellerConfig::factory()->create([
                'reseller_id' => $reseller->id,
                'panel_id' => $panel->id,
                'panel_type' => 'marzneshin',
                'panel_user_id' => "testuser{$i}",
                'status' => 'active',
                'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
                'usage_bytes' => 0,
                'expires_at' => now()->addDays(30),
            ]);
        }

        // Mock HTTP - all configs report high usage, exceeding reseller quota
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*' => Http::response([
                'used_traffic' => 500 * 1024 * 1024, // 500 MB each
            ], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        $job = new SyncResellerUsageJob();
        $job->handle();

        // All configs should be disabled with auto_disabled events
        foreach ($configs as $config) {
            $config->refresh();
            $this->assertEquals('disabled', $config->status);
            
            $event = $config->events()->where('type', 'auto_disabled')->first();
            $this->assertNotNull($event);
            $this->assertEquals('reseller_quota_exhausted', $event->meta['reason']);
        }
    }

    public function test_reenable_job_restores_configs_after_quota_increase(): void
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
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB now
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB used
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create a config that was auto-disabled
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'disabled',
            'disabled_at' => now(),
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
        ]);

        // Create auto_disabled event
        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'auto_disabled',
            'meta' => ['reason' => 'reseller_quota_exhausted'],
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response([], 200),
        ]);

        // Run re-enable job
        $job = new ReenableResellerConfigsJob();
        $job->handle();

        // Config should be re-enabled
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertNull($config->disabled_at);

        // Should have auto_enabled event
        $event = $config->events()->where('type', 'auto_enabled')->first();
        $this->assertNotNull($event);
        $this->assertEquals('reseller_recovered', $event->meta['reason']);
    }

    public function test_reenable_job_does_not_restore_manually_disabled_configs(): void
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
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'disabled',
            'disabled_at' => now(),
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
        ]);

        // Create manual_disabled event (last event)
        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'manual_disabled',
            'meta' => ['user_id' => 1],
        ]);

        Http::fake();

        // Run re-enable job
        $job = new ReenableResellerConfigsJob();
        $job->handle();

        // Config should remain disabled
        $config->refresh();
        $this->assertEquals('disabled', $config->status);

        // Should NOT have auto_enabled event
        $event = $config->events()->where('type', 'auto_enabled')->first();
        $this->assertNull($event);
    }
}
