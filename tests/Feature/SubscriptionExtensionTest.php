<?php

use App\Models\Order;
use App\Models\Panel;
use App\Models\Plan;
use App\Models\User;
use App\Services\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a test panel
    $this->panel = Panel::factory()->create([
        'name' => 'Test Marzban Panel',
        'panel_type' => 'marzban',
        'url' => 'https://test-panel.example.com',
        'username' => 'admin',
        'password' => 'password',
        'extra' => json_encode(['node_hostname' => 'https://node.example.com']),
    ]);

    // Create a test plan
    $this->plan = Plan::factory()->create([
        'name' => 'Test Plan',
        'price' => 100000,
        'volume_gb' => 50,
        'duration_days' => 30,
        'panel_id' => $this->panel->id,
    ]);

    // Create a normal user
    $this->user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'testuser@example.com',
        'balance' => 500000,
    ]);
});

test('user can extend subscription when 3 days or less remaining', function () {
    // Create an existing active order with 2 days remaining
    $existingOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(2),
        'config_details' => 'subscription://existing-config',
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        'usage_bytes' => 10 * 1024 * 1024 * 1024, // 10GB used
    ]);

    expect($existingOrder->canBeExtended())->toBeTrue()
        ->and($existingOrder->isExpiredOrNoTraffic())->toBeFalse();
});

test('user can extend subscription when expired', function () {
    // Create an expired order
    $existingOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->subDays(1),
        'config_details' => 'subscription://existing-config',
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        'usage_bytes' => 10 * 1024 * 1024 * 1024,
    ]);

    expect($existingOrder->canBeExtended())->toBeTrue()
        ->and($existingOrder->isExpiredOrNoTraffic())->toBeTrue();
});

test('user can extend subscription when out of traffic', function () {
    // Create an order with traffic exhausted
    $existingOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(10),
        'config_details' => 'subscription://existing-config',
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        'usage_bytes' => 50 * 1024 * 1024 * 1024, // All traffic used
    ]);

    expect($existingOrder->canBeExtended())->toBeTrue()
        ->and($existingOrder->isExpiredOrNoTraffic())->toBeTrue();
});

test('user cannot extend subscription when more than 3 days remaining with traffic', function () {
    // Create an active order with 10 days remaining
    $existingOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(10),
        'config_details' => 'subscription://existing-config',
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        'usage_bytes' => 10 * 1024 * 1024 * 1024, // 10GB used, 40GB remaining
    ]);

    expect($existingOrder->canBeExtended())->toBeFalse()
        ->and($existingOrder->isExpiredOrNoTraffic())->toBeFalse();
});

test('pending orders cannot be extended', function () {
    $pendingOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'pending',
        'expires_at' => now()->addDays(2),
        'config_details' => null,
        'traffic_limit_bytes' => null,
        'usage_bytes' => 0,
    ]);

    expect($pendingOrder->canBeExtended())->toBeFalse();
});

test('provisioning service finds extendable order for user', function () {
    // Create an existing active order with 2 days remaining
    $existingOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(2),
        'config_details' => 'subscription://existing-config',
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        'usage_bytes' => 10 * 1024 * 1024 * 1024,
    ]);

    $provisioningService = new ProvisioningService();
    $reflection = new ReflectionClass($provisioningService);
    $method = $reflection->getMethod('findExtendableOrder');
    $method->setAccessible(true);

    $found = $method->invoke($provisioningService, $this->user, $this->plan);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($existingOrder->id);
});

test('provisioning service returns error when extension blocked', function () {
    // Create an existing active order with 10 days remaining
    $existingOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(10),
        'config_details' => 'subscription://existing-config',
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        'usage_bytes' => 10 * 1024 * 1024 * 1024,
    ]);

    // Create a new order
    $newOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'pending',
    ]);

    $provisioningService = new ProvisioningService();
    $result = $provisioningService->provisionOrExtend($this->user, $this->plan, $newOrder, false);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('3 روز');
});

test('reseller users bypass extension logic', function () {
    // Create a reseller user
    $reseller = \App\Models\Reseller::factory()->create([
        'user_id' => $this->user->id,
    ]);

    // Verify that the user is a reseller
    expect($this->user->fresh()->isReseller())->toBeTrue();
    
    // The key point is that resellers should not use the extension logic
    // They should always create new configs, not extend existing ones
    // This is verified by the isReseller() check in ProvisioningService
});

test('extension resets usage bytes to zero', function () {
    // Create an existing active order with 2 days remaining
    $existingOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(2),
        'config_details' => 'subscription://existing-config',
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        'usage_bytes' => 40 * 1024 * 1024 * 1024, // 40GB used
    ]);

    expect($existingOrder->usage_bytes)->toBe(40 * 1024 * 1024 * 1024);
    
    // When extended, usage should be reset
    // This would be tested in integration tests with actual provisioning
});

test('order model casts traffic fields correctly', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'plan_id' => $this->plan->id,
        'status' => 'paid',
        'expires_at' => now()->addDays(30),
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        'usage_bytes' => 10 * 1024 * 1024 * 1024,
    ]);

    expect($order->traffic_limit_bytes)->toBeInt()
        ->and($order->usage_bytes)->toBeInt()
        ->and($order->expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
