<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Reset cached roles and permissions
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    // Create necessary permissions and roles
    $updateOwnPermission = Permission::create([
        'name' => 'configs.update_own',
        'guard_name' => 'web',
    ]);

    $resellerRole = Role::create(['name' => 'reseller', 'guard_name' => 'web']);
    $resellerRole->givePermissionTo($updateOwnPermission);
});

test('reseller can set config expiry beyond reseller window', function () {
    // Create reseller with limited window
    $user = User::factory()->create();
    $resellerRole = Role::where('name', 'reseller')->first();
    $user->assignRole($resellerRole);

    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30), // Window ends in 30 days
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 0,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(15), // Currently expires in 15 days
        'panel_id' => $panel->id,
    ]);

    $this->actingAs($user);

    // Try to extend config beyond reseller window (60 days > 30 days window)
    $newExpiryDate = now()->addDays(60)->format('Y-m-d');

    $response = $this->put(route('reseller.configs.update', $config), [
        'traffic_limit_gb' => 10,
        'expires_at' => $newExpiryDate,
    ]);

    // Should succeed (no error about exceeding window)
    // Note: Remote panel update may fail in tests, but local update should succeed
    $response->assertRedirect(route('reseller.configs.index'));
    
    // Verify the config was updated
    $config->refresh();
    expect($config->expires_at->format('Y-m-d'))->toBe($newExpiryDate);
});

test('config expiry is normalized to midnight Asia/Tehran', function () {
    $user = User::factory()->create();
    $resellerRole = Role::where('name', 'reseller')->first();
    $user->assignRole($resellerRole);

    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 0,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(15),
        'panel_id' => $panel->id,
    ]);

    $this->actingAs($user);

    $newExpiryDate = now()->addDays(20)->format('Y-m-d');

    $this->put(route('reseller.configs.update', $config), [
        'traffic_limit_gb' => 10,
        'expires_at' => $newExpiryDate,
    ]);

    $config->refresh();

    // Verify time is set to 00:00:00
    expect($config->expires_at->format('H:i:s'))->toBe('00:00:00');
});

test('config expiry cannot be set before today', function () {
    $user = User::factory()->create();
    $resellerRole = Role::where('name', 'reseller')->first();
    $user->assignRole($resellerRole);

    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 0,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(15),
        'panel_id' => $panel->id,
    ]);

    $this->actingAs($user);

    // Try to set expiry to yesterday
    $pastDate = now()->subDays(1)->format('Y-m-d');

    $response = $this->put(route('reseller.configs.update', $config), [
        'traffic_limit_gb' => 10,
        'expires_at' => $pastDate,
    ]);

    // Should fail validation
    $response->assertSessionHasErrors('expires_at');
});

test('config expiry minimum is today', function () {
    $user = User::factory()->create();
    $resellerRole = Role::where('name', 'reseller')->first();
    $user->assignRole($resellerRole);

    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 0,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(15),
        'panel_id' => $panel->id,
    ]);

    $this->actingAs($user);

    // Set expiry to today
    $todayDate = now()->format('Y-m-d');

    $response = $this->put(route('reseller.configs.update', $config), [
        'traffic_limit_gb' => 10,
        'expires_at' => $todayDate,
    ]);

    // Should succeed
    $response->assertRedirect(route('reseller.configs.index'));

    $config->refresh();
    expect($config->expires_at->format('Y-m-d'))->toBe($todayDate);
});

test('reseller with no window_ends_at can set any future date', function () {
    $user = User::factory()->create();
    $resellerRole = Role::where('name', 'reseller')->first();
    $user->assignRole($resellerRole);

    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => null, // No window limit
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 0,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(15),
        'panel_id' => $panel->id,
    ]);

    $this->actingAs($user);

    // Set expiry to very far future (1 year)
    $futureDate = now()->addYear()->format('Y-m-d');

    $response = $this->put(route('reseller.configs.update', $config), [
        'traffic_limit_gb' => 10,
        'expires_at' => $futureDate,
    ]);

    // Should succeed
    $response->assertRedirect(route('reseller.configs.index'));

    $config->refresh();
    expect($config->expires_at->format('Y-m-d'))->toBe($futureDate);
});

test('traffic limit validation still enforced on edit', function () {
    $user = User::factory()->create();
    $resellerRole = Role::where('name', 'reseller')->first();
    $user->assignRole($resellerRole);

    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 5 * 1024 * 1024 * 1024, // 5 GB used
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(15),
        'panel_id' => $panel->id,
    ]);

    $this->actingAs($user);

    // Try to set traffic limit below current usage (3 GB < 5 GB used)
    $response = $this->put(route('reseller.configs.update', $config), [
        'traffic_limit_gb' => 3,
        'expires_at' => now()->addDays(20)->format('Y-m-d'),
    ]);

    // Should fail with error message
    $response->assertSessionHas('error');
    $response->assertRedirect();
});

test('edit form does not show max date restriction in UI', function () {
    $user = User::factory()->create();
    $resellerRole = Role::where('name', 'reseller')->first();
    $user->assignRole($resellerRole);

    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 0,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(15),
        'panel_id' => $panel->id,
    ]);

    $this->actingAs($user);

    // Test that the policy allows access to edit
    expect($user->can('update', $config))->toBeTrue();
    
    // Note: We skip actual view rendering test due to vite manifest requirement
    // The key change is in the blade template which removed the max attribute
    // and the controller validation which no longer checks window_ends_at
})->skip('View rendering requires vite build which is not available in test environment');
