<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
use Modules\Reseller\Services\ResellerTimeWindowEnforcer;
use Tests\TestCase;

class ResellerTimeWindowTrafficEnforcementTest extends TestCase
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

    public function test_time_window_enforcer_does_not_reactivate_reseller_without_traffic_remaining(): void
    {
        // Create a reseller user
        $resellerUser = User::factory()->create();

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

        // Create a suspended reseller with valid window but NO traffic remaining
        $reseller = Reseller::factory()->create([
            'user_id' => $resellerUser->id,
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'traffic_used_bytes' => 6 * 1024 * 1024 * 1024, // 6 GB (over quota)
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30), // Valid window
        ]);

        // Create a disabled config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'traffic_limit_bytes' => 3 * 1024 * 1024 * 1024,
            'usage_bytes' => 3 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(1),
            'meta' => [
                'suspended_by_time_window' => true,
            ],
        ]);

        // Try to reactivate using the enforcer
        $enforcer = app(ResellerTimeWindowEnforcer::class);
        $result = $enforcer->reactivateIfEligible($reseller);

        // Should NOT reactivate because no traffic remaining
        $this->assertFalse($result);

        // Verify reseller remains suspended
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);

        // Verify config remains disabled
        $config->refresh();
        $this->assertEquals('disabled', $config->status);
    }

    public function test_time_window_enforcer_reactivates_reseller_with_traffic_and_valid_window(): void
    {
        Queue::fake();

        // Create a reseller user
        $resellerUser = User::factory()->create();

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

        // Create a suspended reseller with valid window AND traffic remaining
        $reseller = Reseller::factory()->create([
            'user_id' => $resellerUser->id,
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB (under quota)
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30), // Valid window
        ]);

        // Create a disabled config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'traffic_limit_bytes' => 3 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(1),
            'meta' => [
                'suspended_by_time_window' => true,
            ],
        ]);

        // Try to reactivate using the enforcer
        $enforcer = app(ResellerTimeWindowEnforcer::class);
        $result = $enforcer->reactivateIfEligible($reseller);

        // Should reactivate because has traffic AND valid window
        $this->assertTrue($result);

        // Verify reseller is now active
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // Verify re-enable job was dispatched with reseller ID
        Queue::assertPushed(ReenableResellerConfigsJob::class, function ($job) use ($reseller) {
            return $job->resellerId === $reseller->id;
        });

        // Verify audit log was created
        $auditLog = AuditLog::where('target_type', 'reseller')
            ->where('target_id', $reseller->id)
            ->where('action', 'reseller_time_window_reactivated')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('time_window_extended', $auditLog->reason);
        $this->assertArrayHasKey('traffic_used_bytes', $auditLog->meta);
        $this->assertArrayHasKey('traffic_total_bytes', $auditLog->meta);
    }

    public function test_time_window_enforcer_does_not_reactivate_reseller_with_expired_window(): void
    {
        // Create a reseller user
        $resellerUser = User::factory()->create();

        // Create a suspended reseller with traffic remaining but EXPIRED window
        $reseller = Reseller::factory()->create([
            'user_id' => $resellerUser->id,
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB (under quota)
            'window_starts_at' => now()->subDays(30),
            'window_ends_at' => now()->subDay(), // Expired window
        ]);

        // Try to reactivate using the enforcer
        $enforcer = app(ResellerTimeWindowEnforcer::class);
        $result = $enforcer->reactivateIfEligible($reseller);

        // Should NOT reactivate because window is expired
        $this->assertFalse($result);

        // Verify reseller remains suspended
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);
    }

    public function test_reenable_job_handles_both_suspension_marker_types(): void
    {
        // Create a reseller user
        $resellerUser = User::factory()->create();

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

        // Create a reseller that can be reactivated
        $reseller = Reseller::factory()->create([
            'user_id' => $resellerUser->id,
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create config with time window marker
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0.5 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(2),
            'meta' => [
                'suspended_by_time_window' => true,
            ],
        ]);

        // Create config with quota exhaustion marker
        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'disabled',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0.5 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(2),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
                'disabled_by_reseller_suspension_reason' => 'reseller_quota_exhausted',
            ],
        ]);

        // Create config without marker (should NOT be re-enabled)
        $config3 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser3',
            'status' => 'disabled',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0.5 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(1),
            'meta' => [], // No marker - manually disabled
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response(null, 200),
        ]);

        // Run the job
        $job = new ReenableResellerConfigsJob($reseller->id);
        $job->handle(new \Modules\Reseller\Services\ResellerProvisioner);

        // Verify both marked configs were re-enabled
        $config1->refresh();
        $config2->refresh();
        $config3->refresh();

        $this->assertEquals('active', $config1->status);
        $this->assertNull($config1->disabled_at);
        $this->assertFalse(isset($config1->meta['suspended_by_time_window']));

        $this->assertEquals('active', $config2->status);
        $this->assertNull($config2->disabled_at);
        $this->assertFalse(isset($config2->meta['disabled_by_reseller_suspension']));
        $this->assertFalse(isset($config2->meta['disabled_by_reseller_suspension_reason']));

        // Config without marker should remain disabled
        $this->assertEquals('disabled', $config3->status);
        $this->assertNotNull($config3->disabled_at);

        // Verify reseller was reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);
    }

    public function test_command_logs_when_skipping_reactivation_due_to_no_traffic(): void
    {
        // Create a reseller user
        $resellerUser = User::factory()->create();

        // Create a suspended reseller with valid window but NO traffic remaining
        $reseller = Reseller::factory()->create([
            'user_id' => $resellerUser->id,
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 5 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 6 * 1024 * 1024 * 1024,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Run the command
        $this->artisan('reseller:enforce-time-windows')
            ->expectsOutput("Found 1 resellers eligible for reactivation")
            ->expectsOutput("  - Skipped reseller #{$reseller->id}: no traffic remaining")
            ->assertExitCode(0);

        // Verify reseller remains suspended
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);
    }
}
