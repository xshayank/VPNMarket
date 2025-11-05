<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResellerConfigBackToBackResetTest extends TestCase
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

        // Mock HTTP requests to prevent actual API calls
        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);

        // Mock ResellerProvisioner to simulate successful remote resets
        $this->mock(\Modules\Reseller\Services\ResellerProvisioner::class, function ($mock) {
            $mock->shouldReceive('resetUserUsage')
                ->andReturn([
                    'success' => true,
                    'attempts' => 1,
                    'last_error' => null,
                ]);
        });
    }

    public function test_reseller_can_reset_usage_twice_back_to_back(): void
    {
        // Create permissions
        $resetUsageOwnPermission = Permission::create([
            'name' => 'configs.reset_usage_own',
            'guard_name' => 'web',
        ]);

        // Create role and assign permission
        $resellerRole = Role::create(['name' => 'reseller', 'guard_name' => 'web']);
        $resellerRole->givePermissionTo($resetUsageOwnPermission);

        // Create user with reseller
        $user = User::factory()->create();
        $user->assignRole($resellerRole);

        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'panel_id' => $panel->id,
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now(),
            'window_ends_at' => now()->addDays(30),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'usage_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB used
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB limit
            'expires_at' => now()->addDays(10),
        ]);

        // First reset
        $response = $this->actingAs($user)->post(route('reseller.configs.resetUsage', $config));
        
        // Assert first reset succeeded (either success or warning for remote panel failure is acceptable)
        $response->assertRedirect();
        $this->assertTrue(
            session()->has('success') || session()->has('warning'),
            'Expected either success or warning session key after reset'
        );
        
        // Verify config was reset
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes, 'First reset: usage_bytes should be 0');
        $this->assertEquals(5 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'), 'First reset: settled_usage_bytes should be 5 GB');
        
        // Verify reseller aggregate includes settled usage
        $reseller->refresh();
        $this->assertEquals(5 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes, 'First reset: reseller aggregate should be 5 GB');

        // Add more usage to the config
        $config->update(['usage_bytes' => 3 * 1024 * 1024 * 1024]); // Add 3 GB more usage

        // Second reset (immediately, without waiting 24 hours)
        $response = $this->actingAs($user)->post(route('reseller.configs.resetUsage', $config));
        
        // Assert second reset also succeeded (no 24h cooldown enforced)
        $response->assertRedirect();
        $this->assertTrue(
            session()->has('success') || session()->has('warning'),
            'Expected either success or warning session key after second reset'
        );
        $response->assertSessionMissing('error');
        
        // Verify config was reset again
        $config->refresh();
        $this->assertEquals(0, $config->usage_bytes, 'Second reset: usage_bytes should be 0');
        $this->assertEquals(8 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'), 'Second reset: settled_usage_bytes should accumulate to 8 GB (5 + 3)');
        
        // Verify reseller aggregate accumulated correctly
        $reseller->refresh();
        $this->assertEquals(8 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes, 'Second reset: reseller aggregate should be 8 GB');
    }

    public function test_multiple_consecutive_resets_accumulate_correctly(): void
    {
        // Create permissions
        $resetUsageOwnPermission = Permission::create([
            'name' => 'configs.reset_usage_own',
            'guard_name' => 'web',
        ]);

        // Create role and assign permission
        $resellerRole = Role::create(['name' => 'reseller', 'guard_name' => 'web']);
        $resellerRole->givePermissionTo($resetUsageOwnPermission);

        // Create user with reseller
        $user = User::factory()->create();
        $user->assignRole($resellerRole);

        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'panel_id' => $panel->id,
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'traffic_used_bytes' => 0,
            'window_starts_at' => now(),
            'window_ends_at' => now()->addDays(30),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(10),
        ]);

        $expectedSettled = 0;

        // Perform 3 consecutive resets
        for ($i = 1; $i <= 3; $i++) {
            $currentUsage = $config->usage_bytes;
            $expectedSettled += $currentUsage;

            // Reset
            $response = $this->actingAs($user)->post(route('reseller.configs.resetUsage', $config));
            $response->assertRedirect();
            $this->assertTrue(
                session()->has('success') || session()->has('warning'),
                "Reset $i: Expected either success or warning session key after reset"
            );

            // Verify
            $config->refresh();
            $this->assertEquals(0, $config->usage_bytes, "Reset $i: usage_bytes should be 0");
            $this->assertEquals($expectedSettled, data_get($config->meta, 'settled_usage_bytes'), "Reset $i: settled_usage_bytes should be $expectedSettled");

            // Add more usage for next iteration (except on last iteration)
            if ($i < 3) {
                $config->update(['usage_bytes' => $i * 1024 * 1024 * 1024]); // Add i GB
            }
        }

        // Final verification: settled should be 2 + 1 + 2 = 5 GB
        $this->assertEquals(5 * 1024 * 1024 * 1024, data_get($config->meta, 'settled_usage_bytes'));
        
        // Verify reseller aggregate
        $reseller->refresh();
        $this->assertEquals(5 * 1024 * 1024 * 1024, $reseller->traffic_used_bytes);
    }

    public function test_last_reset_at_is_still_recorded_for_audit(): void
    {
        // Create permissions
        $resetUsageOwnPermission = Permission::create([
            'name' => 'configs.reset_usage_own',
            'guard_name' => 'web',
        ]);

        // Create role and assign permission
        $resellerRole = Role::create(['name' => 'reseller', 'guard_name' => 'web']);
        $resellerRole->givePermissionTo($resetUsageOwnPermission);

        // Create user with reseller
        $user = User::factory()->create();
        $user->assignRole($resellerRole);

        $panel = Panel::factory()->create([
            'panel_type' => 'marzneshin',
            'is_active' => true,
        ]);

        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'panel_id' => $panel->id,
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'window_starts_at' => now(),
            'window_ends_at' => now()->addDays(30),
        ]);

        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'panel_type' => 'marzneshin',
            'panel_user_id' => 'testuser',
            'status' => 'active',
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(10),
        ]);

        // Perform reset
        $beforeReset = now();
        $response = $this->actingAs($user)->post(route('reseller.configs.resetUsage', $config));
        $afterReset = now();

        $response->assertRedirect();
        $this->assertTrue(
            session()->has('success') || session()->has('warning'),
            'Expected either success or warning session key after reset'
        );

        // Verify last_reset_at was recorded
        $config->refresh();
        $lastResetAt = data_get($config->meta, 'last_reset_at');
        
        $this->assertNotNull($lastResetAt, 'last_reset_at should be recorded for audit purposes');
        
        // Verify it's a recent timestamp (within the test timeframe with some buffer)
        $lastResetCarbon = \Carbon\Carbon::parse($lastResetAt);
        $this->assertTrue($lastResetCarbon->between($beforeReset->subSeconds(2), $afterReset->addSeconds(2)), 
            'last_reset_at should be set to current time');
    }
}
