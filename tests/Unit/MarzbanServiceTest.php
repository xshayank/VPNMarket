<?php

use App\Services\MarzbanService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

beforeEach(function () {
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
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

test('createUser sends correct API request with array format', function () {
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
        'expire' => 1735689600, // 2025-01-01 00:00:00 UTC
        'data_limit' => 10737418240, // 10 GB in bytes
    ];

    $result = $service->createUser($userData);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('username')
        ->and($result['username'])->toBe('testuser')
        ->and($result)->toHaveKey('subscription_url');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://example.com/api/user'
            && $body['username'] === 'testuser'
            && $body['expire'] === 1735689600
            && $body['data_limit'] === 10737418240;
    });
});

test('buildAbsoluteSubscriptionUrl returns absolute URL with path', function () {
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

test('buildAbsoluteSubscriptionUrl handles trailing slash on hostname', function () {
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
        'subscription_url' => '/sub/test',
    ];

    $absoluteUrl = $service->buildAbsoluteSubscriptionUrl($userApiResponse);
    $labeledMessage = $service->generateSubscriptionLink($userApiResponse);

    // The labeled message should contain the same absolute URL
    expect($labeledMessage)->toContain($absoluteUrl)
        ->and($absoluteUrl)->toBe('https://node.example.com/sub/test');
});
