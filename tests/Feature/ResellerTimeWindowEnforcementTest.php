<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\EnforceResellerTimeWindowsJob;
use Modules\Reseller\Services\ResellerProvisioner;
use Modules\Reseller\Services\ResellerTimeWindowEnforcer;
use Tests\TestCase;

class ResellerTimeWindowEnforcementTest extends TestCase
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

    public function test_reseller_suspends_on_expiry(): void
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

        // Create a reseller with expired window
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB used
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->subMinutes(10), // Expired 10 minutes ago
        ]);

        // Create active configs
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
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

        // Run the enforcement job
        $provisioner = new ResellerProvisioner;
        $enforcer = new ResellerTimeWindowEnforcer($provisioner);
        $job = new EnforceResellerTimeWindowsJob;
        $job->handle($enforcer);

        // Assert reseller was suspended
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);

        // Assert audit log was created for reseller
        $auditLog = AuditLog::where('action', 'reseller_time_window_suspended')
            ->where('target_type', 'reseller')
            ->where('target_id', $reseller->id)
            ->first();
        $this->assertNotNull($auditLog);
        $this->assertEquals('time_window_expired', $auditLog->reason);

        // Assert configs were disabled
        $config1->refresh();
        $config2->refresh();
        $this->assertEquals('disabled', $config1->status);
        $this->assertEquals('disabled', $config2->status);

        // Assert configs have the time window flag
        $this->assertTrue($config1->meta['suspended_by_time_window']);
        $this->assertTrue($config2->meta['suspended_by_time_window']);

        // Assert config events were created
        $event1 = ResellerConfigEvent::where('reseller_config_id', $config1->id)
            ->where('type', 'auto_disabled')
            ->first();
        $this->assertNotNull($event1);
        $this->assertEquals('reseller_time_window_expired', $event1->meta['reason']);

        // Assert audit logs for configs
        $configAudit = AuditLog::where('action', 'reseller_config_disabled_by_time_window')
            ->where('target_type', 'config')
            ->count();
        $this->assertEquals(2, $configAudit);

        // Assert remote API was called
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/disable');
        });
    }

    public function test_reseller_reactivates_on_recharge(): void
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

        // Create a suspended reseller
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->addDays(30), // Extended to future
        ]);

        // Create disabled configs with time window flag
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
            'meta' => ['suspended_by_time_window' => true],
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
            'meta' => ['suspended_by_time_window' => true],
        ]);

        // Create a manually disabled config (should NOT be re-enabled)
        $config3 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser3',
            'status' => 'disabled',
            'disabled_at' => now(),
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'meta' => [], // No time window flag
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response([], 200),
        ]);

        // Run the enforcement job
        $provisioner = new ResellerProvisioner;
        $enforcer = new ResellerTimeWindowEnforcer($provisioner);
        $job = new EnforceResellerTimeWindowsJob;
        $job->handle($enforcer);

        // Assert reseller was reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // Assert audit log was created for reseller
        $auditLog = AuditLog::where('action', 'reseller_time_window_reactivated')
            ->where('target_type', 'reseller')
            ->where('target_id', $reseller->id)
            ->first();
        $this->assertNotNull($auditLog);
        $this->assertEquals('time_window_extended', $auditLog->reason);

        // Now run the re-enable job that was dispatched by the enforcer
        // (In production this runs via queue, but in tests we run it synchronously)
        $reenableJob = new \Modules\Reseller\Jobs\ReenableResellerConfigsJob($reseller->id);
        $reenableJob->handle($provisioner);

        // Assert configs with time window flag were re-enabled
        $config1->refresh();
        $config2->refresh();
        $this->assertEquals('active', $config1->status);
        $this->assertEquals('active', $config2->status);
        $this->assertNull($config1->disabled_at);
        $this->assertNull($config2->disabled_at);

        // Assert time window flag was removed
        $this->assertArrayNotHasKey('suspended_by_time_window', $config1->meta ?? []);
        $this->assertArrayNotHasKey('suspended_by_time_window', $config2->meta ?? []);

        // Assert manually disabled config remains disabled
        $config3->refresh();
        $this->assertEquals('disabled', $config3->status);

        // Assert config events were created
        $event1 = ResellerConfigEvent::where('reseller_config_id', $config1->id)
            ->where('type', 'auto_enabled')
            ->first();
        $this->assertNotNull($event1);
        $this->assertEquals('reseller_recovered', $event1->meta['reason']);

        // Assert audit logs for configs
        $configAudit = AuditLog::where('action', 'config_auto_enabled')
            ->where('target_type', 'config')
            ->count();
        $this->assertEquals(2, $configAudit);
    }

    public function test_idempotency(): void
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

        // Create an already suspended reseller
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->subMinutes(10), // Expired
        ]);

        // Create already disabled config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'disabled_at' => now()->subHours(1),
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'meta' => ['suspended_by_time_window' => true],
        ]);

        // Mock HTTP
        Http::fake();

        // Run the job twice
        $provisioner = new ResellerProvisioner;
        $enforcer = new ResellerTimeWindowEnforcer($provisioner);
        $job1 = new EnforceResellerTimeWindowsJob;
        $job1->handle($enforcer);

        $job2 = new EnforceResellerTimeWindowsJob;
        $job2->handle($enforcer);

        // Count audit logs - should only have the initial ones, not duplicates
        $resellerAuditCount = AuditLog::where('action', 'reseller_time_window_suspended')
            ->where('target_type', 'reseller')
            ->where('target_id', $reseller->id)
            ->count();

        // Should be 0 because reseller was already suspended
        $this->assertEquals(0, $resellerAuditCount);

        // Config event count should remain unchanged
        $eventCount = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'auto_disabled')
            ->count();

        // Should be 0 from this run because config was already disabled
        $this->assertEquals(0, $eventCount);

        // Assert reseller and config states unchanged
        $reseller->refresh();
        $config->refresh();
        $this->assertEquals('suspended', $reseller->status);
        $this->assertEquals('disabled', $config->status);
    }

    public function test_display_clamps_to_zero(): void
    {
        // Create a reseller with expired window
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->subDays(5), // Expired 5 days ago
        ]);

        // Test time remaining is clamped to 0
        $remainingSeconds = $reseller->getTimeRemainingSeconds();
        $remainingDays = $reseller->getTimeRemainingDays();

        $this->assertEquals(0, $remainingSeconds);
        $this->assertEquals(0, $remainingDays);

        // Test with valid window
        $reseller->update(['window_ends_at' => now()->addDays(10)]);
        $reseller->refresh();
        $remainingDays = $reseller->getTimeRemainingDays();

        $this->assertGreaterThan(0, $remainingDays);
        $this->assertLessThanOrEqual(10, $remainingDays);
    }

    public function test_plan_based_resellers_are_not_affected(): void
    {
        // Create a plan-based reseller with no window
        $reseller = Reseller::factory()->create([
            'type' => 'plan',
            'status' => 'active',
            'window_starts_at' => null,
            'window_ends_at' => null,
        ]);

        Http::fake();

        // Run the job
        $provisioner = new ResellerProvisioner;
        $enforcer = new ResellerTimeWindowEnforcer($provisioner);
        $job = new EnforceResellerTimeWindowsJob;
        $job->handle($enforcer);

        // Assert reseller remains active
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // No time window enforcement audit logs should be created
        $auditCount = AuditLog::where('target_type', 'reseller')
            ->where('target_id', $reseller->id)
            ->whereIn('action', ['reseller_time_window_suspended', 'reseller_time_window_reactivated'])
            ->count();
        $this->assertEquals(0, $auditCount);
    }

    public function test_remote_panel_failure_is_handled_gracefully(): void
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

        // Create a reseller with expired window
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->subMinutes(10),
        ]);

        // Create config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP to fail all requests
        Http::fake([
            '*/api/admins/token' => Http::response(['error' => 'Unauthorized'], 401),
            '*/api/users/*/disable' => Http::response(['error' => 'Server error'], 500),
        ]);

        // Run the job
        $provisioner = new ResellerProvisioner;
        $enforcer = new ResellerTimeWindowEnforcer($provisioner);
        $job = new EnforceResellerTimeWindowsJob;
        $job->handle($enforcer);

        // Assert local state was updated despite remote failure
        $reseller->refresh();
        $config->refresh();
        $this->assertEquals('suspended', $reseller->status);
        $this->assertEquals('disabled', $config->status);
        $this->assertTrue($config->meta['suspended_by_time_window']);

        // Assert event has remote failure info
        $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'auto_disabled')
            ->first();
        $this->assertNotNull($event);
        $this->assertFalse($event->meta['remote_success']);
        $this->assertGreaterThan(1, $event->meta['attempts']);
    }
}
