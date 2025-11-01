<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\User;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;
use Modules\Reseller\Services\ResellerProvisioner;

beforeEach(function () {
    // No beforeEach needed - we'll handle mocking per test
});

test('job re-enables configs when reseller recovers from quota exhaustion', function () {
    // Mock the ResellerProvisioner to avoid actual API calls
    $provisionerMock = Mockery::mock(ResellerProvisioner::class);
    $this->app->bind(ResellerProvisioner::class, fn() => $provisionerMock);
    
    // Create a suspended reseller that now has traffic
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'suspended',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
        'traffic_used_bytes' => 50 * 1024 * 1024 * 1024,   // 50 GB used
        'window_starts_at' => now()->subDays(5),
        'window_ends_at' => now()->addDays(25),
    ]);

    // Create a config that was auto-disabled due to reseller quota exhaustion
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_id' => $panel->id,
        'panel_type' => 'marzban',
        'panel_user_id' => 'test_user_123',
        'disabled_at' => now()->subHours(2),
    ]);

    // Create the auto_disabled event
    ResellerConfigEvent::create([
        'reseller_config_id' => $config->id,
        'type' => 'auto_disabled',
        'meta' => [
            'reason' => 'reseller_quota_exhausted',
        ],
    ]);

    // Mock the enableConfig method to return success array
    $provisionerMock
        ->shouldReceive('enableConfig')
        ->once()
        ->with(Mockery::on(fn($c) => $c->id === $config->id))
        ->andReturn(['success' => true, 'attempts' => 1, 'last_error' => null]);
    
    // Mock the applyRateLimit method
    $provisionerMock
        ->shouldReceive('applyRateLimit')
        ->andReturnNull();

    // Run the job
    ReenableResellerConfigsJob::dispatchSync();

    // Assert reseller is now active
    $reseller->refresh();
    expect($reseller->status)->toBe('active');

    // Assert config is now active
    $config->refresh();
    expect($config->status)->toBe('active');
    expect($config->disabled_at)->toBeNull();

    // Assert auto_enabled event was created
    $enableEvent = ResellerConfigEvent::where('reseller_config_id', $config->id)
        ->where('type', 'auto_enabled')
        ->first();
    
    expect($enableEvent)->not->toBeNull();
    expect($enableEvent->meta['reason'])->toBe('reseller_recovered');
    expect($enableEvent->meta['remote_success'])->toBe(true);
});

test('job re-enables configs when reseller window is extended', function () {
    // Mock the ResellerProvisioner
    $provisionerMock = Mockery::mock(ResellerProvisioner::class);
    $this->app->bind(ResellerProvisioner::class, fn() => $provisionerMock);
    
    // Create a suspended reseller with expired window that's now valid
    $panel = Panel::factory()->create(['panel_type' => 'marzneshin']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'suspended',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 20 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(5),
        'window_ends_at' => now()->addDays(10), // Extended window
    ]);

    // Create configs that were auto-disabled
    $config1 = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_id' => $panel->id,
        'panel_type' => 'marzneshin',
        'panel_user_id' => 'test_user_1',
        'disabled_at' => now()->subHours(1),
    ]);

    $config2 = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_id' => $panel->id,
        'panel_type' => 'marzneshin',
        'panel_user_id' => 'test_user_2',
        'disabled_at' => now()->subHours(1),
    ]);

    // Create auto_disabled events
    ResellerConfigEvent::create([
        'reseller_config_id' => $config1->id,
        'type' => 'auto_disabled',
        'meta' => ['reason' => 'reseller_window_expired'],
    ]);

    ResellerConfigEvent::create([
        'reseller_config_id' => $config2->id,
        'type' => 'auto_disabled',
        'meta' => ['reason' => 'reseller_window_expired'],
    ]);

    // Mock enableConfig for both configs
    $provisionerMock
        ->shouldReceive('enableConfig')
        ->twice()
        ->andReturn(['success' => true, 'attempts' => 1, 'last_error' => null]);
    
    // Mock the applyRateLimit method
    $provisionerMock
        ->shouldReceive('applyRateLimit')
        ->andReturnNull();

    // Run the job
    ReenableResellerConfigsJob::dispatchSync();

    // Assert all configs are active
    $config1->refresh();
    $config2->refresh();
    
    expect($config1->status)->toBe('active');
    expect($config2->status)->toBe('active');
    expect($config1->disabled_at)->toBeNull();
    expect($config2->disabled_at)->toBeNull();
});

