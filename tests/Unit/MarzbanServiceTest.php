<?php

use App\Services\MarzbanService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

beforeEach(function () {
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
});

test('constructor initializes properties correctly', function () {
    $service = new MarzbanService(
        'https://example.com/',
        'admin',
        'password',
        'https://node.example.com'
    );

    expect($service)->toBeInstanceOf(MarzbanService::class);
});

test('login returns true on successful authentication', function () {
    Http::fake([
        '*/api/admin/token' => Http::response([
            'access_token' => 'test-token-123',
        ], 200),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $result = $service->login();

    expect($result)->toBeTrue();
});

test('login returns false on failed authentication', function () {
    Http::fake([
        '*/api/admin/token' => Http::response([
            'detail' => 'Invalid credentials',
        ], 401),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'wrong-password',
        'https://node.example.com'
    );

    $result = $service->login();

    expect($result)->toBeFalse();
});

test('createUser sends correct API request with associative array', function () {
    Http::fake([
        '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/user' => Http::response([
            'username' => 'testuser',
            'subscription_url' => '/sub/abc123',
        ], 200),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $userData = [
        'username' => 'testuser',
        'expire' => 1735689600,
        'data_limit' => 10737418240,
    ];

    $result = $service->createUser($userData);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('username')
        ->and($result['username'])->toBe('testuser');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://example.com/api/user'
            && $body['username'] === 'testuser'
            && $body['expire'] === 1735689600
            && $body['data_limit'] === 10737418240;
    });
});

test('buildAbsoluteSubscriptionUrl returns absolute URL from relative path', function () {
    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $userApiResponse = [
        'username' => 'testuser',
        'subscription_url' => '/sub/abc123xyz',
    ];

    $result = $service->buildAbsoluteSubscriptionUrl($userApiResponse);

    expect($result)->toBe('https://node.example.com/sub/abc123xyz');
});

test('buildAbsoluteSubscriptionUrl handles node hostname with trailing slash', function () {
    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com/'
    );

    $userApiResponse = [
        'username' => 'testuser',
        'subscription_url' => '/sub/abc123xyz',
    ];

    $result = $service->buildAbsoluteSubscriptionUrl($userApiResponse);

    expect($result)->toBe('https://node.example.com/sub/abc123xyz');
});

test('generateSubscriptionLink returns labeled message with absolute URL', function () {
    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $userApiResponse = [
        'username' => 'testuser',
        'subscription_url' => '/sub/abc123xyz',
    ];

    $result = $service->generateSubscriptionLink($userApiResponse);

    expect($result)->toBeString()
        ->and($result)->toContain('https://node.example.com/sub/abc123xyz')
        ->and($result)->toContain('لینک سابسکریپشن شما');
});

test('generateSubscriptionLink uses buildAbsoluteSubscriptionUrl internally', function () {
    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com/'
    );

    $userApiResponse = [
        'username' => 'testuser',
        'subscription_url' => '/sub/test123',
    ];

    $absoluteUrl = $service->buildAbsoluteSubscriptionUrl($userApiResponse);
    $labeledMessage = $service->generateSubscriptionLink($userApiResponse);

    // The labeled message should contain the absolute URL
    expect($labeledMessage)->toContain($absoluteUrl)
        ->and($absoluteUrl)->toBe('https://node.example.com/sub/test123');
});

test('buildAbsoluteSubscriptionUrl returns absolute URL when already absolute', function () {
    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $userApiResponse = [
        'username' => 'testuser',
        'subscription_url' => 'https://cdn.example.com/sub/abc123xyz',
    ];

    $result = $service->buildAbsoluteSubscriptionUrl($userApiResponse);

    expect($result)->toBe('https://cdn.example.com/sub/abc123xyz');
});

test('buildAbsoluteSubscriptionUrl falls back to baseUrl when nodeHostname is empty', function () {
    $service = new MarzbanService(
        'https://panel.example.com',
        'admin',
        'password',
        '' // Empty nodeHostname
    );

    $userApiResponse = [
        'username' => 'testuser',
        'subscription_url' => '/sub/abc123xyz',
    ];

    $result = $service->buildAbsoluteSubscriptionUrl($userApiResponse);

    expect($result)->toBe('https://panel.example.com/sub/abc123xyz');
});

test('buildAbsoluteSubscriptionUrl handles path without leading slash', function () {
    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $userApiResponse = [
        'username' => 'testuser',
        'subscription_url' => 'sub/abc123xyz',
    ];

    $result = $service->buildAbsoluteSubscriptionUrl($userApiResponse);

    expect($result)->toBe('https://node.example.com/sub/abc123xyz');
});

test('deleteUser returns true on successful deletion', function () {
    Http::fake([
        '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/user/testuser' => Http::response([], 204),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $result = $service->deleteUser('testuser');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/api/user/testuser'
            && $request->method() === 'DELETE';
    });
});

test('deleteUser returns false on authentication failure', function () {
    Http::fake([
        '*/api/admin/token' => Http::response(['detail' => 'Invalid credentials'], 401),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'wrong-password',
        'https://node.example.com'
    );

    $result = $service->deleteUser('testuser');

    expect($result)->toBeFalse();
});

test('deleteUser handles exceptions gracefully', function () {
    Http::fake([
        '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/user/*' => fn () => throw new \Exception('Network error'),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $result = $service->deleteUser('testuser');

    expect($result)->toBeFalse();
});

test('getUser returns user data on successful request', function () {
    Http::fake([
        '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/user/testuser' => Http::response([
            'username' => 'testuser',
            'used_traffic' => 1073741824, // 1 GB
            'data_limit' => 10737418240, // 10 GB
            'status' => 'active',
        ], 200),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $result = $service->getUser('testuser');

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('username')
        ->and($result['username'])->toBe('testuser')
        ->and($result)->toHaveKey('used_traffic')
        ->and($result['used_traffic'])->toBe(1073741824);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/api/user/testuser'
            && $request->method() === 'GET';
    });
});

test('getUser authenticates automatically if not logged in', function () {
    Http::fake([
        '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/user/*' => Http::response(['username' => 'testuser', 'used_traffic' => 0], 200),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $service->getUser('testuser');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/admin/token');
    });
});

test('getUser returns null on authentication failure', function () {
    Http::fake([
        '*/api/admin/token' => Http::response(['detail' => 'Invalid credentials'], 401),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'wrong-password',
        'https://node.example.com'
    );

    $result = $service->getUser('testuser');

    expect($result)->toBeNull();
});

test('getUser returns null on API error', function () {
    Http::fake([
        '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/user/testuser' => Http::response(['error' => 'User not found'], 404),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $result = $service->getUser('testuser');

    expect($result)->toBeNull();
});

test('getUser handles exceptions gracefully', function () {
    Http::fake([
        '*/api/admin/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/user/*' => fn () => throw new \Exception('Network error'),
    ]);

    $service = new MarzbanService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $result = $service->getUser('testuser');

    expect($result)->toBeNull();
});

