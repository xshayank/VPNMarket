<?php

use App\Services\EylandooService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

beforeEach(function () {
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
});

test('constructor initializes properties correctly', function () {
    $service = new EylandooService(
        'https://example.com/',
        'test-api-key-123',
        'https://node.example.com'
    );

    expect($service)->toBeInstanceOf(EylandooService::class);
});

test('createUser sends correct API request', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'status' => 'success',
            'message' => 'User(s) created successfully',
            'data' => [
                'users' => [
                    [
                        'username' => 'testuser',
                        'password' => 'generated_password',
                        'config_url' => 'https://example.com/sub/testuser',
                        'expiry_date' => '2024-12-31',
                    ],
                ],
            ],
        ], 201),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        'https://node.example.com'
    );

    $userData = [
        'username' => 'testuser',
        'expire' => 1735689600, // 2025-01-01
        'data_limit' => 10737418240, // 10GB in bytes
        'max_clients' => 2,
    ];

    $result = $service->createUser($userData);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('status')
        ->and($result['status'])->toBe('success')
        ->and($result['data']['users'][0]['username'])->toBe('testuser');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://example.com/api/v1/users'
            && $request->hasHeader('X-API-KEY', 'test-api-key-123')
            && $body['username'] === 'testuser'
            && $body['max_clients'] === 2
            && $body['activation_type'] === 'fixed_date'
            && $body['expiry_date_str'] === '2025-01-01'
            && $body['data_limit'] === 10.0  // Converted to GB
            && $body['data_limit_unit'] === 'GB';
    });
});

test('getUser retrieves user details', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'status' => 'success',
            'data' => [
                'username' => 'testuser',
                'status' => 'active',
                'max_clients' => 2,
                'data_limit' => 53687091200,
                'data_used' => 1073741824,
                'expiry_date' => '2024-12-31',
                'online' => false,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUser('testuser');

    expect($result)->toBeArray()
        ->and($result['data']['username'])->toBe('testuser')
        ->and($result['data']['status'])->toBe('active')
        ->and($result['data']['max_clients'])->toBe(2);
});

test('updateUser sends correct API request', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'status' => 'success',
            'message' => 'User updated successfully',
            'data' => [
                'username' => 'testuser',
                'changes' => [
                    'max_clients' => 5,
                    'data_limit' => 214748364800,
                ],
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $userData = [
        'max_clients' => 5,
        'data_limit' => 214748364800, // 200GB
        'expire' => 1767225600, // 2026-01-01
    ];

    $result = $service->updateUser('testuser', $userData);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://example.com/api/v1/users/testuser'
            && $request->method() === 'PUT'
            && $body['max_clients'] === 5
            && $body['data_limit'] === 200.0 // Converted to GB
            && $body['activation_type'] === 'fixed_date'
            && $body['expiry_date_str'] === '2026-01-01';
    });
});

test('enableUser toggles user to active state', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::sequence()
            ->push([
                'status' => 'success',
                'data' => ['username' => 'testuser', 'status' => 'disabled'],
            ], 200)
            ->push([
                'status' => 'success',
                'message' => 'User enabled successfully',
                'data' => ['username' => 'testuser', 'new_status' => 'active'],
            ], 200),
        '*/api/v1/users/testuser/toggle' => Http::response([
            'status' => 'success',
            'message' => 'User enabled successfully',
            'data' => ['username' => 'testuser', 'new_status' => 'active'],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->enableUser('testuser');

    expect($result)->toBeTrue();
});

test('disableUser toggles user to disabled state', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::sequence()
            ->push([
                'status' => 'success',
                'data' => ['username' => 'testuser', 'status' => 'active'],
            ], 200)
            ->push([
                'status' => 'success',
                'message' => 'User disabled successfully',
                'data' => ['username' => 'testuser', 'new_status' => 'disabled'],
            ], 200),
        '*/api/v1/users/testuser/toggle' => Http::response([
            'status' => 'success',
            'message' => 'User disabled successfully',
            'data' => ['username' => 'testuser', 'new_status' => 'disabled'],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->disableUser('testuser');

    expect($result)->toBeTrue();
});

test('deleteUser removes user successfully', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'status' => 'success',
            'message' => 'User deleted successfully',
            'data' => ['username' => 'testuser'],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->deleteUser('testuser');

    expect($result)->toBeTrue();
});

test('resetUserUsage resets traffic successfully', function () {
    Http::fake([
        '*/api/v1/users/testuser/reset_traffic' => Http::response([
            'status' => 'success',
            'message' => 'User traffic reset successfully',
            'data' => [
                'username' => 'testuser',
                'previous_usage' => 1073741824,
                'new_usage' => 0,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->resetUserUsage('testuser');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/api/v1/users/testuser/reset_traffic'
            && $request->method() === 'POST';
    });
});

test('buildAbsoluteSubscriptionUrl returns absolute URL from response', function () {
    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        'https://node.example.com'
    );

    $userApiResponse = [
        'data' => [
            'users' => [
                [
                    'username' => 'testuser',
                    'config_url' => 'https://example.com/sub/testuser',
                ],
            ],
        ],
    ];

    $result = $service->buildAbsoluteSubscriptionUrl($userApiResponse);

    expect($result)->toBe('https://example.com/sub/testuser');
});

test('buildAbsoluteSubscriptionUrl builds URL from relative path', function () {
    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        'https://node.example.com'
    );

    $userApiResponse = [
        'data' => [
            'users' => [
                [
                    'config_url' => '/sub/testuser',
                ],
            ],
        ],
    ];

    $result = $service->buildAbsoluteSubscriptionUrl($userApiResponse);

    expect($result)->toBe('https://node.example.com/sub/testuser');
});

test('listNodes retrieves all nodes', function () {
    Http::fake([
        '*/api/v1/nodes' => Http::response([
            'status' => 'success',
            'data' => [
                'nodes' => [
                    [
                        'id' => 1,
                        'name' => 'Main Server',
                        'ip_address' => '192.168.1.100',
                        'status' => 'online',
                    ],
                    [
                        'id' => 2,
                        'name' => 'Backup Server',
                        'ip_address' => '192.168.1.101',
                        'status' => 'online',
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->listNodes();

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(2)
        ->and($result[0])->toHaveKey('id')
        ->and($result[0])->toHaveKey('name')
        ->and($result[0]['name'])->toBe('Main Server')
        ->and($result[1]['name'])->toBe('Backup Server');
});

test('createUser includes nodes when provided', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'status' => 'success',
            'message' => 'User(s) created successfully',
            'data' => [
                'users' => [
                    [
                        'username' => 'testuser',
                        'password' => 'generated_password',
                        'config_url' => 'https://example.com/sub/testuser',
                        'expiry_date' => '2024-12-31',
                    ],
                ],
            ],
        ], 201),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        'https://node.example.com'
    );

    $userData = [
        'username' => 'testuser',
        'expire' => 1735689600,
        'data_limit' => 10737418240,
        'max_clients' => 2,
        'nodes' => [1, 3, 5],
    ];

    $result = $service->createUser($userData);

    expect($result)->toBeArray()
        ->and($result['status'])->toBe('success');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://example.com/api/v1/users'
            && isset($body['nodes'])
            && is_array($body['nodes'])
            && $body['nodes'] === [1, 3, 5];
    });
});