test('job does not re-enable manually disabled configs', function () {
    // Mock the ResellerProvisioner
    $provisionerMock = Mockery::mock(ResellerProvisioner::class);
    $this->app->bind(ResellerProvisioner::class, fn() => $provisionerMock);
    
    // Create a recovered reseller
    $panel = Panel::factory()->create(['panel_type' => 'xui']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'suspended',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 30 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(5),
        'window_ends_at' => now()->addDays(25),
    ]);

    // Create a config that was manually disabled
    $manualConfig = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_id' => $panel->id,
        'panel_type' => 'xui',
        'panel_user_id' => 'manual_user',
        'disabled_at' => now()->subHours(3),
    ]);

    // Create a manual_disabled event (not auto_disabled)
    ResellerConfigEvent::create([
        'reseller_config_id' => $manualConfig->id,
        'type' => 'manual_disabled',
        'meta' => ['reason' => 'admin_action'],
    ]);

    // Create an auto-disabled config for comparison
    $autoConfig = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_id' => $panel->id,
        'panel_type' => 'xui',
        'panel_user_id' => 'auto_user',
        'disabled_at' => now()->subHours(2),
    ]);

    ResellerConfigEvent::create([
        'reseller_config_id' => $autoConfig->id,
        'type' => 'auto_disabled',
        'meta' => ['reason' => 'reseller_quota_exhausted'],
    ]);

    // Mock should only be called once (for auto-disabled config)
    $provisionerMock
        ->shouldReceive('enableConfig')
        ->once()
        ->with(Mockery::on(fn($c) => $c->id === $autoConfig->id))
        ->andReturn(['success' => true, 'attempts' => 1, 'last_error' => null]);
    
    // Mock the applyRateLimit method
    $provisionerMock
        ->shouldReceive('applyRateLimit')
        ->andReturnNull();

    // Run the job
    ReenableResellerConfigsJob::dispatchSync();

    // Assert manually disabled config stays disabled
    $manualConfig->refresh();
    expect($manualConfig->status)->toBe('disabled');

    // Assert auto-disabled config is enabled
    $autoConfig->refresh();
    expect($autoConfig->status)->toBe('active');
});

test('job handles remote enable failures gracefully', function () {
    // Mock the ResellerProvisioner
    $provisionerMock = Mockery::mock(ResellerProvisioner::class);
    $this->app->bind(ResellerProvisioner::class, fn() => $provisionerMock);
    
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'suspended',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 40 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(5),
        'window_ends_at' => now()->addDays(25),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_id' => $panel->id,
        'panel_type' => 'marzban',
        'panel_user_id' => 'failing_user',
        'disabled_at' => now()->subHours(1),
    ]);

    ResellerConfigEvent::create([
        'reseller_config_id' => $config->id,
        'type' => 'auto_disabled',
        'meta' => ['reason' => 'reseller_quota_exhausted'],
    ]);

    // Mock enableConfig to fail
    $provisionerMock
        ->shouldReceive('enableConfig')
        ->once()
        ->andReturn(['success' => false, 'attempts' => 3, 'last_error' => 'API timeout']);
    
    // Mock the applyRateLimit method
    $provisionerMock
        ->shouldReceive('applyRateLimit')
        ->andReturnNull();

    // Run the job
    ReenableResellerConfigsJob::dispatchSync();

    // Assert local config is still set to active despite remote failure
    $config->refresh();
    expect($config->status)->toBe('active');
    expect($config->disabled_at)->toBeNull();

    // Assert event was created with remote_success=false
    $enableEvent = ResellerConfigEvent::where('reseller_config_id', $config->id)
        ->where('type', 'auto_enabled')
        ->first();
    
    expect($enableEvent)->not->toBeNull();
    expect($enableEvent->meta['remote_success'])->toBe(false);
});

