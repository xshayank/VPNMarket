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

class ResellerConfigTrafficLimitValidationTest extends TestCase
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

    public function test_config_creation_succeeds_when_traffic_limit_exceeds_remaining_quota(): void
    {
        $user = User::factory()->create();
        $panel = Panel::create([
            'name' => 'Test Panel',
            'url' => 'https://example.com',
            'panel_type' => 'marzneshin',
            'username' => 'admin',
            'password' => 'password',
            'is_active' => true,
            'extra' => ['node_hostname' => 'https://node.example.com'],
        ]);

        // Reseller with low remaining quota
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'panel_id' => $panel->id,
            'traffic_total_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB total
            'traffic_used_bytes' => 4 * 1024 * 1024 * 1024, // 4 GB used
            'window_starts_at' => now(),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Mock HTTP responses for provisioning
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users' => Http::response([
                'username' => 'testuser',
                'subscription_url' => 'https://example.com/sub/testuser',
            ], 201),
        ]);

        // Remaining quota is 1 GB, but we try to create config with 3 GB limit
        // This should now succeed (previously would fail with "Config traffic limit exceeds your remaining traffic quota.")
        $this->actingAs($user);
        $response = $this->post(route('reseller.configs.store'), [
            'panel_id' => $panel->id,
            'traffic_limit_gb' => 3.0, // Exceeds remaining 1 GB
            'expires_days' => 30,
            'comment' => 'Test config',
        ]);

        // Should succeed and redirect to configs index
        $response->assertRedirect(route('reseller.configs.index'));
        $response->assertSessionHas('success', 'Config created successfully.');

        // Verify config was created
        $config = ResellerConfig::where('reseller_id', $reseller->id)->first();
        $this->assertNotNull($config);
        $this->assertEquals(3 * 1024 * 1024 * 1024, $config->traffic_limit_bytes);
        $this->assertEquals('active', $config->status);
    }

    public function test_reseller_suspension_and_config_auto_disable_when_traffic_exhausted(): void
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

        // Reseller with 5 GB total quota
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create multiple configs with total traffic limit > reseller quota
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'active',
            'traffic_limit_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'active',
            'traffic_limit_bytes' => 3 * 1024 * 1024 * 1024, // 3 GB
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Mock HTTP - configs report usage that exhausts reseller quota
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/testuser1' => Http::response([
                'username' => 'testuser1',
                'used_traffic' => 3 * 1024 * 1024 * 1024, // 3 GB
            ], 200),
            '*/api/users/testuser2' => Http::response([
                'username' => 'testuser2',
                'used_traffic' => 3 * 1024 * 1024 * 1024, // 3 GB
            ], 200),
            '*/api/users/*/disable' => Http::response([], 200),
        ]);

        // Run sync job - should detect quota exhaustion
        $job = new SyncResellerUsageJob();
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
        $job->handle($provisioner);

        // Reseller should be suspended
        $reseller->refresh();
        $this->assertEquals('suspended', $reseller->status);
        $this->assertEquals(6 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);

        // All configs should be auto-disabled
        $config1->refresh();
        $config2->refresh();
        $this->assertEquals('disabled', $config1->status);
        $this->assertEquals('disabled', $config2->status);

        // Verify auto_disabled events with correct reason
        $event1 = $config1->events()->where('type', 'auto_disabled')->first();
        $this->assertNotNull($event1);
        $this->assertEquals('reseller_quota_exhausted', $event1->meta['reason']);

        $event2 = $config2->events()->where('type', 'auto_disabled')->first();
        $this->assertNotNull($event2);
        $this->assertEquals('reseller_quota_exhausted', $event2->meta['reason']);
    }

    public function test_config_reenable_after_admin_increases_quota(): void
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

        // Reseller was suspended, now has increased quota
        $reseller = Reseller::factory()->create([
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 20 * 1024 * 1024 * 1024, // Increased to 20 GB
            'traffic_used_bytes' => 6 * 1024 * 1024 * 1024, // 6 GB used
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create configs that were auto-disabled
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'disabled',
            'disabled_at' => now(),
            'traffic_limit_bytes' => 3 * 1024 * 1024 * 1024,
            'usage_bytes' => 3 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
                'disabled_by_reseller_suspension_reason' => 'reseller_quota_exhausted',
            ],
        ]);

        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'disabled',
            'disabled_at' => now(),
            'traffic_limit_bytes' => 3 * 1024 * 1024 * 1024,
            'usage_bytes' => 3 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
                'disabled_by_reseller_suspension_reason' => 'reseller_quota_exhausted',
            ],
        ]);

        // Create auto_disabled events
        ResellerConfigEvent::create([
            'reseller_config_id' => $config1->id,
            'type' => 'auto_disabled',
            'meta' => ['reason' => 'reseller_quota_exhausted'],
        ]);

        ResellerConfigEvent::create([
            'reseller_config_id' => $config2->id,
            'type' => 'auto_disabled',
            'meta' => ['reason' => 'reseller_quota_exhausted'],
        ]);

        // Mock HTTP
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response([], 200),
        ]);

        // Run re-enable job
        $job = new ReenableResellerConfigsJob();
        $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
        $job->handle($provisioner);

        // Reseller should be reactivated
        $reseller->refresh();
        $this->assertEquals('active', $reseller->status);

        // Configs should be re-enabled
        $config1->refresh();
        $config2->refresh();
        $this->assertEquals('active', $config1->status);
        $this->assertEquals('active', $config2->status);
        $this->assertNull($config1->disabled_at);
        $this->assertNull($config2->disabled_at);

        // Verify auto_enabled events
        $enableEvent1 = $config1->events()->where('type', 'auto_enabled')->first();
        $this->assertNotNull($enableEvent1);
        $this->assertEquals('reseller_recovered', $enableEvent1->meta['reason']);

        $enableEvent2 = $config2->events()->where('type', 'auto_enabled')->first();
        $this->assertNotNull($enableEvent2);
        $this->assertEquals('reseller_recovered', $enableEvent2->meta['reason']);
    }
}
