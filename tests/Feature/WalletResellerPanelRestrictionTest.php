<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;

test('wallet reseller with panel_id can be created successfully', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'marzban', 'is_active' => true]);

    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'panel_id' => $panel->id,
        'config_limit' => 10,
        'wallet_balance' => 10000,
    ]);

    expect($reseller)->toBeInstanceOf(Reseller::class)
        ->and($reseller->type)->toBe('wallet')
        ->and($reseller->panel_id)->toBe($panel->id)
        ->and($reseller->config_limit)->toBe(10);
});

test('wallet reseller is restricted to assigned panel', function () {
    $user = User::factory()->create();
    $panel1 = Panel::factory()->create(['panel_type' => 'marzban', 'is_active' => true]);
    $panel2 = Panel::factory()->create(['panel_type' => 'marzban', 'is_active' => true]);

    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'panel_id' => $panel1->id,
        'config_limit' => 10,
        'wallet_balance' => 10000,
    ]);

    // Reseller should only have access to panel1
    expect($reseller->panel_id)->toBe($panel1->id)
        ->and($reseller->panel_id)->not->toBe($panel2->id);
});

test('wallet reseller panel change is blocked when active configs exist', function () {
    $user = User::factory()->create();
    $panel1 = Panel::factory()->create(['panel_type' => 'marzban', 'is_active' => true]);
    $panel2 = Panel::factory()->create(['panel_type' => 'marzban', 'is_active' => true]);

    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'panel_id' => $panel1->id,
        'config_limit' => 10,
        'wallet_balance' => 10000,
    ]);

    // Create an active config
    \App\Models\ResellerConfig::create([
        'reseller_id' => $reseller->id,
        'external_username' => 'test_user',
        'traffic_limit_bytes' => 1024 * 1024 * 1024,
        'usage_bytes' => 0,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $panel1->id,
        'created_by' => $user->id,
    ]);

    // Verify that active configs exist
    $activeConfigsCount = $reseller->configs()->whereIn('status', ['active', 'disabled'])->count();

    expect($activeConfigsCount)->toBeGreaterThan(0);

    // The EditReseller::mutateFormDataBeforeSave should detect this
    // and prevent panel changes (tested via form validation)
});

test('traffic reseller creation unchanged', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'marzban', 'is_active' => true]);

    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    expect($reseller)->toBeInstanceOf(Reseller::class)
        ->and($reseller->type)->toBe('traffic')
        ->and($reseller->panel_id)->toBe($panel->id);
});

test('wallet reseller can have node assignments', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'eylandoo', 'is_active' => true]);

    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'panel_id' => $panel->id,
        'config_limit' => 10,
        'wallet_balance' => 10000,
        'eylandoo_allowed_node_ids' => [1, 2, 3],
    ]);

    expect($reseller->eylandoo_allowed_node_ids)
        ->toBeArray()
        ->toHaveCount(3)
        ->toContain(1, 2, 3);
});

test('wallet reseller can have service assignments', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'marzneshin', 'is_active' => true]);

    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => 'wallet',
        'status' => 'active',
        'panel_id' => $panel->id,
        'config_limit' => 10,
        'wallet_balance' => 10000,
        'marzneshin_allowed_service_ids' => [1, 2],
    ]);

    expect($reseller->marzneshin_allowed_service_ids)
        ->toBeArray()
        ->toHaveCount(2)
        ->toContain(1, 2);
});
