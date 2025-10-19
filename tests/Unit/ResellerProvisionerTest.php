<?php

use App\Models\Panel;
use App\Models\Plan;
use App\Models\Reseller;
use App\Models\User;
use App\Services\MarzneshinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerProvisioner;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
});

test('provisionMarzneshin passes correct array to MarzneshinService createUser', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users' => Http::response([
            'username' => 'testuser',
            'subscription_url' => '/sub/abc123',
        ], 200),
    ]);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'username_prefix' => 'test',
    ]);

    $panel = Panel::factory()->marzneshin()->create();

    $plan = Plan::factory()->create([
        'panel_id' => $panel->id,
        'duration_days' => 30,
        'volume_gb' => 10,
        'marzneshin_service_ids' => [1, 2, 3],
    ]);

    $provisioner = new ResellerProvisioner();
    $result = $provisioner->provisionUser($panel, $plan, 'test_user_1');

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('username')
        ->and($result)->toHaveKey('subscription_url')
        ->and($result['panel_type'])->toBe('marzneshin');

    // Verify the HTTP request was made with correct array structure
    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/api/users')
            && isset($body['username'])
            && isset($body['expire_date'])
            && isset($body['data_limit'])
            && isset($body['service_ids'])
            && is_array($body['service_ids']);
    });
});

test('provisionMarzneshin handles missing service_ids gracefully', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users' => Http::response([
            'username' => 'testuser',
            'subscription_url' => '/sub/abc123',
        ], 200),
    ]);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
    ]);

    $panel = Panel::factory()->marzneshin()->create();

    $plan = Plan::factory()->create([
        'panel_id' => $panel->id,
        'duration_days' => 30,
        'volume_gb' => 10,
        'marzneshin_service_ids' => null,
    ]);

    $provisioner = new ResellerProvisioner();
    $result = $provisioner->provisionUser($panel, $plan, 'test_user_2');

    expect($result)->toBeArray();

    // Verify service_ids is an empty array when not provided
    Http::assertSent(function ($request) {
        $body = $request->data();

        return isset($body['service_ids']) && is_array($body['service_ids']) && count($body['service_ids']) === 0;
    });
});

test('provisionMarzneshin returns null on login failure', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['detail' => 'Invalid credentials'], 401),
    ]);

    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
    ]);

    $panel = Panel::factory()->marzneshin()->create();

    $plan = Plan::factory()->create([
        'panel_id' => $panel->id,
        'duration_days' => 30,
        'volume_gb' => 10,
    ]);

    $provisioner = new ResellerProvisioner();
    $result = $provisioner->provisionUser($panel, $plan, 'test_user_3');

    expect($result)->toBeNull();
});

test('generateUsername creates correct format for orders', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'id' => 5,
        'user_id' => $user->id,
        'username_prefix' => 'myprefix',
    ]);

    $provisioner = new ResellerProvisioner();
    $username = $provisioner->generateUsername($reseller, 'order', 123, 1);

    expect($username)->toBe('myprefix_5_order_123_1');
});

test('generateUsername creates correct format for configs', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'id' => 7,
        'user_id' => $user->id,
        'username_prefix' => 'test',
    ]);

    $provisioner = new ResellerProvisioner();
    $username = $provisioner->generateUsername($reseller, 'config', 456);

    expect($username)->toBe('test_7_cfg_456');
});
