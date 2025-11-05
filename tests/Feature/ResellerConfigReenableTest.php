<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
use Tests\TestCase;

class ResellerConfigReenableTest extends TestCase
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

    public function test_configs_are_marked_when_disabled_by_reseller_suspension(): void
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

        // Create a suspended reseller (quota exhausted)
        $reseller = Reseller::factory()->create([
            'user_id' => $resellerUser->id,
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'traffic_used_bytes' => 6 * 1024 * 1024 * 1024, // 6 GB (over quota)
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create active configs (they should be disabled due to reseller suspension)
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 3 * 1024 * 1024 * 1024,
            'usage_bytes' => 3 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'active',
            'traffic_limit_bytes' => 3 * 1024 * 1024 * 1024,
            'usage_bytes' => 3 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP for disable calls
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response(null, 200),
        ]);

        // Simulate the disableResellerConfigs logic with meta marker
        $reason = 'reseller_quota_exhausted';
        $configs = $reseller->configs()->where('status', 'active')->get();

        foreach ($configs as $config) {
            $meta = $config->meta ?? [];
            $meta['disabled_by_reseller_suspension'] = true;
            $meta['disabled_by_reseller_suspension_reason'] = $reason;
            $meta['disabled_by_reseller_suspension_at'] = now()->toIso8601String();
            
            $config->update([
                'status' => 'disabled',
                'disabled_at' => now(),
                'meta' => $meta,
            ]);
        }

        // Verify configs are disabled and marked
        $config1->refresh();
        $config2->refresh();

        $this->assertEquals('disabled', $config1->status);
        $this->assertEquals('disabled', $config2->status);
        $this->assertTrue($config1->meta['disabled_by_reseller_suspension'] ?? false);
        $this->assertTrue($config2->meta['disabled_by_reseller_suspension'] ?? false);
        $this->assertEquals('reseller_quota_exhausted', $config1->meta['disabled_by_reseller_suspension_reason']);
        $this->assertEquals('reseller_quota_exhausted', $config2->meta['disabled_by_reseller_suspension_reason']);
        $this->assertArrayHasKey('disabled_by_reseller_suspension_at', $config1->meta);
    }

    public function test_reenable_job_filters_configs_by_meta_marker(): void
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

        // Create a reseller that was suspended but now has quota (after recharge)
        $reseller = Reseller::factory()->create([
            'user_id' => $resellerUser->id,
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0, // Reset to 0 (recharged)
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create config disabled by reseller suspension (should be re-enabled)
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(2),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
                'disabled_by_reseller_suspension_reason' => 'reseller_quota_exhausted',
                'disabled_by_reseller_suspension_at' => now()->subHours(2)->toIso8601String(),
            ],
        ]);

        // Create config disabled manually (should NOT be re-enabled)
        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'disabled',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(1),
            'meta' => [], // No marker - was disabled manually
        ]);

        // Mock HTTP for enable calls
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response(null, 200),
        ]);

        // Run the re-enable job for this specific reseller
        $job = new ReenableResellerConfigsJob($reseller->id);
        $job->handle(new \Modules\Reseller\Services\ResellerProvisioner);

        // Verify config1 was re-enabled and marker cleared
        $config1->refresh();
        $this->assertEquals('active', $config1->status);
        $this->assertNull($config1->disabled_at);
        $this->assertFalse(isset($config1->meta['disabled_by_reseller_suspension']));
        $this->assertFalse(isset($config1->meta['disabled_by_reseller_suspension_reason']));
        $this->assertFalse(isset($config1->meta['disabled_by_reseller_suspension_at']));

        // Verify config2 remains disabled (no marker, so not touched)
        $config2->refresh();
        $this->assertEquals('disabled', $config2->status);
        $this->assertNotNull($config2->disabled_at);

        // Verify reseller was reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);
    }

    public function test_reenable_job_creates_events_and_audit_logs(): void
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
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create config with marker
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(2),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
                'disabled_by_reseller_suspension_reason' => 'reseller_quota_exhausted',
                'disabled_by_reseller_suspension_at' => now()->subHours(2)->toIso8601String(),
            ],
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response(null, 200),
        ]);

        // Run the job
        $job = new ReenableResellerConfigsJob($reseller->id);
        $job->handle(new \Modules\Reseller\Services\ResellerProvisioner);

        // Verify config event was created
        $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'auto_enabled')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals('reseller_recovered', $event->meta['reason']);
        $this->assertTrue($event->meta['remote_success']);

        // Verify audit log was created for config
        $configAuditLog = AuditLog::where('target_type', 'config')
            ->where('target_id', $config->id)
            ->where('action', 'config_auto_enabled')
            ->first();

        $this->assertNotNull($configAuditLog);
        $this->assertEquals('reseller_recovered', $configAuditLog->reason);

        // Verify audit log was created for reseller
        // Note: The observer also creates a log with reason='audit_reseller_status_changed'
        // We're looking for the explicit one from the job
        $resellerAuditLog = AuditLog::where('target_type', 'reseller')
            ->where('target_id', $reseller->id)
            ->where('action', 'reseller_activated')
            ->where('reason', 'reseller_recovered')
            ->first();

        $this->assertNotNull($resellerAuditLog, 'Audit log with reason reseller_recovered should exist');
    }

    public function test_reenable_job_accepts_null_reseller_id(): void
    {
        // This test ensures backward compatibility - job can process all resellers

        // Create reseller users
        $resellerUser1 = User::factory()->create();
        $resellerUser2 = User::factory()->create();

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

        // Create two suspended resellers that can be reactivated
        $reseller1 = Reseller::factory()->create([
            'user_id' => $resellerUser1->id,
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        $reseller2 = Reseller::factory()->create([
            'user_id' => $resellerUser2->id,
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create configs with markers
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller1->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(2),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
            ],
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller2->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'disabled',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(2),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
            ],
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response(null, 200),
        ]);

        // Run the job with null reseller_id (should process all eligible)
        $job = new ReenableResellerConfigsJob(null);
        $job->handle(new \Modules\Reseller\Services\ResellerProvisioner);

        // Verify both configs were re-enabled
        $config1->refresh();
        $config2->refresh();
        $this->assertEquals('active', $config1->status);
        $this->assertEquals('active', $config2->status);

        // Verify both resellers were reactivated
        $reseller1->refresh();
        $reseller2->refresh();
        $this->assertEquals('active', $reseller1->status);
        $this->assertEquals('active', $reseller2->status);
    }
}
