<?php

namespace Tests\Unit;

use App\Models\Panel;
use App\Models\ResellerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerProvisioner;
use Tests\TestCase;

class ResellerProvisionerTelemetryTest extends TestCase
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

    public function test_enable_user_returns_telemetry_array_on_success(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        $credentials = $panel->getCredentials();

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->enableUser('marzneshin', $credentials, 'test_user');

        // Assert telemetry structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('last_error', $result);

        // Assert success values
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertNull($result['last_error']);
    }

    public function test_enable_user_returns_telemetry_array_on_failure(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response(['error' => 'Panel error'], 500),
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        $credentials = $panel->getCredentials();

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->enableUser('marzneshin', $credentials, 'test_user');

        // Assert telemetry structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('last_error', $result);

        // Assert failure values
        $this->assertFalse($result['success']);
        $this->assertEquals(3, $result['attempts']);
        $this->assertNotNull($result['last_error']);
    }

    public function test_disable_user_returns_telemetry_array_on_success(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response(['status' => 'disabled'], 200),
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        $credentials = $panel->getCredentials();

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->disableUser('marzneshin', $credentials, 'test_user');

        // Assert telemetry structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('last_error', $result);

        // Assert success values
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertNull($result['last_error']);
    }

    public function test_disable_user_returns_telemetry_array_on_failure(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response(['error' => 'Panel error'], 500),
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        $credentials = $panel->getCredentials();

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->disableUser('marzneshin', $credentials, 'test_user');

        // Assert telemetry structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('last_error', $result);

        // Assert failure values
        $this->assertFalse($result['success']);
        $this->assertEquals(3, $result['attempts']);
        $this->assertNotNull($result['last_error']);
    }

    public function test_enable_config_returns_telemetry_array_on_success(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        
        $config = ResellerConfig::factory()->create([
            'panel_id' => $panel->id,
            'panel_user_id' => 'test_user',
            'status' => 'disabled',
        ]);

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->enableConfig($config);

        // Assert telemetry structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('last_error', $result);

        // Assert success values
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['attempts']);
        $this->assertNull($result['last_error']);
    }

    public function test_enable_config_returns_telemetry_array_for_missing_panel(): void
    {
        $config = ResellerConfig::factory()->create([
            'panel_id' => null, // No panel
            'panel_user_id' => 'test_user',
        ]);

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->enableConfig($config);

        // Assert telemetry structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('last_error', $result);

        // Assert failure values
        $this->assertFalse($result['success']);
        $this->assertEquals(0, $result['attempts']);
        $this->assertStringContainsString('Missing panel_id', $result['last_error']);
    }

    public function test_disable_config_returns_telemetry_array_on_success(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response(['status' => 'disabled'], 200),
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        
        $config = ResellerConfig::factory()->create([
            'panel_id' => $panel->id,
            'panel_user_id' => 'test_user',
            'status' => 'active',
        ]);

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->disableConfig($config);

        // Assert telemetry structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('last_error', $result);

        // Assert success values
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['attempts']);
        $this->assertNull($result['last_error']);
    }

    public function test_disable_config_returns_telemetry_array_for_missing_panel(): void
    {
        $config = ResellerConfig::factory()->create([
            'panel_id' => null, // No panel
            'panel_user_id' => 'test_user',
        ]);

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->disableConfig($config);

        // Assert telemetry structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('last_error', $result);

        // Assert failure values
        $this->assertFalse($result['success']);
        $this->assertEquals(0, $result['attempts']);
        $this->assertStringContainsString('Missing panel_id', $result['last_error']);
    }

    public function test_enable_user_for_marzban_returns_telemetry(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/user/*' => Http::response(['username' => 'test_user', 'status' => 'active'], 200),
        ]);

        $panel = Panel::factory()->marzban()->create();
        $credentials = $panel->getCredentials();

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->enableUser('marzban', $credentials, 'test_user');

        // Assert telemetry structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertArrayHasKey('last_error', $result);
    }

    // Note: XUI enable/disable not fully implemented in XUIService yet
    // The service lacks the updateUser method needed for enable/disable operations
}
