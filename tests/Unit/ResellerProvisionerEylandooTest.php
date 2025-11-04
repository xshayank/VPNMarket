<?php

use App\Models\Panel;
use App\Models\Plan;
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

test('provisionEylandoo accepts node_ids parameter', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['test_user'],
            'message' => '1 user(s) created successfully.',
        ], 200),
        '*/api/v1/users/test_user/sub' => Http::response([
            'subscription_url' => '/sub/test_user',
        ], 200),
    ]);

    $panel = Panel::factory()->eylandoo()->create();

    $plan = Plan::factory()->create([
        'panel_id' => $panel->id,
        'duration_days' => 30,
        'volume_gb' => 10,
    ]);

    $provisioner = new ResellerProvisioner();

    $result = $provisioner->provisionUser($panel, $plan, 'test_user', [
        'node_ids' => [1, 2, 3],
        'connections' => 2,
    ]);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('username')
        ->and($result)->toHaveKey('subscription_url')
        ->and($result['subscription_url'])->not->toBeNull();

    Http::assertSent(function ($request) {
        if (!str_contains($request->url(), '/api/v1/users') || $request->method() !== 'POST') {
            return false;
        }
        
        $body = $request->data();
        return isset($body['nodes']) && $body['nodes'] === [1, 2, 3];
    });
});

test('provisionEylandoo omits nodes when empty', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['test_user'],
            'message' => '1 user(s) created successfully.',
        ], 200),
        '*/api/v1/users/test_user/sub' => Http::response([
            'data' => [
                'subscription_url' => '/sub/test_user',
            ],
        ], 200),
    ]);

    $panel = Panel::factory()->eylandoo()->create();

    $plan = Plan::factory()->create([
        'panel_id' => $panel->id,
        'duration_days' => 30,
        'volume_gb' => 10,
    ]);

    $provisioner = new ResellerProvisioner();

    $result = $provisioner->provisionUser($panel, $plan, 'test_user', [
        'node_ids' => [], // Empty array
        'connections' => 2,
    ]);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('subscription_url');

    Http::assertSent(function ($request) {
        if (!str_contains($request->url(), '/api/v1/users') || $request->method() !== 'POST') {
            return false;
        }
        
        $body = $request->data();
        return !isset($body['nodes']); // Nodes should not be in the request
    });
});

test('provisionEylandoo detects success from created_users array', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['test_user'],
            'message' => '1 user(s) created successfully.',
        ], 200),
        '*/api/v1/users/test_user/sub' => Http::response([
            'url' => '/sub/test_user',
        ], 200),
    ]);

    $panel = Panel::factory()->eylandoo()->create();

    $plan = Plan::factory()->create([
        'panel_id' => $panel->id,
        'duration_days' => 30,
        'volume_gb' => 10,
    ]);

    $provisioner = new ResellerProvisioner();

    $result = $provisioner->provisionUser($panel, $plan, 'test_user');

    expect($result)->toBeArray()
        ->and($result['username'])->toBe('test_user')
        ->and($result['panel_type'])->toBe('eylandoo');
});

test('provisionEylandoo fetches user subscription when no subscription URL in create response', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['test_user'],
            'message' => '1 user(s) created successfully.',
        ], 200),
        '*/api/v1/users/test_user/sub' => Http::response([
            'subscription_url' => 'https://example.com/sub/test_user',
        ], 200),
    ]);

    $panel = Panel::factory()->eylandoo()->create();

    $plan = Plan::factory()->create([
        'panel_id' => $panel->id,
        'duration_days' => 30,
        'volume_gb' => 10,
    ]);

    $provisioner = new ResellerProvisioner();

    $result = $provisioner->provisionUser($panel, $plan, 'test_user');

    expect($result)->toBeArray()
        ->and($result['subscription_url'])->toBe('https://example.com/sub/test_user');

    // Verify getUserSubscription was called (the /sub endpoint)
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/v1/users/test_user/sub') && $request->method() === 'GET';
    });
});

test('provisionEylandoo handles both connections and max_clients parameters', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['test_user'],
            'message' => '1 user(s) created successfully.',
        ], 200),
        '*/api/v1/users/test_user/sub' => Http::response([
            'subscription_url' => '/sub/test_user',
        ], 200),
    ]);

    $panel = Panel::factory()->eylandoo()->create();

    $plan = Plan::factory()->create([
        'panel_id' => $panel->id,
        'duration_days' => 30,
        'volume_gb' => 10,
    ]);

    $provisioner = new ResellerProvisioner();

    // Test with 'connections' parameter
    $result = $provisioner->provisionUser($panel, $plan, 'test_user', [
        'connections' => 5,
    ]);

    expect($result)->toBeArray();

    Http::assertSent(function ($request) {
        if (!str_contains($request->url(), '/api/v1/users') || $request->method() !== 'POST') {
            return false;
        }
        
        $body = $request->data();
        return isset($body['max_clients']) && $body['max_clients'] === 5;
    });
});

test('provisionEylandoo handles /sub endpoint failure gracefully', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['test_user'],
            'message' => '1 user(s) created successfully.',
        ], 200),
        '*/api/v1/users/test_user/sub' => Http::response([], 404),
    ]);

    $panel = Panel::factory()->eylandoo()->create();

    $plan = Plan::factory()->create([
        'panel_id' => $panel->id,
        'duration_days' => 30,
        'volume_gb' => 10,
    ]);

    $provisioner = new ResellerProvisioner();

    $result = $provisioner->provisionUser($panel, $plan, 'test_user');

    // Should still return result, but subscription_url will be null
    expect($result)->toBeArray()
        ->and($result['username'])->toBe('test_user')
        ->and($result['subscription_url'])->toBeNull();
});

test('provisionEylandoo handles /sub endpoint returning no URL', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['test_user'],
            'message' => '1 user(s) created successfully.',
        ], 200),
        '*/api/v1/users/test_user/sub' => Http::response([
            'data' => ['username' => 'test_user'],
        ], 200),
    ]);

    $panel = Panel::factory()->eylandoo()->create();

    $plan = Plan::factory()->create([
        'panel_id' => $panel->id,
        'duration_days' => 30,
        'volume_gb' => 10,
    ]);

    $provisioner = new ResellerProvisioner();

    $result = $provisioner->provisionUser($panel, $plan, 'test_user');

    // Should still return result, but subscription_url will be null
    expect($result)->toBeArray()
        ->and($result['username'])->toBe('test_user')
        ->and($result['subscription_url'])->toBeNull();
});
