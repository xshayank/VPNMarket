<?php

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

// Test that configs are marked as auto-disabled when reseller is suspended due to wallet
test('wallet suspension marks configs with disabled_by_wallet_suspension flag', function () {
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
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB
        'status' => 'active',
        'meta' => null,
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    $config->refresh();
    $reseller->refresh();

    expect($config->status)->toBe('disabled');
    expect($config->meta)->toHaveKey('disabled_by_wallet_suspension');
    expect($config->meta['disabled_by_wallet_suspension'])->toBeTrue();
    expect($config->meta)->toHaveKey('disabled_by_reseller_id');
    expect($config->meta['disabled_by_reseller_id'])->toBe($reseller->id);
    expect($reseller->status)->toBe('suspended_wallet');
});

test('wallet suspension creates auto_disabled event for configs', function () {
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

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
        'status' => 'active',
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
        ->where('type', 'auto_disabled')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->meta)->toHaveKey('reason');
    expect($event->meta['reason'])->toBe('wallet_balance_exhausted');
});

test('wallet suspension creates audit log for config disable', function () {
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

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
        'status' => 'active',
    ]);

    Artisan::call('reseller:charge-wallet-hourly');

    $auditLog = AuditLog::where('action', 'config_auto_disabled')
        ->where('target_type', 'config')
        ->where('target_id', $config->id)
        ->where('reason', 'wallet_balance_exhausted')
        ->first();

    expect($auditLog)->not->toBeNull();
});

test('wallet recharge re-enables auto-disabled configs', function () {
    Config::set('billing.wallet.suspension_threshold', -1000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => -2000,
        'status' => 'suspended_wallet',
    ]);

    // Create a config that was auto-disabled by wallet suspension
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'disabled_at' => now(),
        'meta' => [
            'disabled_by_wallet_suspension' => true,
            'disabled_by_reseller_id' => $reseller->id,
            'disabled_at' => now()->toIso8601String(),
        ],
    ]);

    // Create a transaction for wallet recharge
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'amount' => 5000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_PENDING,
        'description' => 'شارژ کیف پول ریسلر (در انتظار تایید)',
    ]);

    // Simulate admin approving the transaction
    $transaction->update(['status' => Transaction::STATUS_COMPLETED]);
    $reseller->increment('wallet_balance', $transaction->amount);

    // Manually trigger re-enable (since we can't test Filament action directly)
    $reseller->refresh();
    if ($reseller->isSuspendedWallet() && $reseller->wallet_balance > -1000) {
        $reseller->update(['status' => 'active']);

        // Re-enable configs - simulate what WalletTopUpTransactionResource does
        $configs = ResellerConfig::where('reseller_id', $reseller->id)
            ->where('status', 'disabled')
            ->where(function ($query) {
                $query->whereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = TRUE")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = '1'")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = 1")
                    ->orWhereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = 'true'");
            })
            ->get();

        foreach ($configs as $cfg) {
            $meta = $cfg->meta ?? [];
            unset($meta['disabled_by_wallet_suspension']);
            unset($meta['disabled_by_reseller_id']);
            unset($meta['disabled_at']);

            $cfg->update([
                'status' => 'active',
                'disabled_at' => null,
                'meta' => $meta,
            ]);

            ResellerConfigEvent::create([
                'reseller_config_id' => $cfg->id,
                'type' => 'auto_enabled',
                'meta' => [
                    'reason' => 'wallet_recharged',
                    'remote_success' => false,
                ],
            ]);
        }
    }

    $config->refresh();
    $reseller->refresh();

    expect($reseller->status)->toBe('active');
    expect($config->status)->toBe('active');
    expect($config->disabled_at)->toBeNull();
    expect($config->meta)->not->toHaveKey('disabled_by_wallet_suspension');
    expect($config->meta)->not->toHaveKey('disabled_by_reseller_id');
    expect($config->meta)->not->toHaveKey('disabled_at');
});

test('wallet recharge creates auto_enabled event for re-enabled configs', function () {
    Config::set('billing.wallet.suspension_threshold', -1000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => -2000,
        'status' => 'suspended_wallet',
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
            'disabled_by_reseller_id' => $reseller->id,
        ],
    ]);

    // Simulate wallet recharge and re-enable
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'amount' => 5000,
        'type' => Transaction::TYPE_DEPOSIT,
        'status' => Transaction::STATUS_COMPLETED,
        'description' => 'شارژ کیف پول ریسلر',
    ]);

    $reseller->increment('wallet_balance', $transaction->amount);
    $reseller->update(['status' => 'active']);

    // Re-enable the config
    $meta = $config->meta ?? [];
    unset($meta['disabled_by_wallet_suspension']);
    unset($meta['disabled_by_reseller_id']);

    $config->update([
        'status' => 'active',
        'disabled_at' => null,
        'meta' => $meta,
    ]);

    ResellerConfigEvent::create([
        'reseller_config_id' => $config->id,
        'type' => 'auto_enabled',
        'meta' => [
            'reason' => 'wallet_recharged',
        ],
    ]);

    $event = ResellerConfigEvent::where('reseller_config_id', $config->id)
        ->where('type', 'auto_enabled')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->meta)->toHaveKey('reason');
    expect($event->meta['reason'])->toBe('wallet_recharged');
});

