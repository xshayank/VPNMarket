<?php

use App\Models\Reseller;
use App\Models\User;

test('reseller user sees reseller navigation on dashboard', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);
    // Check that the reseller nav partial is rendered by looking for unique elements
    $response->assertSee('داشبورد', false); // Dashboard link in reseller nav
});

test('reseller user sees reseller navigation on reseller dashboard', function () {
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
    // Reseller dashboard should have both the nav and back button
    $response->assertSee('بازگشت', false);
});

test('normal user does not see reseller navigation', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertStatus(200);
    // The reseller nav should not be present - checking for specific reseller nav elements
    // We can't easily check for absence of entire navigation, but we can verify
    // the page renders successfully without errors
    $response->assertDontSee('داشبورد', false);
});

test('guest user does not see reseller navigation', function () {
    $response = $this->get('/dashboard');

    // Guests are redirected to login
    $response->assertRedirect('/login');
});

test('plan based reseller sees plan-specific navigation items', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    // Plan-based resellers should see Plans and Orders links
    $response->assertSee('پلن‌ها', false);
    $response->assertSee('سفارشات', false);
});

test('traffic based reseller sees config-specific navigation items', function () {
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
    // Traffic-based resellers should see Configs link
    $response->assertSee('کانفیگ‌ها', false);
});

test('back button appears on reseller config pages', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $response = $this->actingAs($user)->get(route('reseller.configs.index'));

    $response->assertStatus(200);
    $response->assertSee('بازگشت', false);
    $response->assertSee('javascript:history.back()', false);
});

test('back button appears on reseller plan pages', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get(route('reseller.plans.index'));

    $response->assertStatus(200);
    $response->assertSee('بازگشت', false);
});
