<?php

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

test('fetchEylandooUsage uses getUserUsageBytes and returns usage', function () {
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

    $job = new SyncResellerUsageJob;

    // Use reflection to access protected method
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('fetchEylandooUsage');
    $method->setAccessible(true);

    // Pass null for configId to avoid database queries in unit test
    $result = $method->invoke($job, $credentials, 'testuser', null);

    // Should return 1GB (1073741824 bytes)
    expect($result)->toBe(1073741824);
});

test('fetchEylandooUsage handles various API response formats', function () {
    // Test that different response formats work through getUserUsageBytes parser
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

    $job = new SyncResellerUsageJob;
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('fetchEylandooUsage');
    $method->setAccessible(true);

    // Pass null for configId to avoid database queries in unit test
    $result = $method->invoke($job, $credentials, 'user1', null);

    // getUserUsageBytes should parse this to 5GB
    expect($result)->toBe(5368709120);
});
