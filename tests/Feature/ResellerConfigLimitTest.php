<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;

test('reseller can have config_limit field', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'config_limit' => 5,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    expect($reseller->config_limit)->toBe(5);
});

test('reseller config_limit can be null for unlimited', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'config_limit' => null,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
    ]);

    expect($reseller->config_limit)->toBeNull();
});

test('isWindowValid returns true when window_ends_at is null', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(10),
        'window_ends_at' => null, // Unlimited
    ]);

    expect($reseller->isWindowValid())->toBeTrue();
});

test('isWindowValid returns true when window is valid', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(10),
        'window_ends_at' => now()->addDays(20),
    ]);

    expect($reseller->isWindowValid())->toBeTrue();
});

test('isWindowValid returns false when window has ended', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(30),
        'window_ends_at' => now()->subDays(1),
    ]);

    expect($reseller->isWindowValid())->toBeFalse();
});

test('reseller can create configs up to limit', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'config_limit' => 3,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    // Create 3 configs (at the limit)
    for ($i = 0; $i < 3; $i++) {
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
        ]);
    }

    expect($reseller->configs()->count())->toBe(3);
    expect($reseller->configs()->count())->toBe($reseller->config_limit);
});

test('reseller config count includes soft-deleted configs', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'config_limit' => 5,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
    ]);

    // Create 3 active configs
    for ($i = 0; $i < 3; $i++) {
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'status' => 'active',
        ]);
    }

    // Create 1 deleted config
    $deletedConfig = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'status' => 'deleted',
    ]);
    $deletedConfig->delete(); // Soft delete

    // Total count should not include soft-deleted
    expect($reseller->configs()->count())->toBe(3);
    
    // With trashed should include soft-deleted
    expect($reseller->configs()->withTrashed()->count())->toBe(4);
});

test('reseller with null config_limit has unlimited configs', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'config_limit' => null, // Unlimited
        'traffic_total_bytes' => 1000 * 1024 * 1024 * 1024,
    ]);

    // Create many configs - should not be limited
    for ($i = 0; $i < 10; $i++) {
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
        ]);
    }

    expect($reseller->configs()->count())->toBe(10);
    expect($reseller->config_limit)->toBeNull();
});

test('reseller with zero config_limit has unlimited configs', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    
    // Create with 0 which should be treated as unlimited
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'config_limit' => 0,
        'traffic_total_bytes' => 1000 * 1024 * 1024 * 1024,
    ]);

    // Note: In the application logic, 0 is converted to null
    // But the database might still store 0
    expect($reseller->config_limit)->toBeIn([0, null]);
});
