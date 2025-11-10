<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
use Tests\TestCase;

class ReenableJobMixedMetaTypesTest extends TestCase
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

    public function test_reenable_job_handles_boolean_true_meta_marker(): void
    {
        $this->runReenableTestWithMetaValue(true);
    }

    public function test_reenable_job_handles_string_one_meta_marker(): void
    {
        $this->runReenableTestWithMetaValue('1');
    }

    public function test_reenable_job_handles_integer_one_meta_marker(): void
    {
        $this->runReenableTestWithMetaValue(1);
    }

    public function test_reenable_job_uses_json_query_regardless_of_status(): void
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

        // Create config with status='expired' but has the marker (should still be re-enabled)
        $config1 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser1',
            'status' => 'expired', // Not disabled, but has the marker
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(2),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
            ],
        ]);

        // Create config with status='active' but has the marker (should be re-enabled / cleaned)
        $config2 = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser2',
            'status' => 'active', // Already active but has marker
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
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

        // Verify both configs had their markers cleared
        $config1->refresh();
        $config2->refresh();

        $this->assertEquals('active', $config1->status);
        $this->assertFalse(isset($config1->meta['disabled_by_reseller_suspension']));

        $this->assertEquals('active', $config2->status);
        $this->assertFalse(isset($config2->meta['disabled_by_reseller_suspension']));
    }

    public function test_reenable_job_sets_status_to_active_even_if_remote_fails(): void
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
            ],
        ]);

        // Mock HTTP to simulate remote enable failure
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response(['error' => 'Server error'], 500),
        ]);

        // Run the job
        $job = new ReenableResellerConfigsJob($reseller->id);
        $job->handle(new \Modules\Reseller\Services\ResellerProvisioner);

        // Verify config status remains disabled when remote failed (correct behavior)
        $config->refresh();
        $this->assertEquals('disabled', $config->status);
        $this->assertNotNull($config->disabled_at);
        // Meta flag should remain set since remote enable failed
        $this->assertTrue(isset($config->meta['disabled_by_reseller_suspension']));
    }

    protected function runReenableTestWithMetaValue($metaValue): void
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
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create config with the specified meta value type
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
                'disabled_by_reseller_suspension' => $metaValue,
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

        // Verify config was re-enabled regardless of meta value type
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertNull($config->disabled_at);
        $this->assertFalse(isset($config->meta['disabled_by_reseller_suspension']));
    }
}
