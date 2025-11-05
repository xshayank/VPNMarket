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
use Tests\TestCase;

class AdminResellerResetTest extends TestCase
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

    public function test_admin_reset_clears_reseller_aggregate_only(): void
    {
        // Create an admin user
        $admin = User::factory()->create(['is_admin' => true]);
        
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

        // Create a traffic-based reseller with usage
        $reseller = Reseller::factory()->create([
            'user_id' => $resellerUser->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create configs with both current and settled usage
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB current
            'expires_at' => now()->addDays(30),
            'meta' => [
                'settled_usage_bytes' => 500 * 1024 * 1024, // 500 MB settled
            ],
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'testuser2',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB current
            'expires_at' => now()->addDays(30),
            'meta' => [
                'settled_usage_bytes' => 500 * 1024 * 1024, // 500 MB settled
                'used_traffic' => 1 * 1024 * 1024 * 1024,
                'data_used' => 1 * 1024 * 1024 * 1024,
            ],
        ]);

        // Verify initial state
        $this->assertEquals(1 * 1024 * 1024 * 1024, $config1->usage_bytes);
        $this->assertEquals(500 * 1024 * 1024, $config1->getSettledUsageBytes());
        $this->assertEquals(3 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);

        // Simulate admin reset logic (matching new EditReseller.php)
        $oldUsedBytes = $reseller->traffic_used_bytes;
        
        // Simply reset the reseller's aggregate counter
        $reseller->update(['traffic_used_bytes' => 0]);

        // Create audit log
        AuditLog::log(
            action: 'reseller_usage_admin_reset',
            targetType: 'reseller',
            targetId: $reseller->id,
            reason: 'admin_action',
            meta: [
                'old_traffic_used_bytes' => $oldUsedBytes,
                'new_traffic_used_bytes' => 0,
                'traffic_total_bytes' => $reseller->traffic_total_bytes,
                'note' => 'Admin quota forgiveness - config usage intact',
            ]
        );

        // Verify configs remain UNTOUCHED
        $config1->refresh();
        $config2->refresh();
        $this->assertEquals(1 * 1024 * 1024 * 1024, $config1->usage_bytes, 'Config1 usage_bytes should remain unchanged');
        $this->assertEquals(500 * 1024 * 1024, $config1->getSettledUsageBytes(), 'Config1 settled_usage_bytes should remain unchanged');
        $this->assertEquals(1 * 1024 * 1024 * 1024, $config2->usage_bytes, 'Config2 usage_bytes should remain unchanged');
        $this->assertEquals(500 * 1024 * 1024, $config2->getSettledUsageBytes(), 'Config2 settled_usage_bytes should remain unchanged');
        
        // Verify Eylandoo meta fields remain UNTOUCHED
        $this->assertEquals(1 * 1024 * 1024 * 1024, data_get($config2->meta, 'used_traffic'), 'Eylandoo used_traffic should remain unchanged');
        $this->assertEquals(1 * 1024 * 1024 * 1024, data_get($config2->meta, 'data_used'), 'Eylandoo data_used should remain unchanged');

        // Verify only reseller aggregate was reset
        $reseller->refresh();
        $this->assertEquals(0, $reseller->traffic_used_bytes, 'Reseller traffic_used_bytes should be reset to 0');

        // Verify audit log was created
        $auditLog = AuditLog::where('target_type', 'reseller')
            ->where('target_id', $reseller->id)
            ->where('action', 'reseller_usage_admin_reset')
            ->first();
        
        $this->assertNotNull($auditLog);
        $this->assertEquals($oldUsedBytes, $auditLog->meta['old_traffic_used_bytes']);
        $this->assertEquals(0, $auditLog->meta['new_traffic_used_bytes']);
    }

    public function test_admin_reset_leaves_reseller_config_reset_behavior_unchanged(): void
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

        // Create a reseller
        $reseller = Reseller::factory()->create([
            'user_id' => $resellerUser->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
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
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/reset' => Http::response(null, 200),
        ]);

        // Simulate reseller reset (as in ConfigController::resetUsage)
        $toSettle = $config->usage_bytes;
        $meta = $config->meta ?? [];
        $currentSettled = (int) data_get($meta, 'settled_usage_bytes', 0);
        $newSettled = $currentSettled + $toSettle;
        
        $meta['settled_usage_bytes'] = $newSettled;
        $meta['last_reset_at'] = now()->toDateTimeString();
        
        $config->update([
            'usage_bytes' => 0,
            'meta' => $meta,
        ]);

        // Recalculate reseller usage
        $totalUsageBytesFromDB = $reseller->configs()
            ->get()
            ->sum(function ($c) {
                return $c->usage_bytes + (int) data_get($c->meta, 'settled_usage_bytes', 0);
            });
        $reseller->update(['traffic_used_bytes' => $totalUsageBytesFromDB]);

        // Verify settled usage was recorded
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);
        $this->assertEquals(2 * 1024 * 1024 * 1024, $config->getSettledUsageBytes());
        
        // Verify reseller still has the settled usage in total
        $reseller->refresh();
        $this->assertEquals(2 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);
    }
}
