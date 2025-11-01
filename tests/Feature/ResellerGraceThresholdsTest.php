<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class ResellerGraceThresholdsTest extends TestCase
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
    }

    public function test_config_grace_threshold_prevents_disable_at_exact_limit(): void
    {
        // Set grace thresholds
        Setting::create(['key' => 'config.auto_disable_grace_percent', 'value' => '2.0']);
        Setting::create(['key' => 'config.auto_disable_grace_bytes', 'value' => (string)(50 * 1024 * 1024)]);
        Setting::create(['key' => 'reseller.allow_config_overrun', 'value' => 'false']); // Enable per-config checks

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
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        $configLimit = 1 * 1024 * 1024 * 1024; // 1 GB
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => $configLimit,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP - usage exactly at limit (should NOT disable due to grace)
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => $configLimit, // Exactly at limit
            ], 200),
        ]);

        $job = new SyncResellerUsageJob();
        $job->handle();

        // Config should remain active (within grace)
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertEquals($configLimit, $config->usage_bytes);
        
        // No auto_disabled event should exist
        $event = $config->events()->where('type', 'auto_disabled')->first();
        $this->assertNull($event);
    }

    public function test_config_grace_threshold_disables_when_over_grace(): void
    {
        // Set grace thresholds
        Setting::create(['key' => 'config.auto_disable_grace_percent', 'value' => '2.0']);
        Setting::create(['key' => 'config.auto_disable_grace_bytes', 'value' => (string)(50 * 1024 * 1024)]); // 50MB
        Setting::create(['key' => 'reseller.allow_config_overrun', 'value' => 'false']);

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

        $configLimit = 1 * 1024 * 1024 * 1024; // 1 GB
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => $configLimit,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Grace is max(50MB, 1GB * 2%) = max(50MB, 20.48MB) = 50MB
        // Effective limit: 1GB + 50MB = 1.05GB
        $overGraceUsage = $configLimit + (51 * 1024 * 1024); // 1GB + 51MB (over grace)

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => $overGraceUsage,
            ], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        $job = new SyncResellerUsageJob();
        $job->handle();

        // Config should be disabled (exceeded grace)
        $config->refresh();
        $this->assertEquals('disabled', $config->status);
        $this->assertEquals($overGraceUsage, $config->usage_bytes);
        
        // Should have auto_disabled event with telemetry
        $event = $config->events()->where('type', 'auto_disabled')->first();
        $this->assertNotNull($event);
        $this->assertEquals('traffic_exceeded', $event->meta['reason']);
        $this->assertArrayHasKey('remote_success', $event->meta);
        $this->assertArrayHasKey('attempts', $event->meta);
    }

    public function test_reseller_grace_threshold_prevents_disable_at_exact_limit(): void
    {
        // Set reseller-level grace
        Setting::create(['key' => 'reseller.auto_disable_grace_percent', 'value' => '2.0']);
        Setting::create(['key' => 'reseller.auto_disable_grace_bytes', 'value' => (string)(50 * 1024 * 1024)]);

        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        $resellerLimit = 5 * 1024 * 1024 * 1024; // 5 GB
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => $resellerLimit,
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
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP - usage exactly at reseller limit
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => $resellerLimit, // Exactly at reseller limit
            ], 200),
        ]);

        $job = new SyncResellerUsageJob();
        $job->handle();

        // Config should remain active (within grace)
        $config->refresh();
        $this->assertEquals('active', $config->status);
        
        // Reseller should remain active
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);
    }

    public function test_reseller_grace_threshold_disables_when_over_grace(): void
    {
        // Set reseller-level grace
        Setting::create(['key' => 'reseller.auto_disable_grace_percent', 'value' => '1.0']); // 1%
        Setting::create(['key' => 'reseller.auto_disable_grace_bytes', 'value' => (string)(10 * 1024 * 1024)]); // 10MB

        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        $resellerLimit = 1 * 1024 * 1024 * 1024; // 1 GB
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => $resellerLimit,
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
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Grace: max(1GB * 1%, 10MB) = max(10.24MB, 10MB) = 10.24MB
        // Effective limit: 1GB + 10.24MB
        $overGraceUsage = $resellerLimit + (11 * 1024 * 1024); // 1GB + 11MB (over grace)

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => $overGraceUsage,
            ], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        $job = new SyncResellerUsageJob();
        $job->handle();

        // Config should be disabled
        $config->refresh();
        $this->assertEquals('disabled', $config->status);
        
        // Reseller should be suspended
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);
        
        // Event should have correct reason
        $event = $config->events()->where('type', 'auto_disabled')->first();
        $this->assertNotNull($event);
        $this->assertEquals('reseller_quota_exhausted', $event->meta['reason']);
    }

    public function test_time_expiry_grace_prevents_immediate_expiration(): void
    {
        // Set time grace to 60 minutes
        Setting::create(['key' => 'reseller.time_expiry_grace_minutes', 'value' => '60']);
        Setting::create(['key' => 'reseller.allow_config_overrun', 'value' => 'false']);

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

        // Config expired 30 minutes ago (within 60-minute grace)
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->subMinutes(30), // Expired 30 minutes ago
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'username' => 'testuser',
                'used_traffic' => 100 * 1024 * 1024, // 100MB
            ], 200),
        ]);

        $job = new SyncResellerUsageJob();
        $job->handle();

        // Config should remain active (within time grace)
        $config->refresh();
        $this->assertEquals('active', $config->status);
    }
}