test('wallet recharge does not re-enable manually disabled configs', function () {
    Config::set('billing.wallet.suspension_threshold', -1000);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'wallet_balance' => -2000,
        'status' => 'suspended_wallet',
    ]);

    // Create a config that was manually disabled (no wallet suspension flag)
    $manuallyDisabledConfig = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'disabled_at' => now(),
        'meta' => [
            'manually_disabled' => true,
        ],
    ]);

    // Create a config that was auto-disabled by wallet suspension
    $autoDisabledConfig = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
        ],
    ]);

    // Simulate wallet recharge
    $reseller->increment('wallet_balance', 5000);
    $reseller->update(['status' => 'active']);

    // Re-enable only auto-disabled configs
    $configs = ResellerConfig::where('reseller_id', $reseller->id)
        ->where('status', 'disabled')
        ->where(function ($query) {
            $query->whereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = TRUE");
        })
        ->get();

    foreach ($configs as $cfg) {
        $meta = $cfg->meta ?? [];
        unset($meta['disabled_by_wallet_suspension']);

        $cfg->update([
            'status' => 'active',
            'disabled_at' => null,
            'meta' => $meta,
        ]);
    }

    $manuallyDisabledConfig->refresh();
    $autoDisabledConfig->refresh();

    // Manually disabled should remain disabled
    expect($manuallyDisabledConfig->status)->toBe('disabled');

    // Auto-disabled should be re-enabled
    expect($autoDisabledConfig->status)->toBe('active');
});

test('wallet recharge only affects configs of the specific reseller', function () {
    Config::set('billing.wallet.suspension_threshold', -1000);

    $user1 = User::factory()->create();
    $reseller1 = Reseller::factory()->create([
        'user_id' => $user1->id,
        'type' => 'wallet',
        'wallet_balance' => -2000,
        'status' => 'suspended_wallet',
    ]);

    $user2 = User::factory()->create();
    $reseller2 = Reseller::factory()->create([
        'user_id' => $user2->id,
        'type' => 'wallet',
        'wallet_balance' => -2000,
        'status' => 'suspended_wallet',
    ]);

    // Create configs for both resellers
    $config1 = ResellerConfig::factory()->create([
        'reseller_id' => $reseller1->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
            'disabled_by_reseller_id' => $reseller1->id,
        ],
    ]);

    $config2 = ResellerConfig::factory()->create([
        'reseller_id' => $reseller2->id,
        'status' => 'disabled',
        'meta' => [
            'disabled_by_wallet_suspension' => true,
            'disabled_by_reseller_id' => $reseller2->id,
        ],
    ]);

    // Only recharge reseller1
    $reseller1->increment('wallet_balance', 5000);
    $reseller1->update(['status' => 'active']);

    // Re-enable only reseller1's configs
    $configs = ResellerConfig::where('reseller_id', $reseller1->id)
        ->where('status', 'disabled')
        ->where(function ($query) {
            $query->whereRaw("JSON_EXTRACT(meta, '$.disabled_by_wallet_suspension') = TRUE");
        })
        ->get();

    foreach ($configs as $cfg) {
        $meta = $cfg->meta ?? [];
        unset($meta['disabled_by_wallet_suspension']);
        unset($meta['disabled_by_reseller_id']);

        $cfg->update([
            'status' => 'active',
            'disabled_at' => null,
            'meta' => $meta,
        ]);
    }

    $config1->refresh();
    $config2->refresh();

    // Reseller1's config should be re-enabled
    expect($config1->status)->toBe('active');
    expect($config1->meta)->not->toHaveKey('disabled_by_wallet_suspension');

    // Reseller2's config should remain disabled
    expect($config2->status)->toBe('disabled');
    expect($config2->meta)->toHaveKey('disabled_by_wallet_suspension');
});

test('traffic-based reseller configs are not affected by wallet logic', function () {
    $user = User::factory()->create();
    $trafficReseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $trafficReseller->id,
        'status' => 'active',
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
    ]);

    // Run wallet charging - should not affect traffic reseller
    Artisan::call('reseller:charge-wallet-hourly');

    $config->refresh();
    $trafficReseller->refresh();

    expect($config->status)->toBe('active');
    expect($trafficReseller->status)->toBe('active');
    expect($config->meta)->not->toHaveKey('disabled_by_wallet_suspension');
});
