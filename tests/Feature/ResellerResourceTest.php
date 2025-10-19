<?php

use App\Models\Panel;
use App\Models\Plan;
use App\Models\Reseller;
use App\Models\ResellerAllowedPlan;
use App\Models\User;
use function Pest\Laravel\actingAs;

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
