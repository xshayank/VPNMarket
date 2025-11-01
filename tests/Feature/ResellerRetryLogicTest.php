<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerProvisioner;
use Tests\TestCase;

class ResellerRetryLogicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Log to avoid output during tests
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
    }

    public function test_disable_user_succeeds_on_first_attempt(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response(['status' => 'disabled'], 200),
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        $credentials = $panel->getCredentials();

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->disableUser('marzneshin', $credentials, 'test_user');

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertNull($result['last_error']);

        // Should only make 1 attempt
        Http::assertSentCount(2); // 1 login + 1 disable
    }

    public function test_disable_user_succeeds_on_second_attempt(): void
    {
        $callCount = 0;
        
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // First attempt fails
                    return Http::response(['error' => 'Temporary error'], 500);
                }
                // Second attempt succeeds
                return Http::response(['status' => 'disabled'], 200);
            },
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        $credentials = $panel->getCredentials();

        $startTime = microtime(true);
        $provisioner = new ResellerProvisioner();
        $result = $provisioner->disableUser('marzneshin', $credentials, 'test_user');
        $duration = microtime(true) - $startTime;

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['attempts']);
        $this->assertNull($result['last_error']);
        
        // Should have slept ~1 second between attempts
        $this->assertGreaterThanOrEqual(1.0, $duration);
    }

    public function test_disable_user_fails_after_three_attempts(): void
    {
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response(['error' => 'Panel error'], 500),
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        $credentials = $panel->getCredentials();

        $startTime = microtime(true);
        $provisioner = new ResellerProvisioner();
        $result = $provisioner->disableUser('marzneshin', $credentials, 'test_user');
        $duration = microtime(true) - $startTime;

        $this->assertFalse($result['success']);
        $this->assertEquals(3, $result['attempts']);
        $this->assertNotNull($result['last_error']);
        
        // Should have slept ~1s + ~3s = ~4s total between attempts
        $this->assertGreaterThanOrEqual(4.0, $duration);
        
        // May have multiple login + disable attempts (implementation detail)
        // Just verify it failed and attempted 3 times
    }

    public function test_enable_user_succeeds_on_third_attempt(): void
    {
        $callCount = 0;
        
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => function () use (&$callCount) {
                $callCount++;
                if ($callCount < 3) {
                    // First two attempts fail
                    return Http::response(['error' => 'Temporary error'], 500);
                }
                // Third attempt succeeds
                return Http::response(['status' => 'active'], 200);
            },
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        $credentials = $panel->getCredentials();

        $startTime = microtime(true);
        $provisioner = new ResellerProvisioner();
        $result = $provisioner->enableUser('marzneshin', $credentials, 'test_user');
        $duration = microtime(true) - $startTime;

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['attempts']);
        $this->assertNull($result['last_error']);
        
        // Should have slept ~1s + ~3s = ~4s total between attempts
        $this->assertGreaterThanOrEqual(4.0, $duration);
    }

    public function test_retry_with_login_failures(): void
    {
        $loginCount = 0;
        
        Http::fake([
            '*/api/admins/token' => function () use (&$loginCount) {
                $loginCount++;
                if ($loginCount < 3) {
                    return Http::response(['error' => 'Auth failed'], 401);
                }
                return Http::response(['access_token' => 'test-token'], 200);
            },
            '*/api/users/*/disable' => Http::response(['status' => 'disabled'], 200),
        ]);

        $panel = Panel::factory()->marzneshin()->create();
        $credentials = $panel->getCredentials();

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->disableUser('marzneshin', $credentials, 'test_user');

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['attempts']);
        
        // Should have made 3 login attempts + 1 disable
        Http::assertSentCount(4);
    }

    public function test_enable_config_returns_telemetry_for_missing_panel(): void
    {
        $config = ResellerConfig::factory()->create([
            'panel_id' => null, // No panel
            'panel_user_id' => 'test_user',
        ]);

        $provisioner = new ResellerProvisioner();
        $result = $provisioner->enableConfig($config);

        $this->assertFalse($result['success']);
        $this->assertEquals(0, $result['attempts']);
        $this->assertStringContainsString('Missing panel_id', $result['last_error']);
    }

    public function test_rate_limiting_uses_micro_sleeps(): void
    {
        $provisioner = new ResellerProvisioner();
        
        $startTime = microtime(true);
        
        // Simulate 5 operations with rate limiting
        for ($i = 0; $i < 5; $i++) {
            $provisioner->applyRateLimit($i);
        }
        
        $duration = microtime(true) - $startTime;
        
        // 4 micro-sleeps of 333ms each = ~1.33 seconds
        // (first operation has no sleep)
        $this->assertGreaterThanOrEqual(1.3, $duration);
        $this->assertLessThan(1.5, $duration); // Should not take much longer
    }

    public function test_event_includes_retry_telemetry(): void
    {
        $callCount = 0;
        
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response(['error' => 'Temporary error'], 500);
                }
                return Http::response(['status' => 'disabled'], 200);
            },
        ]);

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
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Manually disable to test event creation
        $provisioner = new ResellerProvisioner();
        $result = $provisioner->disableUser($panel->panel_type, $panel->getCredentials(), $config->panel_user_id);

        // Create event similar to what ConfigController does
        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'manual_disabled',
            'meta' => [
                'user_id' => 1,
                'remote_success' => $result['success'],
                'attempts' => $result['attempts'],
                'last_error' => $result['last_error'],
                'panel_id' => $config->panel_id,
                'panel_type_used' => $panel->panel_type,
            ],
        ]);

        $event = $config->events()->where('type', 'manual_disabled')->first();
        
        $this->assertNotNull($event);
        $this->assertTrue($event->meta['remote_success']);
        $this->assertEquals(2, $event->meta['attempts']);
        $this->assertNull($event->meta['last_error']);
        $this->assertEquals($panel->id, $event->meta['panel_id']);
        $this->assertEquals('marzneshin', $event->meta['panel_type_used']);
    }
}
