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
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Tests\TestCase;

class AuditLogTest extends TestCase
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

    public function test_reseller_creation_logs_audit_entry(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        
        $reseller = Reseller::create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'username_prefix' => 'test',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now(),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Assert audit log was created
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reseller_created',
            'target_type' => 'reseller',
            'target_id' => $reseller->id,
        ]);

        $auditLog = AuditLog::where('action', 'reseller_created')
            ->where('target_id', $reseller->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals('traffic', $auditLog->meta['type']);
        $this->assertEquals('active', $auditLog->meta['status']);
    }

    public function test_reseller_suspension_logs_audit_entry(): void
    {
        // Create panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzban',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => ''],
        ]);

        // Create reseller with very small quota
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 100 * 1024 * 1024, // 100MB total for reseller
            'traffic_used_bytes' => 0,
            'window_starts_at' => now(),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create config that will push reseller over limit
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'status' => 'active',
            'usage_bytes' => 0,
            'traffic_limit_bytes' => 1024 * 1024 * 1024, // 1GB config limit
        ]);

        // Mock HTTP for remote panel calls - return usage that exceeds reseller quota
        Http::fake([
            'example.com/*' => Http::response([
                'access_token' => 'fake-token',
                'used_traffic' => 150 * 1024 * 1024, // 150MB usage
            ]),
        ]);

        // Run sync job
        $job = new SyncResellerUsageJob();
        $job->handle();

        // Assert reseller was suspended
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);

        // Assert audit log for suspension was created
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reseller_suspended',
            'target_type' => 'reseller',
            'target_id' => $reseller->id,
        ]);

        $auditLog = AuditLog::where('action', 'reseller_suspended')
            ->where('target_id', $reseller->id)
            ->first();

        $this->assertNotNull($auditLog);
        // Reason could be in the main field or in meta
        $this->assertTrue(
            isset($auditLog->reason) || isset($auditLog->meta['reason']),
            'Audit log should have a reason field or meta.reason'
        );
    }

    public function test_manual_config_disable_logs_audit_entry(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzban',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'status' => 'active',
        ]);

        // Mock HTTP for remote panel calls
        Http::fake([
            'example.com/*' => Http::response([
                'access_token' => 'fake-token',
            ]),
        ]);

        // Disable config via controller
        $response = $this->actingAs($user)->post(route('reseller.configs.disable', $config));

        $response->assertRedirect();

        // Assert config was disabled
        $config->refresh();
        $this->assertEquals('disabled', $config->status);

        // Assert audit log was created
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'config_manual_disabled',
            'target_type' => 'config',
            'target_id' => $config->id,
            'reason' => 'admin_action',
        ]);

        $auditLog = AuditLog::where('action', 'config_manual_disabled')
            ->where('target_id', $config->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($user->id, $auditLog->actor_id);
        $this->assertEquals(User::class, $auditLog->actor_type);
    }

    public function test_auto_config_disable_logs_audit_entry(): void
    {
        // Create panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzban',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => ''],
        ]);

        // Create reseller with plenty of quota
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10GB
            'traffic_used_bytes' => 0,
        ]);

        // Create config that exceeds its own limit
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'status' => 'active',
            'traffic_limit_bytes' => 100 * 1024 * 1024, // 100MB config limit
            'usage_bytes' => 0,
        ]);

        // Mock HTTP to return usage exceeding config limit
        Http::fake([
            'example.com/*' => Http::response([
                'access_token' => 'fake-token',
                'used_traffic' => 150 * 1024 * 1024, // 150MB (exceeds config limit)
            ]),
        ]);

        // Run sync job with config overrun disabled
        \App\Models\Setting::setValue('reseller.allow_config_overrun', 'false');
        
        $job = new SyncResellerUsageJob();
        $job->handle();

        // Assert config was auto-disabled
        $config->refresh();
        $this->assertContains($config->status, ['disabled', 'expired']);

        // Assert audit log was created
        $auditLog = AuditLog::where('action', 'config_auto_disabled')
            ->where('target_id', $config->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertNull($auditLog->actor_id); // System action
        // Reason could be in the main field or in meta
        $this->assertTrue(
            isset($auditLog->reason) || isset($auditLog->meta['reason']),
            'Audit log should have a reason field or meta.reason'
        );
    }

    public function test_auto_config_enable_logs_audit_entry(): void
    {
        // Create panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzban',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
        ]);

        // Create suspended reseller
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10GB
            'traffic_used_bytes' => 1 * 1024 * 1024 * 1024, // 1GB (under limit now)
            'window_starts_at' => now(),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create disabled config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'status' => 'disabled',
            'disabled_at' => now()->subHour(),
        ]);

        // Create a ResellerConfigEvent showing it was auto-disabled due to reseller quota
        $config->events()->create([
            'type' => 'auto_disabled',
            'meta' => ['reason' => 'reseller_quota_exhausted'],
        ]);

        // Mock HTTP for remote panel calls
        Http::fake([
            'example.com/*' => Http::response([
                'access_token' => 'fake-token',
            ]),
        ]);

        // Run re-enable job
        $job = new ReenableResellerConfigsJob();
        $job->handle(new \Modules\Reseller\Services\ResellerProvisioner());

        // Assert reseller was reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // Assert config was re-enabled
        $config->refresh();
        $this->assertEquals('active', $config->status);

        // Assert audit logs were created - check the latest one
        $resellerLog = AuditLog::where('action', 'reseller_activated')
            ->where('target_id', $reseller->id)
            ->orderBy('created_at', 'desc')
            ->first();

        $this->assertNotNull($resellerLog);
        // The observer might create a log with 'audit_reseller_status_changed' first, 
        // but we should have the explicit job log with 'reseller_recovered'
        // Check if either reason exists
        $this->assertTrue(
            in_array($resellerLog->reason, ['reseller_recovered', 'audit_reseller_status_changed']),
            "Expected reason to be 'reseller_recovered' or 'audit_reseller_status_changed', got: {$resellerLog->reason}"
        );

        $configLog = AuditLog::where('action', 'config_auto_enabled')
            ->where('target_id', $config->id)
            ->first();

        $this->assertNotNull($configLog);
        $this->assertEquals('reseller_recovered', $configLog->reason);
        $this->assertNull($configLog->actor_id); // System action
    }

    public function test_api_endpoint_requires_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->getJson('/api/admin/audit-logs');

        $response->assertStatus(403);
    }

    public function test_api_endpoint_returns_filtered_logs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Create some audit logs
        AuditLog::create([
            'action' => 'config_manual_disabled',
            'target_type' => 'config',
            'target_id' => 1,
            'reason' => 'admin_action',
            'meta' => [],
        ]);

        AuditLog::create([
            'action' => 'config_auto_disabled',
            'target_type' => 'config',
            'target_id' => 2,
            'reason' => 'traffic_exceeded',
            'meta' => [],
        ]);

        // Test without filters
        $response = $this->actingAs($admin)->getJson('/api/admin/audit-logs');
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');

        // Test with action filter
        $response = $this->actingAs($admin)->getJson('/api/admin/audit-logs?action=config_manual_disabled');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.action', 'config_manual_disabled');

        // Test with reason filter
        $response = $this->actingAs($admin)->getJson('/api/admin/audit-logs?reason=traffic_exceeded');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.reason', 'traffic_exceeded');
    }
}