test('job skips resellers without traffic or expired window', function () {
    // Mock the ResellerProvisioner
    $provisionerMock = Mockery::mock(ResellerProvisioner::class);
    $this->app->bind(ResellerProvisioner::class, fn() => $provisionerMock);
    
    $panel = Panel::factory()->create();
    $user = User::factory()->create();
    
    // Create a suspended reseller with no traffic remaining (over grace)
    $noTrafficReseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'suspended',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100GB
        'traffic_used_bytes' => 105 * 1024 * 1024 * 1024, // 105GB used (over grace of 2GB)
        'window_starts_at' => now()->subDays(5),
        'window_ends_at' => now()->addDays(25),
    ]);

    // Create a suspended reseller with expired window
    $expiredWindowReseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'type' => 'traffic',
        'status' => 'suspended',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 50 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(35),
        'window_ends_at' => now()->subDays(5), // Expired
    ]);

    // Neither should be reactivated
    $provisionerMock->shouldNotReceive('enableConfig');

    // Run the job
    ReenableResellerConfigsJob::dispatchSync();

    // Assert resellers stay suspended
    $noTrafficReseller->refresh();
    $expiredWindowReseller->refresh();
    
    expect($noTrafficReseller->status)->toBe('suspended');
    expect($expiredWindowReseller->status)->toBe('suspended');
});

test('job respects rate limiting of 3 configs per second', function () {
    // Mock the ResellerProvisioner
    $provisionerMock = Mockery::mock(ResellerProvisioner::class);
    $this->app->bind(ResellerProvisioner::class, fn() => $provisionerMock);
    
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $user = User::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'suspended',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 20 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(5),
        'window_ends_at' => now()->addDays(25),
    ]);

    // Create 5 configs to test rate limiting
    $configs = [];
    for ($i = 0; $i < 5; $i++) {
        $config = ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'status' => 'disabled',
            'panel_id' => $panel->id,
            'panel_type' => 'marzban',
            'panel_user_id' => "user_{$i}",
            'disabled_at' => now()->subHours(1),
        ]);

        ResellerConfigEvent::create([
            'reseller_config_id' => $config->id,
            'type' => 'auto_disabled',
            'meta' => ['reason' => 'reseller_quota_exhausted'],
        ]);

        $configs[] = $config;
    }

    // Mock enableConfig to succeed for all
    $provisionerMock
        ->shouldReceive('enableConfig')
        ->times(5)
        ->andReturn(['success' => true, 'attempts' => 1, 'last_error' => null]);
    
    // Mock the applyRateLimit method to actually sleep for testing
    $provisionerMock
        ->shouldReceive('applyRateLimit')
        ->andReturnUsing(function ($count) {
            if ($count > 0) {
                usleep(333333); // 333ms between operations
            }
        });

    $startTime = microtime(true);
    
    // Run the job
    ReenableResellerConfigsJob::dispatchSync();
    
    $endTime = microtime(true);
    $duration = $endTime - $startTime;

    // With 5 configs and 333ms between each (starting from 2nd): 4 * 333ms = ~1.33 seconds
    expect($duration)->toBeGreaterThanOrEqual(1.3);

    // All configs should be enabled
    foreach ($configs as $config) {
        $config->refresh();
        expect($config->status)->toBe('active');
    }
});

test('enableConfig method returns false for config without panel_id', function () {
    $config = ResellerConfig::factory()->create([
        'panel_id' => null,
        'panel_type' => 'marzban',
        'panel_user_id' => 'test_user',
    ]);

    // Create actual provisioner (not mocked)
    $provisioner = new ResellerProvisioner();

    $result = $provisioner->enableConfig($config);
    expect($result)->toBeArray();
    expect($result['success'])->toBe(false);
    expect($result['last_error'])->toContain('Missing panel_id');
});
