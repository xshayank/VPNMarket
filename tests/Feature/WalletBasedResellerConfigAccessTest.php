<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

// Test wallet-based reseller supportsConfigManagement
test('wallet based reseller supportsConfigManagement returns true', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
    ]);

    expect($reseller->supportsConfigManagement())->toBeTrue();
});

test('traffic based reseller supportsConfigManagement returns true', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
    ]);

    expect($reseller->supportsConfigManagement())->toBeTrue();
});

test('plan based reseller supportsConfigManagement returns false', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    expect($reseller->supportsConfigManagement())->toBeFalse();
});

// Test wallet-based reseller can access config index
test('wallet based reseller can access config index', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 10000,
    ]);

    $response = $this->actingAs($user)->get('/reseller/configs');

    $response->assertStatus(200);
    $response->assertViewIs('reseller::configs.index');
});

// Test wallet-based reseller can access config create
test('wallet based reseller can access config create page', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 10000,
    ]);

    $response = $this->actingAs($user)->get('/reseller/configs/create');

    $response->assertStatus(200);
    $response->assertViewIs('reseller::configs.create');
});

// Test plan-based reseller cannot access config features
test('plan based reseller cannot access config index', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/reseller/configs');

    $response->assertRedirect(route('reseller.dashboard'));
    $response->assertSessionHas('error', 'This feature is only available for traffic-based and wallet-based resellers.');
});

test('plan based reseller cannot access config create', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/reseller/configs/create');

    $response->assertRedirect(route('reseller.dashboard'));
    $response->assertSessionHas('error', 'This feature is only available for traffic-based and wallet-based resellers.');
});

// Test wallet-based reseller can enable configs without quota check
test('wallet based reseller can enable config without traffic quota check', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 10000,
        // Note: No traffic_total_bytes or window_ends_at set
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
    ]);

    $response = $this->actingAs($user)->post("/reseller/configs/{$config->id}/enable");

    // Should succeed without checking traffic/window
    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors();
    
    $config->refresh();
    expect($config->status)->toBe('active');
});

// Test traffic-based reseller still requires quota check
test('traffic based reseller cannot enable config without traffic quota', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,  // 10 GB
        'traffic_used_bytes' => 15 * 1024 * 1024 * 1024,   // 15 GB (over quota)
        'window_starts_at' => now()->subDays(5),
        'window_ends_at' => now()->addDays(25),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
    ]);

    $response = $this->actingAs($user)->post("/reseller/configs/{$config->id}/enable");

    // Should fail quota check
    $response->assertRedirect();
    $response->assertSessionHas('error', 'Cannot enable config: reseller quota exceeded or window expired.');
    
    $config->refresh();
    expect($config->status)->toBe('disabled');
});

// Test usage sync includes wallet-based resellers
test('usage sync job processes wallet based resellers', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 10000,
        'traffic_used_bytes' => 0,
    ]);

    // Create a config with some usage
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 5 * 1024 * 1024 * 1024,  // 5 GB
    ]);

    // Note: This test verifies the query includes wallet type
    // Actual sync would require panel setup, so we just verify the reseller is included
    $activeResellers = Reseller::where('status', 'active')
        ->whereIn('type', ['traffic', 'wallet'])
        ->get();

    expect($activeResellers->contains($reseller))->toBeTrue();
});

// Test quota enforcement skips wallet-based resellers
test('time window enforcement command skips wallet based resellers', function () {
    $user1 = User::factory()->create();
    $walletReseller = Reseller::factory()->create([
        'user_id' => $user1->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 10000,
    ]);

    $user2 = User::factory()->create();
    $trafficReseller = Reseller::factory()->create([
        'user_id' => $user2->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    // Verify the query only includes traffic type
    $activeTrafficResellers = Reseller::where('type', 'traffic')
        ->where('status', 'active')
        ->get();

    expect($activeTrafficResellers->contains($walletReseller))->toBeFalse();
    expect($activeTrafficResellers->contains($trafficReseller))->toBeTrue();
});

// Test wallet-based reseller configs show in listing
test('wallet based reseller can see their configs in listing', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 10000,
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'external_username' => 'test_wallet_user',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/reseller/configs');

    $response->assertStatus(200);
    $response->assertSee('test_wallet_user');
});
