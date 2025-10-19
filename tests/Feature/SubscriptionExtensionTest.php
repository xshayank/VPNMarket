<?php

use App\Models\Order;
use App\Models\Panel;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a test panel
    $this->panel = Panel::factory()->create([
        'name' => 'Test Panel',
        'panel_type' => 'marzban',
        'url' => 'http://test-panel.local',
        'username' => 'admin',
        'password' => 'password',
        'extra' => [
            'node_hostname' => 'http://node.test',
        ],
    ]);

    // Create a test plan
    $this->plan = Plan::factory()->create([
        'name' => 'Test Plan',
        'price' => 50000,
        'volume_gb' => 10,
        'duration_days' => 30,
        'panel_id' => $this->panel->id,
        'is_active' => true,
    ]);

    // Create a test user with balance
    $this->user = User::factory()->create([
        'balance' => 100000,
    ]);
});

test('user can extend subscription when 3 days or less remaining', function () {
    $this->actingAs($this->user);

    // Create a paid order that expires in 2 days
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(2),
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1GB used
        'panel_user_id' => 'user_' . $this->user->id . '_order_' . 1,
        'config_details' => 'test-config',
    ]);

    // Access the extension page
    $response = $this->get(route('subscription.extend.show', $order->id));
    
    $response->assertStatus(200);
    $response->assertSee('تمدید سرویس');
    $response->assertSee('افزایش زمان');
});

test('user cannot extend subscription when more than 3 days remaining and has traffic', function () {
    $this->actingAs($this->user);

    // Create a paid order that expires in 10 days with plenty of traffic
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(10),
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1GB used, 9GB remaining
        'panel_user_id' => 'user_' . $this->user->id . '_order_' . 1,
        'config_details' => 'test-config',
    ]);

    // Access the extension page
    $response = $this->get(route('subscription.extend.show', $order->id));
    
    $response->assertStatus(200);
    $response->assertSee('تمدید مجاز نیست');
    $response->assertSee('3 روز پایانی');
});

test('user can extend subscription when out of traffic', function () {
    $this->actingAs($this->user);

    // Create a paid order that has used all traffic
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(10),
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 10 * 1024 * 1024 * 1024, // All traffic used
        'panel_user_id' => 'user_' . $this->user->id . '_order_' . 1,
        'config_details' => 'test-config',
    ]);

    // Access the extension page
    $response = $this->get(route('subscription.extend.show', $order->id));
    
    $response->assertStatus(200);
    $response->assertSee('تمدید سرویس');
    $response->assertSee('بازنشانی کامل');
    $response->assertSee('ترافیک شما تمام شده است');
});

test('user can extend subscription when expired', function () {
    $this->actingAs($this->user);

    // Create a paid order that has expired
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->subDays(5),
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 5 * 1024 * 1024 * 1024,
        'panel_user_id' => 'user_' . $this->user->id . '_order_' . 1,
        'config_details' => 'test-config',
    ]);

    // Access the extension page
    $response = $this->get(route('subscription.extend.show', $order->id));
    
    $response->assertStatus(200);
    $response->assertSee('تمدید سرویس');
    $response->assertSee('بازنشانی کامل');
    $response->assertSee('منقضی شده است');
});

test('extension denied when insufficient wallet balance', function () {
    // Create user with insufficient balance
    $poorUser = User::factory()->create([
        'balance' => 1000, // Less than plan price of 50000
    ]);
    
    $this->actingAs($poorUser);

    // Create a paid order that expires in 2 days
    $order = Order::factory()->create([
        'user_id' => $poorUser->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(2),
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
        'panel_user_id' => 'user_' . $poorUser->id . '_order_' . 1,
        'config_details' => 'test-config',
    ]);

    // Access the extension page
    $response = $this->get(route('subscription.extend.show', $order->id));
    
    $response->assertStatus(200);
    $response->assertSee('موجودی کیف پول شما کافی نیست');
    $response->assertSee('شارژ کیف پول');
});

test('user cannot access another users subscription extension', function () {
    $this->actingAs($this->user);
    
    $otherUser = User::factory()->create();

    // Create an order for another user
    $order = Order::factory()->create([
        'user_id' => $otherUser->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(2),
    ]);

    // Try to access the extension page
    $response = $this->get(route('subscription.extend.show', $order->id));
    
    $response->assertStatus(403);
});

test('extension type is extend when within 3 days and has traffic', function () {
    $this->actingAs($this->user);

    $expiresAt = now()->addDays(2);
    
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => $expiresAt,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 2 * 1024 * 1024 * 1024,
        'panel_user_id' => 'user_' . $this->user->id . '_order_' . 1,
        'config_details' => 'test-config',
    ]);

    $response = $this->get(route('subscription.extend.show', $order->id));
    
    $response->assertStatus(200);
    $response->assertSee('افزایش زمان');
    
    // Should show new expiry as current expiry + duration
    $expectedNewExpiry = $expiresAt->copy()->addDays($this->plan->duration_days)->format('Y-m-d');
    $response->assertSee($expectedNewExpiry);
});

test('extension type is reset when expired', function () {
    $this->actingAs($this->user);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->subDays(5),
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 8 * 1024 * 1024 * 1024,
        'panel_user_id' => 'user_' . $this->user->id . '_order_' . 1,
        'config_details' => 'test-config',
    ]);

    $response = $this->get(route('subscription.extend.show', $order->id));
    
    $response->assertStatus(200);
    $response->assertSee('بازنشانی کامل');
    
    // Should show new expiry as now + duration
    $expectedNewExpiry = now()->addDays($this->plan->duration_days)->format('Y-m-d');
    $response->assertSee($expectedNewExpiry);
});
