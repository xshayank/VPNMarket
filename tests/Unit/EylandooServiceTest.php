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

test('createUser omits nodes when empty array provided', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['testuser'],
            'message' => '1 user(s) created successfully.',
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
        'nodes' => [], // Empty array
    ];

    $result = $service->createUser($userData);

    expect($result)->toBeArray()
        ->and($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://example.com/api/v1/users'
            && ! isset($body['nodes']); // nodes key should not be present
    });
});

test('createUser omits nodes when not provided', function () {
    Http::fake([
        '*/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['testuser'],
            'message' => '1 user(s) created successfully.',
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
        // nodes not provided at all
    ];

    $result = $service->createUser($userData);

    expect($result)->toBeArray()
        ->and($result['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://example.com/api/v1/users'
            && ! isset($body['nodes']); // nodes key should not be present
    });
});

test('extractSubscriptionUrl extracts from data.subscription_url', function () {
    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        'https://node.example.com'
    );

    $response = [
        'data' => [
            'subscription_url' => '/sub/testuser',
        ],
    ];

    $result = $service->extractSubscriptionUrl($response);

    expect($result)->toBe('/sub/testuser');
});

test('extractSubscriptionUrl extracts from data.users[0].config_url', function () {
    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        'https://node.example.com'
    );

    $response = [
        'data' => [
            'users' => [
                [
                    'config_url' => '/config/testuser',
                ],
            ],
        ],
    ];

    $result = $service->extractSubscriptionUrl($response);

    expect($result)->toBe('/config/testuser');
});

test('extractSubscriptionUrl returns null when not found', function () {
    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        'https://node.example.com'
    );

    $response = [
        'data' => [
            'username' => 'testuser',
        ],
    ];

    $result = $service->extractSubscriptionUrl($response);

    expect($result)->toBeNull();
});

test('getUserUsageBytes extracts from userInfo.total_traffic_bytes', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'userInfo' => [
                'username' => 'testuser',
                'total_traffic_bytes' => 1073741824, // 1 GB
                'upload_bytes' => 536870912, // 512 MB
                'download_bytes' => 536870912, // 512 MB
                'data_limit' => 32,
                'data_limit_unit' => 'GB',
                'is_active' => true,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(1073741824)
        ->and($result)->toBeInt();
});

test('getUserUsageBytes calculates from userInfo upload_bytes and download_bytes', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'userInfo' => [
                'username' => 'testuser',
                'upload_bytes' => 536870912, // 512 MB
                'download_bytes' => 1073741824, // 1 GB
                'data_limit' => 32,
                'data_limit_unit' => 'GB',
                'is_active' => true,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    // 512 MB + 1 GB = 1536 MB = 1610612736 bytes
    expect($result)->toBe(1610612736)
        ->and($result)->toBeInt();
});

test('getUserUsageBytes falls back to data.data_used', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'status' => 'success',
            'data' => [
                'username' => 'testuser',
                'data_used' => 2147483648, // 2 GB
                'status' => 'active',
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(2147483648)
        ->and($result)->toBeInt();
});

test('getUserUsageBytes returns 0 when no traffic data present', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'userInfo' => [
                'username' => 'testuser',
                'data_limit' => 32,
                'data_limit_unit' => 'GB',
                'is_active' => true,
                // No traffic fields at all
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(0)
        ->and($result)->toBeInt();
});

test('getUserUsageBytes returns null on HTTP error', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response(null, 500),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBeNull();
});

test('getUserUsageBytes returns null on user not found', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'error' => 'User not found',
        ], 404),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBeNull();
});

test('getUserUsageBytes clamps negative values to 0', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'userInfo' => [
                'username' => 'testuser',
                'total_traffic_bytes' => -1000, // Negative value (shouldn't happen but being defensive)
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(0)
        ->and($result)->toBeInt();
});

test('getUserUsageBytes prioritizes total_traffic_bytes over upload+download', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'userInfo' => [
                'username' => 'testuser',
                'total_traffic_bytes' => 5000000000, // 5 GB (the accurate total)
                'upload_bytes' => 1000000000, // 1 GB
                'download_bytes' => 2000000000, // 2 GB
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    // Should use total_traffic_bytes, not sum of upload+download
    expect($result)->toBe(5000000000)
        ->and($result)->toBeInt();
});

// New tests for enhanced usage parsing

test('getUserUsageBytes handles success:false as hard failure', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'success' => false,
            'message' => 'User not found or unauthorized',
        ], 200), // Note: 200 status but success:false
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBeNull();
});

test('getUserUsageBytes extracts from data.total_traffic_bytes', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'data' => [
                'username' => 'testuser',
                'total_traffic_bytes' => 3000000000, // 3 GB
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(3000000000);
});

test('getUserUsageBytes extracts from user.total_traffic_bytes', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'user' => [
                'username' => 'testuser',
                'total_traffic_bytes' => 4000000000, // 4 GB
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(4000000000);
});

test('getUserUsageBytes extracts from result.traffic_total_bytes', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'result' => [
                'username' => 'testuser',
                'traffic_total_bytes' => 2500000000,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(2500000000);
});

test('getUserUsageBytes extracts from stats.total_bytes', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'stats' => [
                'total_bytes' => 1500000000,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(1500000000);
});

test('getUserUsageBytes handles string numbers', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'userInfo' => [
                'username' => 'testuser',
                'total_traffic_bytes' => '2000000000', // String number
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(2000000000)
        ->and($result)->toBeInt();
});

test('getUserUsageBytes calculates from data.upload + data.download', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'data' => [
                'username' => 'testuser',
                'upload' => 800000000,
                'download' => 1200000000,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(2000000000);
});

test('getUserUsageBytes calculates from user.up + user.down', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'user' => [
                'username' => 'testuser',
                'up' => 500000000,
                'down' => 1500000000,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(2000000000);
});

test('getUserUsageBytes calculates from result.uploaded + result.downloaded', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'result' => [
                'username' => 'testuser',
                'uploaded' => 600000000,
                'downloaded' => 900000000,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(1500000000);
});

test('getUserUsageBytes calculates from stats.uplink + stats.downlink', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'stats' => [
                'uplink' => 300000000,
                'downlink' => 700000000,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(1000000000);
});

test('getUserUsageBytes extracts from data.used_traffic', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'data' => [
                'username' => 'testuser',
                'used_traffic' => 3500000000,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(3500000000);
});

test('getUserUsageBytes extracts from user.data_used_bytes', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'user' => [
                'username' => 'testuser',
                'data_used_bytes' => 2800000000,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(2800000000);
});

test('getUserUsageBytes extracts from result.data_usage_bytes', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'result' => [
                'username' => 'testuser',
                'data_usage_bytes' => 4200000000,
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(4200000000);
});

test('getUserUsageBytes prioritizes single fields over pairs', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'userInfo' => [
                'total_traffic_bytes' => 5000000000, // Should use this
                'upload_bytes' => 1000000000,
                'download_bytes' => 2000000000, // Not the sum of these
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(5000000000);
});

test('getUserUsageBytes handles string numbers in pairs', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'data' => [
                'upload_bytes' => '600000000', // String
                'download_bytes' => '1400000000', // String
            ],
        ], 200),
    ]);

    $service = new EylandooService(
        'https://example.com',
        'test-api-key-123',
        ''
    );

    $result = $service->getUserUsageBytes('testuser');

    expect($result)->toBe(2000000000);
});
