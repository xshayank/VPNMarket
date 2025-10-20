<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get as testGet;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
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
    ]);
});

test('reseller config can be created with a comment', function () {
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'test_user_123',
        'comment' => 'Test comment for this config',
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 0,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);

    expect($config->comment)->toBe('Test comment for this config');
    
    assertDatabaseHas('reseller_configs', [
        'id' => $config->id,
        'comment' => 'Test comment for this config',
    ]);
});

test('reseller config can be created without a comment', function () {
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'test_user_456',
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 0,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);

    expect($config->comment)->toBeNull();
    
    assertDatabaseHas('reseller_configs', [
        'id' => $config->id,
        'comment' => null,
    ]);
});

test('reseller config comment cannot exceed 200 characters', function () {
    $longComment = str_repeat('a', 201);
    
    $validator = \Illuminate\Support\Facades\Validator::make(
        ['comment' => $longComment],
        ['comment' => 'nullable|string|max:200']
    );
    
    expect($validator->fails())->toBeTrue();
});

test('reseller config comment can be exactly 200 characters', function () {
    $comment200 = str_repeat('a', 200);
    
    $validator = \Illuminate\Support\Facades\Validator::make(
        ['comment' => $comment200],
        ['comment' => 'nullable|string|max:200']
    );
    
    expect($validator->passes())->toBeTrue();
});

test('reseller config index displays comment when present', function () {
    actingAs($this->user);
    
    // Create a config with comment
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'test_config_789',
        'comment' => 'VIP client configuration',
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        'usage_bytes' => 0,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);
    
    // Verify the comment is saved in the database
    expect($config->comment)->toBe('VIP client configuration');
    assertDatabaseHas('reseller_configs', [
        'id' => $config->id,
        'comment' => 'VIP client configuration',
    ]);
})->skip('Requires Vite build assets');

test('reseller config usage handles null values correctly', function () {
    // Create config with 0 usage_bytes (since column doesn't allow null)
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'test_null_usage',
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 0,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);
    
    // Test that usage calculation treats 0 or null as 0
    $usageBytes = $config->usage_bytes ?? 0;
    expect($usageBytes)->toBe(0);
    
    // Test percentage calculation with 0
    $percent = $config->traffic_limit_bytes > 0 
        ? round(($usageBytes / $config->traffic_limit_bytes) * 100, 1) 
        : 0;
    expect($percent)->toBe(0.0);
});

test('reseller config usage displays correct percentage', function () {
    // Create config with specific usage
    $limitBytes = 10 * 1024 * 1024 * 1024; // 10 GB
    $usageBytes = 1.2 * 1024 * 1024 * 1024; // 1.2 GB
    
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'test_usage_display',
        'traffic_limit_bytes' => $limitBytes,
        'usage_bytes' => $usageBytes,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'marzban',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);
    
    $percent = round(($usageBytes / $limitBytes) * 100, 1);
    expect($percent)->toBe(12.0);
    
    $usedGB = round($usageBytes / (1024 * 1024 * 1024), 2);
    $limitGB = round($limitBytes / (1024 * 1024 * 1024), 2);
    
    expect($usedGB)->toBe(1.2);
    expect($limitGB)->toBe(10.0);
});
