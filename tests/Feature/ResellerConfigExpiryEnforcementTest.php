<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class ResellerConfigExpiryEnforcementTest extends TestCase
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

    public function test_reseller_can_create_config_with_expiry_beyond_window(): void
    {
        $user = User::factory()->create();
        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'panel_id' => $panel->id,
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30), // Window ends in 30 days
        ]);

        // Mock HTTP responses for provisioning
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users' => Http::response([
                'username' => 'testuser',
                'subscription_url' => 'sub://example.com',
            ], 200),
        ]);

        // Act: Try to create a config with expiry beyond the reseller window (60 days)
        $this->actingAs($user);
        $response = $this->post(route('reseller.configs.store'), [
            'panel_id' => $panel->id,
            'traffic_limit_gb' => 5,
            'expires_days' => 60, // Exceeds reseller window of 30 days
        ]);

        // Assert: Should succeed without validation error
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('reseller.configs.index'));
        
        // Verify config was created
        $this->assertDatabaseHas('reseller_configs', [
            'reseller_id' => $reseller->id,
            'status' => 'active',
        ]);
    }

    public function test_reseller_suspended_when_quota_exhausted(): void
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
            'traffic_total_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB total
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

        // Mock HTTP - config usage exceeds reseller quota
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'used_traffic' => 2 * 1024 * 1024 * 1024, // 2 GB (exceeds reseller's 1 GB quota)
            ], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        // Act: Run sync job
        $job = new SyncResellerUsageJob();
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
        $job->handle($provisioner);

        // Assert: Reseller should be suspended
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);

        // Assert: Config should be disabled with proper event
        $config->refresh();
        $this->assertEquals('disabled', $config->status);
        
        $event = $config->events()->where('type', 'auto_disabled')->first();
        $this->assertNotNull($event);
        $this->assertEquals('reseller_quota_exhausted', $event->meta['reason']);
    }

    public function test_reseller_suspended_when_window_expired(): void
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
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
            'traffic_used_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB used (within limit)
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->subDays(1), // Window expired yesterday
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

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser' => Http::response([
                'used_traffic' => 500 * 1024 * 1024, // 500 MB
            ], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        // Act: Run sync job
        $job = new SyncResellerUsageJob();
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
        $job->handle($provisioner);

        // Assert: Reseller should be suspended due to expired window
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);

        // Assert: Config should be disabled with proper event
        $config->refresh();
        $this->assertEquals('disabled', $config->status);
        
        $event = $config->events()->where('type', 'auto_disabled')->first();
        $this->assertNotNull($event);
        $this->assertEquals('reseller_window_expired', $event->meta['reason']);
    }

    public function test_suspended_reseller_cannot_access_panel(): void
    {
        $user = User::factory()->create();
        $panel = Panel::factory()->create();

        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'suspended', // Reseller is suspended
            'panel_id' => $panel->id,
        ]);

        $this->actingAs($user);

        // Try to access reseller dashboard
        $response = $this->get(route('reseller.dashboard'));
        
        // Should get 403 forbidden
        $response->assertStatus(403);
        $response->assertSee('suspended');
    }

    public function test_reseller_reactivated_after_quota_increase(): void
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

        // Reseller that was suspended but now has more quota
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB (increased from 1 GB)
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB used
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
            'meta' => [
                'disabled_by_reseller_suspension' => true,
                'disabled_by_reseller_suspension_reason' => 'reseller_quota_exhausted',
            ],
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

        // Act: Run re-enable job
        $job = new ReenableResellerConfigsJob();
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
        $job->handle($provisioner);

        // Assert: Reseller should be reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // Assert: Config should be re-enabled
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertNull($config->disabled_at);

        // Should have auto_enabled event
        $event = $config->events()->where('type', 'auto_enabled')->first();
        $this->assertNotNull($event);
        $this->assertEquals('reseller_recovered', $event->meta['reason']);
    }

    public function test_reseller_reactivated_after_window_extension(): void
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

        // Reseller that was suspended but now has extended window
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30), // Window extended
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
            'meta' => [
                'suspended_by_time_window' => true,
            ],
        ]);

        // Create auto_disabled event for window expiry
        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'auto_disabled',
            'meta' => ['reason' => 'reseller_window_expired'],
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response([], 200),
        ]);

        // Act: Run re-enable job
        $job = new ReenableResellerConfigsJob();
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
        $job->handle($provisioner);

        // Assert: Reseller should be reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // Assert: Config should be re-enabled
        $config->refresh();
        $this->assertEquals('active', $config->status);
    }

    public function test_manually_disabled_configs_not_reenabled(): void
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
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Config that was manually disabled (not auto-disabled)
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

        // Create manual_disabled event (not auto_disabled)
        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'manual_disabled',
            'meta' => ['user_id' => 1],
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response([], 200),
        ]);

        // Act: Run re-enable job
        $job = new ReenableResellerConfigsJob();
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
        $job->handle($provisioner);

        // Assert: Reseller should be reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // Assert: Manually disabled config should remain disabled
        $config->refresh();
        $this->assertEquals('disabled', $config->status);
        $this->assertNotNull($config->disabled_at);

        // Should NOT have auto_enabled event
        $event = $config->events()->where('type', 'auto_enabled')->first();
        $this->assertNull($event);
    }

    public function test_rate_limiting_applied_during_auto_disable(): void
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
            'traffic_total_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create 10 configs to test rate limiting
        $configs = [];
        for ($i = 0; $i < 10; $i++) {
            $configs[] = ResellerConfig::factory()->create([
                'reseller_id' => $reseller->id,
                'panel_id' => $panel->id,
                'panel_type' => 'marzneshin',
                'panel_user_id' => "testuser{$i}",
                'status' => 'active',
                'traffic_limit_bytes' => 1 * 1024 * 1024 * 1024,
                'usage_bytes' => 0,
                'expires_at' => now()->addDays(30),
            ]);
        }

        // Mock HTTP - high usage exceeds reseller quota
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*' => Http::response([
                'used_traffic' => 200 * 1024 * 1024, // 200 MB each
            ], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        // Act: Run sync job and measure time
        $startTime = microtime(true);
        $job = new SyncResellerUsageJob();
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
        $job->handle($provisioner);
        $duration = microtime(true) - $startTime;

        // Assert: All configs should be disabled
        foreach ($configs as $config) {
            $config->refresh();
            $this->assertEquals('disabled', $config->status);
        }

        // Assert: With 10 configs and rate limit of 3/sec, should take at least 3 seconds
        // (3 immediately, wait 1s, 3 more, wait 1s, 3 more, wait 1s, 1 more = 3 seconds minimum)
        // We'll be lenient and check for at least 2 seconds to account for test overhead
        $this->assertGreaterThan(2, $duration, 'Rate limiting should slow down the disable process');
    }
}
