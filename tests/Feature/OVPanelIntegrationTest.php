<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get as testGet;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->user = User::factory()->create(['is_admin' => false]);
    $this->panel = Panel::factory()->create(['panel_type' => 'ovpanel', 'is_active' => true]);

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

test('ovpanel config can be created with ovpn file fields', function () {
    $config = ResellerConfig::create([
        'reseller_id' => $this->reseller->id,
        'external_username' => 'test_ovpanel_user',
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 0,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'panel_user_id' => 'test_ovpanel_user',
        'created_by' => $this->user->id,
        'ovpn_path' => 'ovpn/test.ovpn',
        'ovpn_token' => bin2hex(random_bytes(32)),
    ]);

    expect($config->panel_type)->toBe('ovpanel')
        ->and($config->ovpn_path)->toBe('ovpn/test.ovpn')
        ->and($config->ovpn_token)->toBeString()
        ->and(strlen($config->ovpn_token))->toBe(64);

    assertDatabaseHas('reseller_configs', [
        'id' => $config->id,
        'panel_type' => 'ovpanel',
        'ovpn_path' => 'ovpn/test.ovpn',
    ]);
});

test('isOvpanel method returns true for ovpanel configs', function () {
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);

    expect($config->isOvpanel())->toBeTrue();
});

test('isOvpanel method returns false for non-ovpanel configs', function () {
    $marzbanPanel = Panel::factory()->create(['panel_type' => 'marzban']);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'marzban',
        'panel_id' => $marzbanPanel->id,
        'created_by' => $this->user->id,
    ]);

    expect($config->isOvpanel())->toBeFalse();
});

test('generateOvpnToken creates a valid token', function () {
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);

    $config->generateOvpnToken();
    $config->save();

    expect($config->ovpn_token)->toBeString()
        ->and(strlen($config->ovpn_token))->toBe(64)
        ->and($config->ovpn_token_expires_at)->toBeNull();
});

test('generateOvpnToken with expiration sets expiry date', function () {
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
    ]);

    $config->generateOvpnToken(24); // 24 hours
    $config->save();

    expect($config->ovpn_token)->toBeString()
        ->and($config->ovpn_token_expires_at)->not->toBeNull()
        ->and($config->ovpn_token_expires_at->isFuture())->toBeTrue();
});

test('isOvpnTokenValid returns true for valid token without expiry', function () {
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
        'ovpn_token' => bin2hex(random_bytes(32)),
        'ovpn_token_expires_at' => null,
    ]);

    expect($config->isOvpnTokenValid())->toBeTrue();
});

test('isOvpnTokenValid returns true for non-expired token', function () {
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
        'ovpn_token' => bin2hex(random_bytes(32)),
        'ovpn_token_expires_at' => now()->addHours(24),
    ]);

    expect($config->isOvpnTokenValid())->toBeTrue();
});

test('isOvpnTokenValid returns false for expired token', function () {
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
        'ovpn_token' => bin2hex(random_bytes(32)),
        'ovpn_token_expires_at' => now()->subHours(1),
    ]);

    expect($config->isOvpnTokenValid())->toBeFalse();
});

test('isOvpnTokenValid returns false for missing token', function () {
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
        'ovpn_token' => null,
    ]);

    expect($config->isOvpnTokenValid())->toBeFalse();
});

test('ovpn download route returns 404 for invalid token', function () {
    testGet(route('ovpn.download.token', ['token' => 'invalid_token']))
        ->assertStatus(404);
});

test('ovpn download route returns 403 for expired token', function () {
    Storage::put('ovpn/test.ovpn', 'test ovpn content');

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
        'ovpn_path' => 'ovpn/test.ovpn',
        'ovpn_token' => bin2hex(random_bytes(32)),
        'ovpn_token_expires_at' => now()->subHours(1), // Expired
    ]);

    testGet(route('ovpn.download.token', ['token' => $config->ovpn_token]))
        ->assertStatus(403);
});

test('ovpn download route returns file for valid token', function () {
    $content = 'client
dev tun
proto tcp
remote example.com 1194';

    Storage::put('ovpn/test.ovpn', $content);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
        'ovpn_path' => 'ovpn/test.ovpn',
        'ovpn_token' => bin2hex(random_bytes(32)),
    ]);

    $response = testGet(route('ovpn.download.token', ['token' => $config->ovpn_token]))
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/x-openvpn-profile');

    expect($response->streamedContent())->toContain('client');
});

test('ovpn download logs audit event', function () {
    Storage::put('ovpn/test.ovpn', 'test ovpn content');

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
        'ovpn_path' => 'ovpn/test.ovpn',
        'ovpn_token' => bin2hex(random_bytes(32)),
    ]);

    testGet(route('ovpn.download.token', ['token' => $config->ovpn_token]));

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'config_ovpn_downloaded',
        'target_type' => ResellerConfig::class,
        'target_id' => $config->id,
    ]);
});

test('authenticated reseller can download their config', function () {
    Storage::put('ovpn/test.ovpn', 'test ovpn content');

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $this->user->id,
        'ovpn_path' => 'ovpn/test.ovpn',
        'ovpn_token' => bin2hex(random_bytes(32)),
    ]);

    actingAs($this->user)
        ->get(route('ovpn.download.reseller', ['id' => $config->id]))
        ->assertStatus(200);
});

test('reseller cannot download another resellers config', function () {
    $otherUser = User::factory()->create(['is_admin' => false]);
    $otherReseller = Reseller::factory()->create([
        'user_id' => $otherUser->id,
        'type' => 'traffic',
        'status' => 'active',
    ]);

    Storage::put('ovpn/test.ovpn', 'test ovpn content');

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $otherReseller->id,
        'panel_type' => 'ovpanel',
        'panel_id' => $this->panel->id,
        'created_by' => $otherUser->id,
        'ovpn_path' => 'ovpn/test.ovpn',
        'ovpn_token' => bin2hex(random_bytes(32)),
    ]);

    actingAs($this->user)
        ->get(route('ovpn.download.reseller', ['id' => $config->id]))
        ->assertStatus(403);
});
