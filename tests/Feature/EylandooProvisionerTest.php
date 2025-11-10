<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use App\Provisioners\EylandooProvisioner;
use App\Provisioners\ProvisionerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
use Tests\TestCase;

class EylandooProvisionerTest extends TestCase
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

    public function test_factory_returns_eylandoo_provisioner_for_eylandoo_panel(): void
    {
        $panel = Panel::create([
            'name' => 'Eylandoo Test Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-token-12345',
            'is_active' => true,
        ]);

        $config = ResellerConfig::factory()->create([
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'test_user',
        ]);

        $provisioner = ProvisionerFactory::forConfig($config);

        $this->assertInstanceOf(EylandooProvisioner::class, $provisioner);
    }

    public function test_eylandoo_provisioner_enables_config_with_correct_api_call(): void
    {
        // Create panel with Eylandoo credentials
        $panel = Panel::create([
            'name' => 'Eylandoo Test Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-token-12345',
            'is_active' => true,
        ]);

        $config = ResellerConfig::factory()->create([
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'test_user_123',
            'status' => 'disabled',
        ]);

        // Mock HTTP responses for Eylandoo API
        Http::fake([
            // First call to get user status (returns disabled)
            '*/api/v1/users/test_user_123' => Http::sequence()
                ->push(['data' => ['status' => 'disabled']], 200)
                ->push(['data' => ['status' => 'active']], 200), // After toggle
            // Enable endpoint (toggle)
            '*/api/v1/users/test_user_123/toggle' => Http::response(null, 200),
        ]);

        // Enable the config using the provisioner
        $provisioner = new EylandooProvisioner;
        $result = $provisioner->enableConfig($config);

        // Verify success
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['attempts']);
        $this->assertNull($result['last_error']);

        // Verify correct API calls were made
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/users/test_user_123') &&
                   $request->hasHeader('X-API-KEY', 'test-api-token-12345');
        });
    }

    public function test_eylandoo_provisioner_is_idempotent_for_already_enabled_config(): void
    {
        $panel = Panel::create([
            'name' => 'Eylandoo Test Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-token-12345',
            'is_active' => true,
        ]);

        $config = ResellerConfig::factory()->create([
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'test_user_456',
            'status' => 'active',
        ]);

        // Mock HTTP: user is already enabled
        Http::fake([
            '*/api/v1/users/test_user_456' => Http::response([
                'data' => ['status' => 'active'],
            ], 200),
        ]);

        // Enable the config (should succeed even though already enabled)
        $provisioner = new EylandooProvisioner;
        $result = $provisioner->enableConfig($config);

        // Verify success (idempotent behavior)
        $this->assertTrue($result['success']);
        $this->assertNull($result['last_error']);

        // Verify toggle endpoint was NOT called (already enabled)
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/toggle');
        });
    }

    public function test_eylandoo_provisioner_retries_on_transient_errors(): void
    {
        $panel = Panel::create([
            'name' => 'Eylandoo Test Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-token-12345',
            'is_active' => true,
        ]);

        $config = ResellerConfig::factory()->create([
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'test_user_retry',
            'status' => 'disabled',
        ]);

        // Mock HTTP: fail twice with 503, then succeed
        Http::fake([
            '*/api/v1/users/test_user_retry' => Http::sequence()
                ->push(['data' => ['status' => 'disabled']], 503) // Attempt 1: service unavailable
                ->push(['data' => ['status' => 'disabled']], 503) // Attempt 2: service unavailable
                ->push(['data' => ['status' => 'disabled']], 200) // Attempt 3: success
                ->push(['data' => ['status' => 'active']], 200),  // After toggle
            '*/api/v1/users/test_user_retry/toggle' => Http::response(null, 200),
        ]);

        // Enable the config (should retry and eventually succeed)
        $provisioner = new EylandooProvisioner;
        $result = $provisioner->enableConfig($config);

        // Verify success after retries
        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['attempts']); // Should have taken 3 attempts
        $this->assertNull($result['last_error']);
    }

    public function test_eylandoo_provisioner_handles_missing_credentials(): void
    {
        // Panel with missing api_token
        $panel = Panel::create([
            'name' => 'Broken Eylandoo Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            // Missing api_token
            'is_active' => true,
        ]);

        $config = ResellerConfig::factory()->create([
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'test_user',
        ]);

        // Enable the config (should fail gracefully)
        $provisioner = new EylandooProvisioner;
        $result = $provisioner->enableConfig($config);

        // Verify failure with clear error message
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('credentials', strtolower($result['last_error']));
    }

    public function test_eylandoo_provisioner_disables_config_correctly(): void
    {
        $panel = Panel::create([
            'name' => 'Eylandoo Test Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-token-12345',
            'is_active' => true,
        ]);

        $config = ResellerConfig::factory()->create([
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'test_user_disable',
            'status' => 'active',
        ]);

        // Mock HTTP responses for Eylandoo API
        Http::fake([
            '*/api/v1/users/test_user_disable' => Http::sequence()
                ->push(['data' => ['status' => 'active']], 200)
                ->push(['data' => ['status' => 'disabled']], 200),
            '*/api/v1/users/test_user_disable/toggle' => Http::response(null, 200),
        ]);

        // Disable the config
        $provisioner = new EylandooProvisioner;
        $result = $provisioner->disableConfig($config);

        // Verify success
        $this->assertTrue($result['success']);
        $this->assertNull($result['last_error']);

        // Verify API was called
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/users/test_user_disable');
        });
    }

    public function test_reenable_job_uses_eylandoo_provisioner_for_eylandoo_configs(): void
    {
        $resellerUser = User::factory()->create();

        // Create Eylandoo panel
        $panel = Panel::create([
            'name' => 'Eylandoo Panel',
            'url' => 'https://eylandoo.example.com',
            'panel_type' => 'eylandoo',
            'api_token' => 'test-api-token-12345',
            'is_active' => true,
        ]);

        // Create reseller with traffic available
        $reseller = Reseller::factory()->create([
            'user_id' => $resellerUser->id,
            'type' => 'traffic',
            'status' => 'suspended',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0, // Reset after top-up
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        // Create config disabled by reseller suspension
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'eylandoo',
            'panel_user_id' => 'eylandoo_user_1',
            'status' => 'disabled',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 2 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'disabled_at' => now()->subHours(2),
            'meta' => [
                'disabled_by_reseller_suspension' => true,
                'disabled_by_reseller_suspension_reason' => 'reseller_quota_exhausted',
            ],
        ]);

        // Mock Eylandoo API
        Http::fake([
            '*/api/v1/users/eylandoo_user_1' => Http::sequence()
                ->push(['data' => ['status' => 'disabled']], 200)
                ->push(['data' => ['status' => 'active']], 200),
            '*/api/v1/users/eylandoo_user_1/toggle' => Http::response(null, 200),
        ]);

        // Run the re-enable job
        $job = new ReenableResellerConfigsJob($reseller->id);
        $job->handle(new \Modules\Reseller\Services\ResellerProvisioner);

        // Verify config was re-enabled
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertNull($config->disabled_at);

        // Verify Eylandoo API was called with correct headers
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'eylandoo.example.com') &&
                   str_contains($request->url(), '/api/v1/users/') &&
                   $request->hasHeader('X-API-KEY', 'test-api-token-12345');
        });
    }
}
