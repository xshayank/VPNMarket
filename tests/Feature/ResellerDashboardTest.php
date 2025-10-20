<?php

use App\Models\Reseller;
use App\Models\User;

test('non reseller cannot access reseller dashboard', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(403);
});

test('plan based reseller can access dashboard', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertViewIs('reseller::dashboard');
    $response->assertViewHas('reseller');
    $response->assertViewHas('stats');
});

test('traffic based reseller can access dashboard', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertViewIs('reseller::dashboard');
});

test('suspended reseller cannot access dashboard', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'suspended',
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(403);
});

test('traffic based reseller with unlimited config limit shows نامحدود', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'config_limit' => null,
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertSee('نامحدود');
    $response->assertViewHas('stats', function ($stats) {
        return $stats['is_unlimited_limit'] === true && $stats['configs_remaining'] === null;
    });
});

test('traffic based reseller with zero config limit shows نامحدود', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'config_limit' => 0,
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertSee('نامحدود');
    $response->assertViewHas('stats', function ($stats) {
        return $stats['is_unlimited_limit'] === true && $stats['configs_remaining'] === null;
    });
});

test('traffic based reseller with config limit shows remaining count', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'config_limit' => 10,
    ]);

    // Create 7 configs for this reseller
    \App\Models\ResellerConfig::factory()->count(7)->create([
        'reseller_id' => $reseller->id,
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertSee('3 از 10', false);
    $response->assertSee('باقیمانده', false);
    $response->assertSee('محدودیت کانفیگ', false);
    $response->assertViewHas('stats', function ($stats) {
        return $stats['config_limit'] === 10 
            && $stats['total_configs'] === 7 
            && $stats['configs_remaining'] === 3
            && $stats['is_unlimited_limit'] === false;
    });
});

test('traffic based reseller with all configs used shows zero remaining', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'config_limit' => 5,
    ]);

    // Create 5 configs (all used up)
    \App\Models\ResellerConfig::factory()->count(5)->create([
        'reseller_id' => $reseller->id,
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertSee('0 از 5', false);
    $response->assertSee('باقیمانده', false);
    $response->assertViewHas('stats', function ($stats) {
        return $stats['config_limit'] === 5 
            && $stats['total_configs'] === 5 
            && $stats['configs_remaining'] === 0;
    });
});

test('soft deleted configs do not reduce remaining count', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'config_limit' => 10,
    ]);

    // Create 5 active configs
    \App\Models\ResellerConfig::factory()->count(5)->create([
        'reseller_id' => $reseller->id,
    ]);

    // Create 2 soft-deleted configs
    \App\Models\ResellerConfig::factory()->count(2)->create([
        'reseller_id' => $reseller->id,
        'deleted_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertSee('5 از 10', false);
    $response->assertSee('باقیمانده', false);
    $response->assertViewHas('stats', function ($stats) {
        return $stats['config_limit'] === 10 
            && $stats['total_configs'] === 5  // Only non-deleted configs
            && $stats['configs_remaining'] === 5;
    });
});

