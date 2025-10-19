<?php

use App\Models\Plan;
use App\Models\Reseller;
use App\Models\User;
use Modules\Reseller\Services\ResellerPricingService;

test('it returns null for non visible plan', function () {
    $pricingService = new ResellerPricingService();
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create(['user_id' => $user->id, 'type' => 'plan']);
    $plan = Plan::factory()->create(['reseller_visible' => false]);

    $result = $pricingService->calculatePrice($reseller, $plan);

    expect($result)->toBeNull();
});

test('it calculates price from plan level fixed price', function () {
    $pricingService = new ResellerPricingService();
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create(['user_id' => $user->id, 'type' => 'plan']);
    $plan = Plan::factory()->create([
        'price' => 100,
        'reseller_visible' => true,
        'reseller_price' => 80,
    ]);

    $result = $pricingService->calculatePrice($reseller, $plan);

    expect($result)->not->toBeNull()
        ->and($result['price'])->toBe(80.0)
        ->and($result['source'])->toBe('plan_price');
});

test('it calculates price from plan level discount percent', function () {
    $pricingService = new ResellerPricingService();
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create(['user_id' => $user->id, 'type' => 'plan']);
    $plan = Plan::factory()->create([
        'price' => 100,
        'reseller_visible' => true,
        'reseller_discount_percent' => 20,
    ]);

    $result = $pricingService->calculatePrice($reseller, $plan);

    expect($result)->not->toBeNull()
        ->and($result['price'])->toBe(80.0)
        ->and($result['source'])->toBe('plan_percent');
});

test('it prioritizes override price over plan price', function () {
    $pricingService = new ResellerPricingService();
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create(['user_id' => $user->id, 'type' => 'plan']);
    $plan = Plan::factory()->create([
        'price' => 100,
        'reseller_visible' => true,
        'reseller_price' => 80,
    ]);

    $reseller->allowedPlans()->attach($plan->id, [
        'override_type' => 'price',
        'override_value' => 70,
        'active' => true,
    ]);

    $result = $pricingService->calculatePrice($reseller, $plan);

    expect($result)->not->toBeNull()
        ->and($result['price'])->toBe(70.0)
        ->and($result['source'])->toBe('override_price');
});

test('it prioritizes override percent over everything', function () {
    $pricingService = new ResellerPricingService();
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create(['user_id' => $user->id, 'type' => 'plan']);
    $plan = Plan::factory()->create([
        'price' => 100,
        'reseller_visible' => true,
        'reseller_price' => 80,
        'reseller_discount_percent' => 20,
    ]);

    $reseller->allowedPlans()->attach($plan->id, [
        'override_type' => 'percent',
        'override_value' => 30,
        'active' => true,
    ]);

    $result = $pricingService->calculatePrice($reseller, $plan);

    expect($result)->not->toBeNull()
        ->and($result['price'])->toBe(70.0)
        ->and($result['source'])->toBe('override_percent');
});
