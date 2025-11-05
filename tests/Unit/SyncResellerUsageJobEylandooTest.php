<?php

use App\Services\EylandooService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\SyncResellerUsageJob;

uses(Tests\TestCase::class);

beforeEach(function () {
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();
    Log::shouldReceive('notice')->andReturnNull();
});

test('fetchEylandooUsage reads used_traffic from getUser response', function () {
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'status' => 'success',
            'data' => [
                'username' => 'testuser',
                'status' => 'active',
                'data_used' => 1073741824, // 1GB
            ],
        ], 200),
    ]);

    $credentials = [
        'url' => 'https://example.com',
        'api_token' => 'test-api-key-123',
        'extra' => [
            'node_hostname' => '',
        ],
    ];

    $job = new SyncResellerUsageJob();

    // Use reflection to access protected method
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('fetchEylandooUsage');
    $method->setAccessible(true);

    $result = $method->invoke($job, $credentials, 'testuser', 1);

    // Should return 1GB (1073741824 bytes)
    expect($result)->toBe(1073741824);
});

test('fetchEylandooUsage handles missing used_traffic with fallback', function () {
    // First call to getUser returns response without used_traffic
    // Second call to getUserUsageBytes should parse it
    Http::fake([
        '*/api/v1/users/testuser' => Http::response([
            'status' => 'success',
            'data' => [
                'username' => 'testuser',
                'status' => 'active',
                'data_used' => 2147483648, // 2GB
            ],
        ], 200),
    ]);

    $credentials = [
        'url' => 'https://example.com',
        'api_token' => 'test-api-key-123',
        'extra' => [
            'node_hostname' => '',
        ],
    ];

    $job = new SyncResellerUsageJob();
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('fetchEylandooUsage');
    $method->setAccessible(true);

    $result = $method->invoke($job, $credentials, 'testuser', 1);

    // Should return 2GB - getUser now injects used_traffic automatically
    expect($result)->toBe(2147483648);
});

test('fetchEylandooUsage returns null for empty username', function () {
    $credentials = [
        'url' => 'https://example.com',
        'api_token' => 'test-api-key-123',
        'extra' => [],
    ];

    $job = new SyncResellerUsageJob();
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('fetchEylandooUsage');
    $method->setAccessible(true);

    $result = $method->invoke($job, $credentials, '', 1);

    expect($result)->toBeNull();
});

test('fetchEylandooUsage returns null when user not found', function () {
    Http::fake([
        '*/api/v1/users/nonexistent' => Http::response(null, 404),
    ]);

    $credentials = [
        'url' => 'https://example.com',
        'api_token' => 'test-api-key-123',
        'extra' => [],
    ];

    $job = new SyncResellerUsageJob();
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('fetchEylandooUsage');
    $method->setAccessible(true);

    $result = $method->invoke($job, $credentials, 'nonexistent', 1);

    expect($result)->toBeNull();
});

test('fetchEylandooUsage handles various usage field formats via used_traffic', function () {
    // Test that different response formats work because getUser normalizes them
    Http::fake([
        '*/api/v1/users/user1' => Http::response([
            'userInfo' => [
                'total_traffic_bytes' => 5368709120, // 5GB
            ],
        ], 200),
    ]);

    $credentials = [
        'url' => 'https://example.com',
        'api_token' => 'test-api-key-123',
        'extra' => [],
    ];

    $job = new SyncResellerUsageJob();
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('fetchEylandooUsage');
    $method->setAccessible(true);

    $result = $method->invoke($job, $credentials, 'user1', 1);

    // getUser should normalize this to used_traffic = 5GB
    expect($result)->toBe(5368709120);
});
