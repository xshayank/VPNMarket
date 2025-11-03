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
});

test('configs.reset_usage permission exists after seeder', function () {
    // Run the seeder
    $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);

    // Check permission was created
    $permission = Permission::where('name', 'configs.reset_usage')
        ->where('guard_name', 'web')
        ->first();

    expect($permission)->not->toBeNull();
});

test('configs.reset_usage_own permission exists after seeder', function () {
    // Run the seeder
    $this->artisan('db:seed', ['--class' => 'PermissionsSeeder']);

    // Check permission was created
    $permission = Permission::where('name', 'configs.reset_usage_own')
        ->where('guard_name', 'web')
        ->first();

    expect($permission)->not->toBeNull();
});

test('reseller can reset usage with configs.reset_usage_own permission', function () {
    // Create permissions
    $resetUsageOwnPermission = Permission::create([
        'name' => 'configs.reset_usage_own',
        'guard_name' => 'web',
    ]);

    // Create role and assign permission
    $resellerRole = Role::create(['name' => 'reseller', 'guard_name' => 'web']);
    $resellerRole->givePermissionTo($resetUsageOwnPermission);

    // Create user with reseller
    $user = User::factory()->create();
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
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB limit
        'expires_at' => now()->addDays(10),
    ]);

    // Test policy authorization
    expect($user->can('resetUsage', $config))->toBeTrue();

    // Test actual reset (we'll mock the provisioner to avoid external calls)
    $this->actingAs($user);

    // Note: In a real scenario, this would call the actual endpoint
    // For this test, we're just verifying the policy check passes
    expect($config->canResetUsage())->toBeTrue();
});

test('reseller cannot reset usage for other reseller configs', function () {
    // Create permissions
    $resetUsageOwnPermission = Permission::create([
        'name' => 'configs.reset_usage_own',
        'guard_name' => 'web',
    ]);

    // Create role and assign permission
    $resellerRole = Role::create(['name' => 'reseller', 'guard_name' => 'web']);
    $resellerRole->givePermissionTo($resetUsageOwnPermission);

    // Create two resellers
    $user1 = User::factory()->create();
    $user1->assignRole($resellerRole);

    $user2 = User::factory()->create();
    $user2->assignRole($resellerRole);

    $panel = Panel::factory()->create();
    
    $reseller1 = Reseller::factory()->create([
        'user_id' => $user1->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
    ]);

    $reseller2 = Reseller::factory()->create([
        'user_id' => $user2->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
    ]);

    $config2 = ResellerConfig::factory()->create([
        'reseller_id' => $reseller2->id,
        'status' => 'active',
        'usage_bytes' => 5 * 1024 * 1024 * 1024,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(10),
    ]);

    // User1 should not be able to reset user2's config
    expect($user1->can('resetUsage', $config2))->toBeFalse();
});

test('admin can reset any config with configs.reset_usage permission', function () {
    // Create permissions
    $resetUsagePermission = Permission::create([
        'name' => 'configs.reset_usage',
        'guard_name' => 'web',
    ]);

    // Create admin role and assign permission
    $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo($resetUsagePermission);

    // Create admin user
    $admin = User::factory()->create(['is_admin' => true]);
    $admin->assignRole($adminRole);

    // Create reseller and config
    $resellerUser = User::factory()->create();
    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $resellerUser->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 5 * 1024 * 1024 * 1024,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(10),
    ]);

    // Admin should be able to reset any config
    expect($admin->can('resetUsage', $config))->toBeTrue();
});

test('policy denies gracefully when permission does not exist', function () {
    // Create user without any reset permissions
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 5 * 1024 * 1024 * 1024,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(10),
    ]);

    // Should deny gracefully without throwing exception
    expect($user->can('resetUsage', $config))->toBeFalse();
});

test('usage reset cooldown is enforced', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'usage_bytes' => 5 * 1024 * 1024 * 1024,
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(10),
        'meta' => [
            'last_reset_at' => now()->subHours(12)->toDateTimeString(), // Reset 12 hours ago
        ],
    ]);

    // Should not be able to reset within 24 hours
    expect($config->canResetUsage())->toBeFalse();

    // Update to 25 hours ago
    $config->update([
        'meta' => [
            'last_reset_at' => now()->subHours(25)->toDateTimeString(),
        ],
    ]);

    // Should be able to reset after 24 hours
    expect($config->canResetUsage())->toBeTrue();
});

test('permissions:sync command creates permissions', function () {
    // Run the command
    $this->artisan('permissions:sync')
        ->expectsOutput('✓ Permissions synced successfully!')
        ->assertExitCode(0);

    // Verify permissions were created
    expect(Permission::where('name', 'configs.reset_usage')->exists())->toBeTrue();
    expect(Permission::where('name', 'configs.reset_usage_own')->exists())->toBeTrue();
});

test('permissions:sync command is idempotent', function () {
    // Run the command twice
    $this->artisan('permissions:sync')->assertExitCode(0);
    $this->artisan('permissions:sync')->assertExitCode(0);

    // Should still have exactly one of each permission
    expect(Permission::where('name', 'configs.reset_usage')->count())->toBe(1);
    expect(Permission::where('name', 'configs.reset_usage_own')->count())->toBe(1);
});
