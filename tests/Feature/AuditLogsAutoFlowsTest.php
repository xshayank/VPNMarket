<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class AuditLogsAutoFlowsTest extends TestCase
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

    /**
     * Test that when reseller quota is reached, reseller status becomes suspended
     * and AuditLog entries are present for both the suspension and config auto-disables with reasons.
     */
    public function test_auto_disable_emits_audit_logs_and_suspends_reseller(): void
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

        // Reseller with low quota (1 GB)
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB total
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create 2 active configs that will exceed the reseller quota
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP - both configs report 600 MB usage = 1.2 GB total (exceeds 1 GB reseller quota)
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*' => Http::response([
                'used_traffic' => 600 * 1024 * 1024, // 600 MB each
            ], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        // Run the sync job
        $job = new SyncResellerUsageJob();
        $job->handle();

        // Assert reseller is suspended
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);
        $this->assertEquals(1200 * 1024 * 1024, $reseller->traffic_used_bytes); // 1.2 GB

        // Assert configs are disabled
        $config1->refresh();
        $config2->refresh();
        $this->assertEquals('disabled', $config1->status);
        $this->assertEquals('disabled', $config2->status);

        // Assert AuditLog entries for config auto-disables exist with proper reasons
        $config1AuditLog = AuditLog::where('action', 'config_auto_disabled')
            ->where('target_type', 'config')
            ->where('target_id', $config1->id)
            ->first();
        $this->assertNotNull($config1AuditLog);
        $this->assertEquals('reseller_quota_exhausted', $config1AuditLog->reason);
        $this->assertArrayHasKey('remote_success', $config1AuditLog->meta);
        $this->assertArrayHasKey('attempts', $config1AuditLog->meta);
        $this->assertArrayHasKey('panel_id', $config1AuditLog->meta);

        $config2AuditLog = AuditLog::where('action', 'config_auto_disabled')
            ->where('target_type', 'config')
            ->where('target_id', $config2->id)
            ->first();
        $this->assertNotNull($config2AuditLog);
        $this->assertEquals('reseller_quota_exhausted', $config2AuditLog->reason);

        // Assert AuditLog entry for reseller suspension exists
        // Note: The observer also creates a log with reason='audit_reseller_status_changed'
        // We want to verify the domain-specific log from the job exists with the correct reason
        $resellerAuditLogs = AuditLog::where('action', 'reseller_suspended')
            ->where('target_type', 'reseller')
            ->where('target_id', $reseller->id)
            ->get();
        
        $this->assertGreaterThanOrEqual(1, $resellerAuditLogs->count());
        
        // Find the domain-specific log from the job (not the observer)
        $jobAuditLog = $resellerAuditLogs->firstWhere('reason', 'reseller_quota_exhausted');
        $this->assertNotNull($jobAuditLog, 'Domain-specific audit log from job should exist');
        $this->assertArrayHasKey('traffic_used_bytes', $jobAuditLog->meta);
        $this->assertArrayHasKey('traffic_total_bytes', $jobAuditLog->meta);
        $this->assertEquals(1200 * 1024 * 1024, $jobAuditLog->meta['traffic_used_bytes']);
    }

    /**
     * Test that after recharge/window extension, auto re-enable creates AuditLog entries
     * for config_auto_enabled and reseller_activated.
     */
    public function test_auto_enable_after_recharge_emits_audit_logs(): void
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

        // Suspended reseller that now has quota after recharge
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB (recharged)
            'traffic_used_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB used
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create a config that was auto-disabled due to reseller quota exhaustion
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

        // Create auto_disabled event with reseller quota reason
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
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
        $job = new ReenableResellerConfigsJob();
        $job->handle($provisioner);

        // Assert reseller is reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // Assert config is re-enabled
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertNull($config->disabled_at);

        // Assert AuditLog entry for config auto-enable exists
        $configAuditLog = AuditLog::where('action', 'config_auto_enabled')
            ->where('target_type', 'config')
            ->where('target_id', $config->id)
            ->first();
        $this->assertNotNull($configAuditLog);
        $this->assertEquals('reseller_recovered', $configAuditLog->reason);
        $this->assertArrayHasKey('remote_success', $configAuditLog->meta);
        $this->assertArrayHasKey('attempts', $configAuditLog->meta);
        $this->assertArrayHasKey('panel_id', $configAuditLog->meta);
        $this->assertArrayHasKey('panel_type_used', $configAuditLog->meta);

        // Assert AuditLog entry for reseller activation exists
        // Note: The observer also creates a log with reason='audit_reseller_status_changed'
        // We want to verify the domain-specific log from the job exists with the correct reason
        $resellerAuditLogs = AuditLog::where('action', 'reseller_activated')
            ->where('target_type', 'reseller')
            ->where('target_id', $reseller->id)
            ->get();
        
        $this->assertGreaterThanOrEqual(1, $resellerAuditLogs->count());
        
        // Find the domain-specific log from the job (not the observer)
        $jobAuditLog = $resellerAuditLogs->firstWhere('reason', 'reseller_recovered');
        $this->assertNotNull($jobAuditLog, 'Domain-specific audit log from job should exist');
        $this->assertArrayHasKey('traffic_used_bytes', $jobAuditLog->meta);
        $this->assertArrayHasKey('traffic_total_bytes', $jobAuditLog->meta);
    }

    /**
     * Test that reseller usage aggregation uses all configs regardless of status.
     * This ensures that disabled/expired configs are still counted in total usage.
     */
    public function test_reseller_usage_aggregation_uses_all_configs(): void
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
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create one active config
        $activeConfig = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'active_user',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Create one already-disabled config (not active)
        $disabledConfig = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'disabled_user',
            'status' => 'disabled',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB already used (from before)
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP - only active config will be synced
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/active_user' => Http::response([
                'used_traffic' => 3 * 1024 * 1024 * 1024, // 3 GB
            ], 200),
        ]);

        // Run the sync job
        $job = new SyncResellerUsageJob();
        $job->handle();

        // Assert active config usage was updated
        $activeConfig->refresh();
        $this->assertEquals(3 * 1024 * 1024 * 1024, $activeConfig->usage_bytes);

        // Assert disabled config usage remains unchanged (wasn't synced)
        $disabledConfig->refresh();
        $this->assertEquals(2 * 1024 * 1024 * 1024, $disabledConfig->usage_bytes);

        // Assert reseller total usage includes BOTH configs (3 GB + 2 GB = 5 GB)
        $reseller->refresh();
        $this->assertEquals(5 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);
    }
}
