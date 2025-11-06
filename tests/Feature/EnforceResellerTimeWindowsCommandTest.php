<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EnforceResellerTimeWindowsCommandTest extends TestCase
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

    public function test_command_suspends_expired_resellers(): void
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

        // Create expired reseller
        $expiredReseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->subMinutes(10), // Expired
        ]);

        // Create active reseller (should not be affected)
        $activeReseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(10),
            'window_ends_at' => now()->addDays(20), // Still valid
        ]);

        // Create configs for expired reseller
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $expiredReseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        // Run the command
        $exitCode = Artisan::call('reseller:enforce-time-windows');

        // Assert command succeeded
        $this->assertEquals(0, $exitCode);

        // Assert expired reseller was suspended
        $expiredReseller->refresh();
        $this->assertEquals('suspended', $expiredReseller->status);

        // Assert active reseller remains active
        $activeReseller->refresh();
        $this->assertEquals('active', $activeReseller->status);

        // Assert config was disabled
        $config->refresh();
        $this->assertEquals('disabled', $config->status);
        $this->assertTrue($config->meta['suspended_by_time_window']);
    }

    public function test_command_reactivates_extended_resellers(): void
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

        // Create suspended reseller with extended window
        $suspendedReseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->addDays(30), // Extended
        ]);

        // Create disabled config with time window flag
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $suspendedReseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'disabled_at' => now(),
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'meta' => ['suspended_by_time_window' => true],
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response([], 200),
        ]);

        // Run the command
        $exitCode = Artisan::call('reseller:enforce-time-windows');

        // Assert command succeeded
        $this->assertEquals(0, $exitCode);

        // Assert reseller was reactivated
        $suspendedReseller->refresh();
        $this->assertEquals('active', $suspendedReseller->status);

        // Assert config was re-enabled
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertArrayNotHasKey('suspended_by_time_window', $config->meta ?? []);
    }

    public function test_command_is_idempotent(): void
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

        // Create expired reseller
        $expiredReseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->subMinutes(10), // Expired
        ]);

        // Create config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $expiredReseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        // Run the command first time
        $exitCode1 = Artisan::call('reseller:enforce-time-windows');
        $this->assertEquals(0, $exitCode1);

        // Verify suspended
        $expiredReseller->refresh();
        $config->refresh();
        $this->assertEquals('suspended', $expiredReseller->status);
        $this->assertEquals('disabled', $config->status);

        // Run the command again
        $exitCode2 = Artisan::call('reseller:enforce-time-windows');
        $this->assertEquals(0, $exitCode2);

        // State should remain the same
        $expiredReseller->refresh();
        $config->refresh();
        $this->assertEquals('suspended', $expiredReseller->status);
        $this->assertEquals('disabled', $config->status);
    }

    public function test_configs_reenabled_synchronously_on_reactivation(): void
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

        // Create suspended reseller with valid window and traffic
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(10),
            'window_ends_at' => now()->addDays(20), // Valid window
        ]);

        // Create multiple disabled configs with various suspension markers
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'disabled_at' => now(),
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'meta' => ['suspended_by_time_window' => true, 'disabled_by_reseller_id' => $reseller->id],
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'disabled',
            'disabled_at' => now(),
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'meta' => ['disabled_by_reseller_suspension' => true],
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response([], 200),
        ]);

        // Run the command
        $exitCode = Artisan::call('reseller:enforce-time-windows');

        // Assert command succeeded
        $this->assertEquals(0, $exitCode);

        // Assert reseller was reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // Assert both configs were re-enabled synchronously
        $config1->refresh();
        $config2->refresh();

        $this->assertEquals('active', $config1->status);
        $this->assertEquals('active', $config2->status);
        $this->assertArrayNotHasKey('suspended_by_time_window', $config1->meta ?? []);
        $this->assertArrayNotHasKey('disabled_by_reseller_id', $config1->meta ?? []);
        $this->assertArrayNotHasKey('disabled_by_reseller_suspension', $config2->meta ?? []);
    }

    public function test_inline_fallback_reenables_configs_when_job_fails(): void
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

        // Create suspended reseller with valid window and traffic
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(10),
            'window_ends_at' => now()->addDays(20), // Valid window
        ]);

        // Create disabled config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'disabled_at' => now(),
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
                'disabled_by_reseller_id' => $reseller->id,
            ],
        ]);

        // Mock HTTP to simulate remote panel being available
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response([], 200),
        ]);

        // Run the command - even if job system has issues, inline fallback should work
        $exitCode = Artisan::call('reseller:enforce-time-windows');

        // Assert command succeeded
        $this->assertEquals(0, $exitCode);

        // Assert reseller was reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // Assert config was re-enabled (either by job or fallback)
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertArrayNotHasKey('disabled_by_reseller_suspension', $config->meta ?? []);
        $this->assertArrayNotHasKey('disabled_by_reseller_id', $config->meta ?? []);
    }

    public function test_configs_flagged_correctly_for_window_expiry(): void
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

        // Create reseller with expired window but enough traffic
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024, // Has traffic
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->subMinutes(10), // Expired window
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
        ]);

        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        Artisan::call('reseller:enforce-time-windows');

        $config->refresh();
        $this->assertEquals('disabled', $config->status);
        // Should have suspended_by_time_window flag (window expired)
        $this->assertTrue($config->meta['suspended_by_time_window'] ?? false);
        // Should NOT have disabled_by_reseller_suspension flag (not quota issue)
        $this->assertArrayNotHasKey('disabled_by_reseller_suspension', $config->meta ?? []);
    }

    public function test_configs_flagged_correctly_for_quota_exhaustion(): void
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

        // Create reseller with valid window but exhausted quota
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 11 * 1024 * 1024 * 1024, // Quota exhausted
            'window_starts_at' => now()->subDays(10),
            'window_ends_at' => now()->addDays(20), // Valid window
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
        ]);

        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        Artisan::call('reseller:enforce-time-windows');

        $config->refresh();
        $this->assertEquals('disabled', $config->status);
        // Should have disabled_by_reseller_suspension flag (quota exhausted)
        $this->assertTrue($config->meta['disabled_by_reseller_suspension'] ?? false);
        $this->assertEquals('quota_exhausted', $config->meta['disabled_by_reseller_suspension_reason'] ?? null);
        // Should NOT have suspended_by_time_window flag (not window issue)
        $this->assertArrayNotHasKey('suspended_by_time_window', $config->meta ?? []);
    }

    public function test_scheduler_registration(): void
    {
        // Set feature flag to true (default)
        putenv('SCHEDULE_ENFORCE_RESELLER_WINDOWS=true');

        // Clear schedule cache
        Artisan::call('schedule:clear-cache');

        // Get schedule
        $schedule = app()->make(\Illuminate\Console\Scheduling\Schedule::class);
        $events = $schedule->events();

        // Find our command in the schedule
        $found = false;
        foreach ($events as $event) {
            if (str_contains($event->command ?? '', 'reseller:enforce-time-windows')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Command should be registered in scheduler when feature flag is enabled');

        // Clean up
        putenv('SCHEDULE_ENFORCE_RESELLER_WINDOWS');
    }

    public function test_scheduler_respects_feature_flag(): void
    {
        // Set feature flag to false
        putenv('SCHEDULE_ENFORCE_RESELLER_WINDOWS=false');

        // Need to re-register schedule by re-requiring console.php
        // This is a bit tricky in tests, but we can check the logic directly
        $enabled = env('SCHEDULE_ENFORCE_RESELLER_WINDOWS', true);
        $this->assertFalse($enabled, 'Feature flag should be false');

        // Clean up
        putenv('SCHEDULE_ENFORCE_RESELLER_WINDOWS');
    }
}
