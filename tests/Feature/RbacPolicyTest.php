<?php

use App\Models\User;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\Panel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run the RBAC seeder to set up roles and permissions
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\RbacSeeder']);
});

test('super admin can access everything', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $superAdmin->assignRole('super-admin');
    
    $reseller = Reseller::factory()->create();
    $config = ResellerConfig::factory()->create();
    $panel = Panel::factory()->create();
    
    expect($superAdmin->can('viewAny', Reseller::class))->toBeTrue()
        ->and($superAdmin->can('view', $reseller))->toBeTrue()
        ->and($superAdmin->can('update', $reseller))->toBeTrue()
        ->and($superAdmin->can('delete', $reseller))->toBeTrue()
        ->and($superAdmin->can('view', $config))->toBeTrue()
        ->and($superAdmin->can('update', $config))->toBeTrue()
        ->and($superAdmin->can('view', $panel))->toBeTrue();
});

test('admin can manage most resources', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $admin->assignRole('admin');
    
    $reseller = Reseller::factory()->create();
    $config = ResellerConfig::factory()->create();
    $panel = Panel::factory()->create();
    
    expect($admin->can('viewAny', Reseller::class))->toBeTrue()
        ->and($admin->can('view', $reseller))->toBeTrue()
        ->and($admin->can('create', Reseller::class))->toBeTrue()
        ->and($admin->can('update', $reseller))->toBeTrue()
        ->and($admin->can('view', $config))->toBeTrue()
        ->and($admin->can('update', $config))->toBeTrue()
        ->and($admin->can('view', $panel))->toBeTrue()
        ->and($admin->can('update', $panel))->toBeTrue();
});

test('reseller can only view and update own resources', function () {
    $resellerUser = User::factory()->create();
    $resellerUser->assignRole('reseller');
    
    $ownReseller = Reseller::factory()->create(['user_id' => $resellerUser->id]);
    $otherReseller = Reseller::factory()->create();
    
    $ownConfig = ResellerConfig::factory()->create(['reseller_id' => $ownReseller->id]);
    $otherConfig = ResellerConfig::factory()->create();
    
    // Can view/update own reseller
    expect($resellerUser->can('view', $ownReseller))->toBeTrue()
        ->and($resellerUser->can('update', $ownReseller))->toBeTrue()
        ->and($resellerUser->can('view', $otherReseller))->toBeFalse()
        ->and($resellerUser->can('update', $otherReseller))->toBeFalse()
        ->and($resellerUser->can('delete', $ownReseller))->toBeFalse();
    
    // Can view/update own configs
    expect($resellerUser->can('view', $ownConfig))->toBeTrue()
        ->and($resellerUser->can('update', $ownConfig))->toBeTrue()
        ->and($resellerUser->can('view', $otherConfig))->toBeFalse()
        ->and($resellerUser->can('update', $otherConfig))->toBeFalse();
});

test('regular user has minimal permissions', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    
    $reseller = Reseller::factory()->create();
    $config = ResellerConfig::factory()->create();
    $panel = Panel::factory()->create();
    
    expect($user->can('viewAny', Reseller::class))->toBeFalse()
        ->and($user->can('view', $reseller))->toBeFalse()
        ->and($user->can('create', Reseller::class))->toBeFalse()
        ->and($user->can('viewAny', ResellerConfig::class))->toBeFalse()
        ->and($user->can('view', $config))->toBeFalse()
        ->and($user->can('viewAny', Panel::class))->toBeFalse()
        ->and($user->can('view', $panel))->toBeFalse();
});

test('users are migrated to correct roles', function () {
    // Create users with different flags
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $admin = User::factory()->create(['is_admin' => true]);
    $regularUser = User::factory()->create(['is_admin' => false]);
    
    // Create a reseller user
    $resellerUser = User::factory()->create(['is_admin' => false]);
    $reseller = Reseller::factory()->create(['user_id' => $resellerUser->id]);
    
    // Run migration (seeder already ran in beforeEach, but we'll call it again)
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\RbacSeeder']);
    
    // Check role assignments
    expect($superAdmin->fresh()->hasRole('super-admin'))->toBeTrue()
        ->and($admin->fresh()->hasRole('admin'))->toBeTrue()
        ->and($resellerUser->fresh()->hasRole('reseller'))->toBeTrue()
        ->and($regularUser->fresh()->hasRole('user'))->toBeTrue();
});

test('reseller cannot access other resellers configs', function () {
    $reseller1User = User::factory()->create();
    $reseller1User->assignRole('reseller');
    $reseller1 = Reseller::factory()->create(['user_id' => $reseller1User->id]);
    
    $reseller2User = User::factory()->create();
    $reseller2User->assignRole('reseller');
    $reseller2 = Reseller::factory()->create(['user_id' => $reseller2User->id]);
    
    $config1 = ResellerConfig::factory()->create(['reseller_id' => $reseller1->id]);
    $config2 = ResellerConfig::factory()->create(['reseller_id' => $reseller2->id]);
    
    // Reseller 1 can access their own config but not reseller 2's
    expect($reseller1User->can('view', $config1))->toBeTrue()
        ->and($reseller1User->can('update', $config1))->toBeTrue()
        ->and($reseller1User->can('view', $config2))->toBeFalse()
        ->and($reseller1User->can('update', $config2))->toBeFalse();
});

test('canAccessPanel works correctly', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $superAdmin->assignRole('super-admin');
    
    $admin = User::factory()->create(['is_admin' => true]);
    $admin->assignRole('admin');
    
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');
    
    $user = User::factory()->create();
    $user->assignRole('user');
    
    $adminPanel = new \Filament\Panel('admin');
    $adminPanel->id('admin');
    
    expect($superAdmin->canAccessPanel($adminPanel))->toBeTrue()
        ->and($admin->canAccessPanel($adminPanel))->toBeTrue()
        ->and($reseller->canAccessPanel($adminPanel))->toBeFalse()
        ->and($user->canAccessPanel($adminPanel))->toBeFalse();
});
