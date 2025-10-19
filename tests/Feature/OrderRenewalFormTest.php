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

    // Create a test user
    $this->user = User::factory()->create();
});

test('user can access renewal confirmation page for paid order with plan', function () {
    $this->actingAs($this->user);

    // Create a paid order
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(20),
    ]);

    $response = $this->get(route('order.renew.form', $order->id));
    
    $response->assertStatus(200);
    $response->assertSee('تأیید تمدید سرویس');
    $response->assertSee($this->plan->name);
    $response->assertSee('تأیید و ادامه');
});

test('user cannot access renewal confirmation page for unpaid order', function () {
    $this->actingAs($this->user);

    // Create a pending order
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'pending',
    ]);

    $response = $this->get(route('order.renew.form', $order->id));
    
    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('error');
});

test('user cannot access renewal confirmation page for order without plan', function () {
    $this->actingAs($this->user);

    // Create a wallet charge order (no plan)
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => null,
        'status' => 'paid',
        'amount' => 100000,
    ]);

    $response = $this->get(route('order.renew.form', $order->id));
    
    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('error');
});

test('user cannot access another users renewal confirmation page', function () {
    $this->actingAs($this->user);
    
    $otherUser = User::factory()->create();

    $order = Order::factory()->create([
        'user_id' => $otherUser->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(20),
    ]);

    $response = $this->get(route('order.renew.form', $order->id));
    
    $response->assertStatus(403);
});

test('confirming renewal creates a new pending order', function () {
    $this->actingAs($this->user);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(20),
        'config_details' => 'existing-config',
    ]);

    $response = $this->post(route('order.renew', $order->id));
    
    // Should create a new order
    $newOrder = Order::where('renews_order_id', $order->id)->first();
    
    expect($newOrder)->not->toBeNull()
        ->and($newOrder->status)->toBe('pending')
        ->and($newOrder->plan_id)->toBe($order->plan_id)
        ->and($newOrder->user_id)->toBe($order->user_id)
        ->and($newOrder->config_details)->toBeNull()
        ->and($newOrder->expires_at)->toBeNull();
    
    $response->assertRedirect(route('order.show', $newOrder->id));
    $response->assertSessionHas('status');
});

test('legacy subscription extend url redirects to renewal confirmation page', function () {
    $this->actingAs($this->user);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(2),
    ]);

    $response = $this->get("/subscription/{$order->id}/extend");
    
    $response->assertRedirect(route('order.renew.form', $order->id));
});

test('renewal confirmation page shows current expiry date if available', function () {
    $this->actingAs($this->user);

    $expiresAt = now()->addDays(15);
    
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => $expiresAt,
    ]);

    $response = $this->get(route('order.renew.form', $order->id));
    
    $response->assertStatus(200);
    $response->assertSee('تاریخ انقضای فعلی');
    $response->assertSee($expiresAt->format('Y-m-d'));
});
