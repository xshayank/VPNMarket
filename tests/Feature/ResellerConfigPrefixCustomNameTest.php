<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reseller\Services\ResellerProvisioner;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
    $this->user = User::factory()->create(['is_admin' => false]);
    $this->panel = Panel::factory()->create(['panel_type' => 'marzban', 'is_active' => true]);

    $this->reseller = Reseller::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'username_prefix' => 'resell',
    ]);
});

test('reseller config can be created with a custom prefix', function () {
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'test_user_123',
        'prefix' => 'myprefix',
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 0,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);

    expect($config->prefix)->toBe('myprefix');

    \Pest\Laravel\assertDatabaseHas('reseller_configs', [
        'id' => $config->id,
        'prefix' => 'myprefix',
    ]);
});

test('reseller config can be created with a custom name', function () {
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'custom_username_456',
        'custom_name' => 'custom_username_456',
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 0,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);

    expect($config->custom_name)->toBe('custom_username_456');

    \Pest\Laravel\assertDatabaseHas('reseller_configs', [
        'id' => $config->id,
        'custom_name' => 'custom_username_456',
    ]);
});

test('reseller config can be created without prefix or custom name', function () {
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'test_user_789',
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 0,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);

    expect($config->prefix)->toBeNull();
    expect($config->custom_name)->toBeNull();

    \Pest\Laravel\assertDatabaseHas('reseller_configs', [
        'id' => $config->id,
        'prefix' => null,
        'custom_name' => null,
    ]);
});

test('prefix validation rejects invalid characters', function () {
    $invalidPrefix = 'invalid@prefix!';

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['prefix' => $invalidPrefix],
        ['prefix' => 'nullable|string|max:50|regex:/^[a-zA-Z0-9_-]+$/']
    );

    expect($validator->fails())->toBeTrue();
});

test('prefix validation accepts valid characters', function () {
    $validPrefix = 'valid_prefix-123';

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['prefix' => $validPrefix],
        ['prefix' => 'nullable|string|max:50|regex:/^[a-zA-Z0-9_-]+$/']
    );

    expect($validator->passes())->toBeTrue();
});

test('custom_name validation rejects invalid characters', function () {
    $invalidCustomName = 'invalid@name!';

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['custom_name' => $invalidCustomName],
        ['custom_name' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_-]+$/']
    );

    expect($validator->fails())->toBeTrue();
});

test('custom_name validation accepts valid characters', function () {
    $validCustomName = 'valid_custom-name_123';

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['custom_name' => $validCustomName],
        ['custom_name' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_-]+$/']
    );

    expect($validator->passes())->toBeTrue();
});

test('generateUsername uses custom name when provided', function () {
    $provisioner = new ResellerProvisioner;

    $username = $provisioner->generateUsername(
        $this->reseller,
        'config',
        123,
        null,
        null,
        'my_custom_name'
    );

    expect($username)->toBe('my_custom_name');
});

test('generateUsername uses custom prefix when provided', function () {
    $provisioner = new ResellerProvisioner;

    $username = $provisioner->generateUsername(
        $this->reseller,
        'config',
        123,
        null,
        'myprefix',
        null
    );

    expect($username)->toBe("myprefix_{$this->reseller->id}_cfg_123");
});

test('generateUsername uses reseller default prefix when no custom prefix or name', function () {
    $provisioner = new ResellerProvisioner;

    $username = $provisioner->generateUsername(
        $this->reseller,
        'config',
        123,
        null,
        null,
        null
    );

    expect($username)->toBe("resell_{$this->reseller->id}_cfg_123");
});

test('generateUsername prioritizes custom name over custom prefix', function () {
    $provisioner = new ResellerProvisioner;

    $username = $provisioner->generateUsername(
        $this->reseller,
        'config',
        123,
        null,
        'myprefix',
        'my_custom_name'
    );

    expect($username)->toBe('my_custom_name');
});

test('prefix cannot exceed 50 characters', function () {
    $longPrefix = str_repeat('a', 51);

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['prefix' => $longPrefix],
        ['prefix' => 'nullable|string|max:50|regex:/^[a-zA-Z0-9_-]+$/']
    );

    expect($validator->fails())->toBeTrue();
});

test('prefix can be exactly 50 characters', function () {
    $prefix50 = str_repeat('a', 50);

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['prefix' => $prefix50],
        ['prefix' => 'nullable|string|max:50|regex:/^[a-zA-Z0-9_-]+$/']
    );

    expect($validator->passes())->toBeTrue();
});

test('custom_name cannot exceed 100 characters', function () {
    $longCustomName = str_repeat('a', 101);

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['custom_name' => $longCustomName],
        ['custom_name' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_-]+$/']
    );

    expect($validator->fails())->toBeTrue();
});

test('custom_name can be exactly 100 characters', function () {
    $customName100 = str_repeat('a', 100);

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['custom_name' => $customName100],
        ['custom_name' => 'nullable|string|max:100|regex:/^[a-zA-Z0-9_-]+$/']
    );

    expect($validator->passes())->toBeTrue();
});

test('traffic and time limits work correctly with custom prefix', function () {
    $limitBytes = 10 * 1024 * 1024 * 1024; // 10 GB
    $usageBytes = 5 * 1024 * 1024 * 1024; // 5 GB
    $expiresAt = now()->addDays(30);

    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'custom_user_123',
        'prefix' => 'custom',
        'traffic_limit_bytes' => $limitBytes,
        'usage_bytes' => $usageBytes,
        'expires_at' => $expiresAt,
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);

    // Test traffic limit
    expect($config->hasTrafficRemaining())->toBeTrue();
    expect($config->traffic_limit_bytes)->toBe($limitBytes);
    expect($config->usage_bytes)->toBe($usageBytes);

    // Test time limit
    expect($config->expires_at->format('Y-m-d'))->toBe($expiresAt->format('Y-m-d'));
    expect($config->isExpiredByTime())->toBeFalse();
});

test('traffic and time limits work correctly with custom name', function () {
    $limitBytes = 20 * 1024 * 1024 * 1024; // 20 GB
    $usageBytes = 15 * 1024 * 1024 * 1024; // 15 GB
    $expiresAt = now()->addDays(60);

    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'full_custom_name',
        'custom_name' => 'full_custom_name',
        'traffic_limit_bytes' => $limitBytes,
        'usage_bytes' => $usageBytes,
        'expires_at' => $expiresAt,
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);

    // Test traffic limit
    expect($config->hasTrafficRemaining())->toBeTrue();
    expect($config->traffic_limit_bytes)->toBe($limitBytes);
    expect($config->usage_bytes)->toBe($usageBytes);

    // Test time limit
    expect($config->expires_at->format('Y-m-d'))->toBe($expiresAt->format('Y-m-d'));
    expect($config->isExpiredByTime())->toBeFalse();
});
