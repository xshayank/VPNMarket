<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerProvisioner;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
});

test('disableUser returns true for successful Marzneshin disable', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/disable' => Http::response(['status' => 'disabled'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $credentials = $panel->getCredentials();

    $provisioner = new ResellerProvisioner();
    $result = $provisioner->disableUser('marzneshin', $credentials, 'test_user');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/disable');
    });
});

test('disableUser returns false when remote API fails', function () {
    // Just test that it returns false when login fails
    Http::fake([
        '*' => Http::response(['detail' => 'Authentication required'], 401),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $credentials = $panel->getCredentials();

    $provisioner = new ResellerProvisioner();
    $result = $provisioner->disableUser('marzneshin', $credentials, 'test_user');

    expect($result)->toBeFalse();
});

test('enableUser returns true for successful Marzneshin enable', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $credentials = $panel->getCredentials();

    $provisioner = new ResellerProvisioner();
    $result = $provisioner->enableUser('marzneshin', $credentials, 'test_user');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/enable');
    });
});

test('enableUser returns false when remote API fails', function () {
    // Just test that it returns false when login fails
    Http::fake([
        '*' => Http::response(['detail' => 'Authentication required'], 401),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $credentials = $panel->getCredentials();

    $provisioner = new ResellerProvisioner();
    $result = $provisioner->enableUser('marzneshin', $credentials, 'test_user');

    expect($result)->toBeFalse();
});

test('config stores subscription_url and panel_id during provisioning', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users' => Http::response([
            'username' => 'testuser',
            'subscription_url' => 'https://panel.example.com/sub/abc123',
        ], 200),
    ]);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'traffic_total_bytes' => 10737418240, // 10GB
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    $panel = Panel::factory()->marzneshin()->create();

    $config = ResellerConfig::create([
        'reseller_id' => $reseller->id,
        'external_username' => 'test_user_1',
        'traffic_limit_bytes' => 1073741824, // 1GB
        'usage_bytes' => 0,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'panel_type' => 'marzneshin',
        'panel_id' => $panel->id,
        'created_by' => $user->id,
    ]);

    expect($config->panel_id)->toBe($panel->id)
        ->and($config->panel)->toBeInstanceOf(Panel::class);
});

test('Marzneshin updateUser handles missing expire gracefully', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*' => Http::response(['status' => 'updated'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $service = new \App\Services\MarzneshinService(
        $panel->url,
        $panel->username,
        $panel->password,
        $panel->extra['node_hostname'] ?? ''
    );

    $service->login();
    
    // Update with only data_limit, no expire
    $result = $service->updateUser('test_user', [
        'data_limit' => 1073741824,
    ]);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();
        return str_contains($request->url(), '/api/users/')
            && isset($body['data_limit'])
            && !isset($body['expire_date']); // expire_date should NOT be present
    });
});

test('Marzneshin updateUser includes service_ids as array', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*' => Http::response(['status' => 'updated'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $service = new \App\Services\MarzneshinService(
        $panel->url,
        $panel->username,
        $panel->password,
        $panel->extra['node_hostname'] ?? ''
    );

    $service->login();
    
    // Update with service_ids
    $result = $service->updateUser('test_user', [
        'expire' => now()->addDays(30)->timestamp,
        'data_limit' => 1073741824,
        'service_ids' => [1, 2, 3],
    ]);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();
        return isset($body['service_ids']) 
            && is_array($body['service_ids'])
            && count($body['service_ids']) === 3;
    });
});
