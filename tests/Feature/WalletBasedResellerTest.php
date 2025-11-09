<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerUsageSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

// Test wallet-based reseller model helpers
test('wallet based reseller isWalletBased returns true', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
    ]);

    expect($reseller->isWalletBased())->toBeTrue();
    expect($reseller->isTrafficBased())->toBeFalse(); // Type is wallet, not traffic
});

test('traffic based reseller isWalletBased returns false', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
    ]);

    expect($reseller->isWalletBased())->toBeFalse();
});

test('wallet based reseller gets default price per gb from config', function () {
    Config::set('billing.wallet.price_per_gb', 780);
    
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_price_per_gb' => null,
    ]);

    expect($reseller->getWalletPricePerGb())->toBe(780);
});

test('wallet based reseller gets custom price per gb when set', function () {
    Config::set('billing.wallet.price_per_gb', 780);
    
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_price_per_gb' => 1000,
    ]);

    expect($reseller->getWalletPricePerGb())->toBe(1000);
});

test('wallet based reseller isSuspendedWallet returns true when status is suspended_wallet', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
    ]);

    expect($reseller->isSuspendedWallet())->toBeTrue();
});

// Test wallet-based reseller dashboard access
test('wallet based reseller can access dashboard', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 10000,
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertViewIs('reseller::dashboard');
    $response->assertViewHas('reseller');
    $response->assertViewHas('stats');
});

test('wallet based reseller dashboard shows wallet balance', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 15000,
        'wallet_price_per_gb' => 800,
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertSee('موجودی کیف پول');
    $response->assertSee('15,000 تومان', false);
    $response->assertSee('800 تومان', false);
    $response->assertSee('قیمت هر گیگابایت');
    $response->assertViewHas('stats', function ($stats) {
        return $stats['wallet_balance'] === 15000
            && $stats['wallet_price_per_gb'] === 800;
    });
});

test('wallet based reseller dashboard shows type as wallet based', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'type' => 'wallet',
        'status' => 'active',
        'wallet_balance' => 10000,
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertSee('ریسلر کیف پول‌محور');
});

// Test wallet-based reseller suspension
test('wallet based reseller with suspended_wallet status is redirected to wallet page', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
        'wallet_balance' => -2000,
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertRedirect(route('wallet.charge.form'));
    $response->assertSessionHas('warning');
});

test('wallet based reseller with suspended_wallet can access wallet charge page', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'suspended_wallet',
        'wallet_balance' => -2000,
    ]);

    $response = $this->actingAs($user)->get(route('wallet.charge.form'));

    $response->assertStatus(200);
});

// Test traffic-based reseller unchanged
test('traffic based reseller with billing_type traffic still works normally', function () {
    $user = User::factory()->create();
    Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'billing_type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $response = $this->actingAs($user)->get('/reseller');

    $response->assertStatus(200);
    $response->assertSee('ریسلر ترافیک‌محور');
    $response->assertDontSee('موجودی کیف پول');
    $response->assertSee('ترافیک کل');
});

// Test snapshot creation
test('usage snapshot can be created for reseller', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
    ]);

    $snapshot = ResellerUsageSnapshot::create([
        'reseller_id' => $reseller->id,
        'total_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB
        'measured_at' => now(),
    ]);

    expect($snapshot->reseller_id)->toBe($reseller->id);
    expect($snapshot->total_bytes)->toBe(5 * 1024 * 1024 * 1024);
    
    $reseller->refresh();
    expect($reseller->usageSnapshots()->count())->toBe(1);
});

test('charging command creates snapshot', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 10000,
    ]);

    // Create some configs with usage
    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 2 * 1024 * 1024 * 1024, // 2 GB
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    expect($reseller->usageSnapshots()->count())->toBeGreaterThan(0);
});

test('charging command deducts correct amount from wallet', function () {
    Config::set('billing.wallet.price_per_gb', 1000);
    
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 10000,
        'wallet_price_per_gb' => 1000,
    ]);

    // Create config with 1 GB usage
    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    $reseller->refresh();
    
    // Should charge 1 GB * 1000 = 1000 تومان
    expect($reseller->wallet_balance)->toBe(9000);
});

test('charging command suspends reseller when balance too low', function () {
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.price_per_gb', 5000);
    
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 1000,
        'wallet_price_per_gb' => 5000,
        'status' => 'active',
    ]);

    // Create config with 1 GB usage - this will cost 5000, bringing balance to -4000
    ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
        'status' => 'active',
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    $reseller->refresh();
    
    expect($reseller->status)->toBe('suspended_wallet');
    expect($reseller->wallet_balance)->toBeLessThanOrEqual(-1000);
});

test('charging command disables configs when suspending reseller', function () {
    Config::set('billing.wallet.suspension_threshold', -1000);
    Config::set('billing.wallet.price_per_gb', 5000);
    
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => 500,
        'wallet_price_per_gb' => 5000,
        'status' => 'active',
    ]);

    // Create active configs
    $config1 = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
        'status' => 'active',
    ]);
    
    $config2 = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 0,
        'status' => 'active',
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    $config1->refresh();
    $config2->refresh();
    
    expect($config1->status)->toBe('disabled');
    expect($config2->status)->toBe('disabled');
});

test('charging command only charges wallet-based resellers', function () {
    $user1 = User::factory()->create();
    $walletReseller = Reseller::factory()->create([
        'user_id' => $user1->id,
        'type' => 'wallet',
        'wallet_balance' => 10000,
    ]);

    $user2 = User::factory()->create();
    $trafficReseller = Reseller::factory()->create([
        'user_id' => $user2->id,
        'billing_type' => 'traffic',
        'wallet_balance' => 10000,
    ]);

    ResellerConfig::factory()->create([
        'reseller_id' => $walletReseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
    ]);
    
    ResellerConfig::factory()->create([
        'reseller_id' => $trafficReseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    $walletReseller->refresh();
    $trafficReseller->refresh();
    
    // Wallet reseller should be charged
    expect($walletReseller->wallet_balance)->toBeLessThan(10000);
    
    // Traffic reseller should not be charged
    expect($trafficReseller->wallet_balance)->toBe(10000);
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
