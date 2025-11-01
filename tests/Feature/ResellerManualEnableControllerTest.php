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
use Tests\TestCase;

class ResellerManualEnableControllerTest extends TestCase
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

    public function test_manual_enable_succeeds_with_telemetry(): void
    {
        // Mock successful panel API responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
        ]);

        // Create test data
        $panel = Panel::factory()->marzneshin()->create();
        
        $user = User::factory()->create();
        
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(1),
            'window_ends_at' => now()->addDays(30),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'test_user',
            'status' => 'disabled',
            'disabled_at' => now()->subHour(),
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024, // 5GB
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Authenticate and make request
        $response = $this->actingAs($user)
            ->post(route('reseller.configs.enable', $config));

        // Assert successful response
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Config enabled successfully.');

        // Assert config status updated
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertNull($config->disabled_at);

        // Assert event created with telemetry
        $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'manual_enabled')
            ->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->meta['remote_success']);
        $this->assertEquals(1, $event->meta['attempts']);
        $this->assertNull($event->meta['last_error']);
        $this->assertEquals($panel->id, $event->meta['panel_id']);
        $this->assertEquals('marzneshin', $event->meta['panel_type_used']);
    }

    public function test_manual_enable_handles_panel_failure_gracefully(): void
    {
        // Mock failed panel API responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/enable' => Http::response(['error' => 'Panel error'], 500),
        ]);

        // Create test data
        $panel = Panel::factory()->marzneshin()->create();
        
        $user = User::factory()->create();
        
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
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
            'panel_user_id' => 'test_user',
            'status' => 'disabled',
            'disabled_at' => now()->subHour(),
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Authenticate and make request
        $response = $this->actingAs($user)
            ->post(route('reseller.configs.enable', $config));

        // Assert returns warning (not 500)
        $response->assertRedirect();
        $response->assertSessionHas('warning');

        // Assert local state updated despite remote failure
        $config->refresh();
        $this->assertEquals('active', $config->status);
        $this->assertNull($config->disabled_at);

        // Assert event includes failure telemetry
        $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'manual_enabled')
            ->first();

        $this->assertNotNull($event);
        $this->assertFalse($event->meta['remote_success']);
        $this->assertEquals(3, $event->meta['attempts']); // Should retry 3 times
        $this->assertNotNull($event->meta['last_error']);
    }

    public function test_manual_disable_succeeds_with_telemetry(): void
    {
        // Mock successful panel API responses
        Http::fake([
            '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/api/users/*/disable' => Http::response(['status' => 'disabled'], 200),
        ]);

        // Create test data
        $panel = Panel::factory()->marzneshin()->create();
        
        $user = User::factory()->create();
        
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'test_user',
            'status' => 'active',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 0,
            'expires_at' => now()->addDays(30),
        ]);

        // Authenticate and make request
        $response = $this->actingAs($user)
            ->post(route('reseller.configs.disable', $config));

        // Assert successful response
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Config disabled successfully.');

        // Assert config status updated
        $config->refresh();
        $this->assertEquals('disabled', $config->status);
        $this->assertNotNull($config->disabled_at);

        // Assert event created with telemetry
        $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
            ->where('type', 'manual_disabled')
            ->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->meta['remote_success']);
        $this->assertEquals(1, $event->meta['attempts']);
        $this->assertNull($event->meta['last_error']);
    }

    public function test_enable_returns_error_when_config_already_active(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
        ]);
        
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'status' => 'active', // Already active
        ]);

        $response = $this->actingAs($user)
            ->post(route('reseller.configs.enable', $config));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Config is not disabled.');
    }

    public function test_disable_returns_error_when_config_not_active(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
        ]);
        
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'status' => 'disabled', // Not active
        ]);

        $response = $this->actingAs($user)
            ->post(route('reseller.configs.disable', $config));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Config is not active.');
    }

    public function test_enable_forbidden_for_wrong_reseller(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        
        $reseller1 = Reseller::factory()->create([
            'user_id' => $user1->id,
            'type' => 'traffic',
            'status' => 'active',
        ]);
        $reseller2 = Reseller::factory()->create([
            'user_id' => $user2->id,
            'type' => 'traffic',
            'status' => 'active',
        ]);
        
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller2->id, // Different reseller
            'status' => 'disabled',
        ]);

        $response = $this->actingAs($user1)
            ->post(route('reseller.configs.enable', $config));

        $response->assertStatus(403);
    }
}
