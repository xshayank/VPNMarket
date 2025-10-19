<?php

use App\Services\MarzneshinService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

beforeEach(function () {
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
});

test('updateUser includes username in PUT request body', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/testuser' => Http::response(['success' => true], 200),
    ]);

    $service = new MarzneshinService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $userData = [
        'expire' => 1767225600,
        'data_limit' => 21474836480,
    ];

    $result = $service->updateUser('testuser', $userData);

    expect($result)->toBeTrue();

    // Verify that username was included in the PUT body
    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://example.com/api/users/testuser') {
            return false;
        }

        $body = $request->data();

        return isset($body['username'])
            && $body['username'] === 'testuser';
    });
});

test('updateUser returns boolean true for successful API response', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/user_1_order_5' => Http::response([
            'username' => 'user_1_order_5',
            'data_limit' => 10737418240,
            'expire_date' => '2026-01-01T00:00:00+00:00',
        ], 200),
    ]);

    $service = new MarzneshinService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $userData = [
        'expire' => 1767225600,
        'data_limit' => 10737418240,
        'service_ids' => [1, 2],
    ];

    $result = $service->updateUser('user_1_order_5', $userData);

    expect($result)->toBeTrue()
        ->and($result)->not->toBeArray();
});

test('updateUser returns boolean false when API returns error', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/testuser' => Http::response([
            'detail' => ['username' => 'Field required'],
        ], 422),
    ]);

    $service = new MarzneshinService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $userData = [
        'expire' => 1767225600,
        'data_limit' => 10737418240,
    ];

    $result = $service->updateUser('testuser', $userData);

    expect($result)->toBeFalse();
});

test('updateUser includes all required fields in PUT body', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/testuser' => Http::response(['success' => true], 200),
    ]);

    $service = new MarzneshinService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $userData = [
        'expire' => 1767225600,
        'data_limit' => 21474836480,
        'service_ids' => [1, 2, 3],
    ];

    $service->updateUser('testuser', $userData);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://example.com/api/users/testuser'
            && $request->method() === 'PUT'
            && isset($body['username'])
            && $body['username'] === 'testuser'
            && isset($body['expire_strategy'])
            && $body['expire_strategy'] === 'fixed_date'
            && isset($body['expire_date'])
            && isset($body['data_limit'])
            && $body['data_limit'] === 21474836480
            && isset($body['service_ids'])
            && $body['service_ids'] === [1, 2, 3];
    });
});

test('renewal scenario: updateUser succeeds without requiring subscription_url in response', function () {
    // Simulate a typical renewal scenario where the API doesn't return subscription_url
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/user_123_order_456' => Http::response([
            'username' => 'user_123_order_456',
            'data_limit' => 21474836480,
            'expire_date' => '2026-01-01T00:00:00+00:00',
            // Note: No subscription_url in update response
        ], 200),
    ]);

    $service = new MarzneshinService(
        'https://example.com',
        'admin',
        'password',
        'https://node.example.com'
    );

    $userData = [
        'expire' => 1767225600,
        'data_limit' => 21474836480,
        'service_ids' => [1],
    ];

    $result = $service->updateUser('user_123_order_456', $userData);

    // Should return true even though response doesn't contain subscription_url
    expect($result)->toBeTrue();
});
