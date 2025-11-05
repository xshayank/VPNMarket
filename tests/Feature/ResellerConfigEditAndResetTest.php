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
use Modules\Reseller\Jobs\SyncResellerUsageJob;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResellerConfigEditAndResetTest extends TestCase
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

        // Create permissions
        Permission::create(['name' => 'configs.update_own']);
        Permission::create(['name' => 'configs.reset_usage_own']);
    }

    public function test_reseller_can_edit_config_limits(): void
    {
        // Disable middleware for this test
        $this->withoutMiddleware();

        // Create a panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzban',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Create a user with reseller
        $user = User::factory()->create();
        $user->givePermissionTo('configs.update_own');
        
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create a config
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'panel_user_id' => 'testuser',
            'external_username' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB used
            'expires_at' => now()->addDays(15),
            'created_by' => $user->id,
        ]);

        // Mock HTTP responses for panel update
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/user/testuser' => Http::response(['success' => true], 200),
        ]);

        // Update config limits
        $response = $this->actingAs($user)->put(route('reseller.configs.update', $config), [
            'traffic_limit_gb' => 10,
            'expires_at' => now()->addDays(20)->format('Y-m-d'),
        ]);

        $response->assertRedirect(route('reseller.configs.index'));
        $response->assertSessionHas('success');

        // Verify config was updated
        $config->refresh();
        $this->assertEquals(10 * 1024 * 1024 * 1024, $config->traffic_limit_bytes);
        $this->assertEquals(now()->addDays(20)->startOfDay()->timestamp, $config->expires_at->timestamp);

        // Verify event was created
        $this->assertDatabaseHas('reseller_config_events', [
            'reseller_config_id' => $config->id,
            'type' => 'edited',
        ]);

        // Verify audit log was created
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reseller_config_edited',
            'target_type' => 'config',
            'target_id' => $config->id,
        ]);
    }

    public function test_edit_validates_traffic_limit_not_below_usage(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo('configs.update_own');
        
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'window_ends_at' => now()->addDays(30),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB used
            'expires_at' => now()->addDays(15),
            'created_by' => $user->id,
        ]);

        // Try to set limit below current usage (2 GB < 3 GB used)
        $response = $this->actingAs($user)->put(route('reseller.configs.update', $config), [
            'traffic_limit_gb' => 2,
            'expires_at' => now()->addDays(20)->format('Y-m-d'),
        ]);

        $response->assertSessionHas('error');
        
        // Verify config was NOT updated
        $config->refresh();
        $this->assertEquals(5 * 1024 * 1024 * 1024, $config->traffic_limit_bytes);
    }

    public function test_edit_validates_expiry_within_reseller_window(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo('configs.update_own');
        
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'window_ends_at' => now()->addDays(10),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(5),
            'created_by' => $user->id,
        ]);

        // Try to set expiry beyond reseller's window (15 days > 10 days window)
        $response = $this->actingAs($user)->put(route('reseller.configs.update', $config), [
            'traffic_limit_gb' => 5,
            'expires_at' => now()->addDays(15)->format('Y-m-d'),
        ]);

        $response->assertSessionHas('error');
        
        // Verify config was NOT updated
        $config->refresh();
        $this->assertEquals(now()->addDays(5)->startOfDay()->timestamp, $config->expires_at->timestamp);
    }

    public function test_reset_usage_creates_settlement_and_resets_usage(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo('configs.reset_usage_own');
        
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
        ]);

        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzban',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'panel_user_id' => 'testuser',
            'external_username' => 'testuser',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB used
            'expires_at' => now()->addDays(15),
            'created_by' => $user->id,
            'meta' => null,
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/user/testuser/reset' => Http::response(['success' => true], 200),
        ]);

        // Reset usage
        $response = $this->actingAs($user)->post(route('reseller.configs.resetUsage', $config));

        // Should succeed (either success or warning for remote panel failure)
        $this->assertTrue(
            session()->has('success') || session()->has('warning'),
            'Expected reset to succeed'
        );

        // Verify config usage was reset
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);
        
        // Verify settled_usage_bytes was incremented
        $this->assertEquals(2 * 1024 * 1024 * 1024, $config->getSettledUsageBytes());
        
        // Verify last_reset_at was set
        $this->assertNotNull($config->getLastResetAt());

        // Verify event was created
        $this->assertDatabaseHas('reseller_config_events', [
            'reseller_config_id' => $config->id,
            'type' => 'usage_reset',
        ]);

        // Verify audit log was created
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'config_usage_reset',
            'target_type' => 'config',
            'target_id' => $config->id,
        ]);
    }

    public function test_reset_usage_not_blocked_within_24_hours(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo('configs.reset_usage_own');
        
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
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'panel_user_id' => 'testuser',
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'meta' => [
                'settled_usage_bytes' => 0,
                'last_reset_at' => now()->subHours(12)->toDateTimeString(), // Reset 12 hours ago
            ],
            'created_by' => $user->id,
        ]);

        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/user/testuser/reset' => Http::response(['success' => true], 200),
        ]);

        // Reset is now allowed even within 24 hours (no cooldown)
        $response = $this->actingAs($user)->post(route('reseller.configs.resetUsage', $config));

        // Should succeed (either success or warning for remote panel failure)
        $this->assertTrue(
            session()->has('success') || session()->has('warning'),
            'Expected reset to succeed without 24-hour cooldown'
        );
        
        // Verify usage WAS reset
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);
        $this->assertEquals(1 * 1024 * 1024 * 1024, $config->getSettledUsageBytes());
    }

    public function test_reset_usage_works_regardless_of_timing(): void
    {
        $this->withoutMiddleware();

        $user = User::factory()->create();
        $user->givePermissionTo('configs.reset_usage_own');
        
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
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'panel_user_id' => 'testuser',
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'meta' => [
                'settled_usage_bytes' => 2 * 1024 * 1024 * 1024,
                'last_reset_at' => now()->subHours(25)->toDateTimeString(), // Reset 25 hours ago (no longer matters)
            ],
            'created_by' => $user->id,
        ]);

        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/user/testuser/reset' => Http::response(['success' => true], 200),
        ]);

        // Reset should work regardless of when last reset was
        $response = $this->actingAs($user)->post(route('reseller.configs.resetUsage', $config));

        // Should succeed (either success or warning for remote panel failure)
        $this->assertTrue(
            session()->has('success') || session()->has('warning'),
            'Expected reset to succeed without timing restrictions'
        );
        
        // Verify usage was reset and settled
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes);
        $this->assertEquals(3 * 1024 * 1024 * 1024, $config->getSettledUsageBytes()); // 2 GB previous + 1 GB new
    }

    public function test_total_usage_includes_settled(): void
    {
        // Create a panel
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzban',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Create a reseller
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create configs with settled usage
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
            'meta' => [
                'settled_usage_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB settled
            ],
            'expires_at' => now()->addDays(30),
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'panel_user_id' => 'testuser2',
            'status' => 'active',
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB
            'meta' => [
                'settled_usage_bytes' => 4 * 1024 * 1024 * 1024, // 4 GB settled
            ],
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/user/testuser1' => Http::response(['used_traffic' => 2 * 1024 * 1024 * 1024], 200),
            '*/api/user/testuser2' => Http::response(['used_traffic' => 1 * 1024 * 1024 * 1024], 200),
        ]);

        // Run sync job
        $job = new SyncResellerUsageJob();
        $job->handle();

        // Verify reseller's total usage includes settled bytes
        $reseller->refresh();
        // Total should be: (2 GB + 3 GB settled) + (1 GB + 4 GB settled) = 10 GB
        $this->assertEquals(10 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);
    }
}
