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
