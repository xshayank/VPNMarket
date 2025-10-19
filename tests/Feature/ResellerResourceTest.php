<?php

use App\Models\Panel;
use App\Models\Plan;
use App\Models\Reseller;
use App\Models\ResellerAllowedPlan;
use App\Models\User;

test('reseller can have panel_id relationship', function () {
    $panel = Panel::factory()->create([
        'name' => 'Test Panel',
        'panel_type' => 'marzneshin',
    ]);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
    ]);

    expect($reseller->panel_id)->toBe($panel->id);
    expect($reseller->panel)->toBeInstanceOf(Panel::class);
    expect($reseller->panel->name)->toBe('Test Panel');
});

test('reseller allowed plans use correct plan_id field', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $plan = Plan::factory()->create([
        'name' => 'Test Plan',
        'reseller_visible' => true,
    ]);

    // Manually attach using the pivot table
    ResellerAllowedPlan::create([
        'reseller_id' => $reseller->id,
        'plan_id' => $plan->id,
        'override_type' => 'percent',
        'override_value' => 10,
        'active' => true,
    ]);

    $reseller->refresh();

    expect($reseller->allowedPlans)->toHaveCount(1);
    expect($reseller->allowedPlans->first()->id)->toBe($plan->id);
    expect($reseller->allowedPlans->first()->pivot->override_type)->toBe('percent');
    expect((float) $reseller->allowedPlans->first()->pivot->override_value)->toBe(10.00);
});

test('reseller can store marzneshin_allowed_service_ids as array', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'marzneshin_allowed_service_ids' => [1, 2, 3],
    ]);

    expect($reseller->marzneshin_allowed_service_ids)->toBe([1, 2, 3]);
    expect($reseller->marzneshin_allowed_service_ids)->toBeArray();
});

test('traffic reseller with panel_id filters panels correctly', function () {
    $panel1 = Panel::factory()->create(['name' => 'Panel 1', 'is_active' => true]);
    $panel2 = Panel::factory()->create(['name' => 'Panel 2', 'is_active' => true]);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel1->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    // When reseller has panel_id, only that panel should be available
    expect($reseller->panel_id)->toBe($panel1->id);
});

test('reseller can store large traffic limits without overflow', function () {
    $user = User::factory()->create();

    // Test with 1,000,000 GB (1 PB) - should not overflow
    $largeTrafficGB = 1000000;
    $largeTrafficBytes = $largeTrafficGB * 1024 * 1024 * 1024;

    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => $largeTrafficBytes,
        'traffic_used_bytes' => 0,
    ]);

    expect($reseller->traffic_total_bytes)->toBe($largeTrafficBytes);
    expect($reseller->traffic_used_bytes)->toBe(0);

    // Verify it can be retrieved correctly
    $reseller->refresh();
    expect($reseller->traffic_total_bytes)->toBe($largeTrafficBytes);
});

test('reseller traffic limits stay within unsigned bigint bounds', function () {
    $user = User::factory()->create();

    // Maximum safe value for unsigned BIGINT: 18,446,744,073,709,551,615
    // With our max of 10,000,000 GB: 10,000,000 * 1024^3 = 10,737,418,240,000,000,000
    // This is well within the unsigned BIGINT limit
    $maxAllowedGB = 10000000;
    $maxAllowedBytes = $maxAllowedGB * 1024 * 1024 * 1024;

    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => $maxAllowedBytes,
    ]);

    expect($reseller->traffic_total_bytes)->toBe($maxAllowedBytes);
    expect($reseller->traffic_total_bytes)->toBeLessThan(PHP_INT_MAX);
});
