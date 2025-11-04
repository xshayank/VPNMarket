<?php

use App\Services\EylandooService;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

beforeEach(function () {
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();
});

test('extractSubscriptionUrlFromSub handles subResponse.sub_url shape', function () {
    $service = new EylandooService('https://example.com', 'test-api-key');

    $response = [
        'subResponse' => [
            'sub_url' => 'https://example.com/sub/resell_8_cfg_53',
            'success' => true,
        ],
    ];

    $url = $service->extractSubscriptionUrlFromSub($response);

    expect($url)->toBe('https://example.com/sub/resell_8_cfg_53');
});

test('extractSubscriptionUrlFromSub handles data.subscription_url shape', function () {
    $service = new EylandooService('https://example.com', 'test-api-key');

    $response = [
        'data' => [
            'subscription_url' => 'https://example.com/sub/test_user',
        ],
    ];

    $url = $service->extractSubscriptionUrlFromSub($response);

    expect($url)->toBe('https://example.com/sub/test_user');
});

test('extractSubscriptionUrlFromSub handles top-level subscription_url shape', function () {
    $service = new EylandooService('https://example.com', 'test-api-key');

    $response = [
        'subscription_url' => 'https://example.com/sub/test_user',
    ];

    $url = $service->extractSubscriptionUrlFromSub($response);

    expect($url)->toBe('https://example.com/sub/test_user');
});

test('extractSubscriptionUrlFromSub handles url field shape', function () {
    $service = new EylandooService('https://example.com', 'test-api-key');

    $response = [
        'url' => 'https://example.com/sub/test_user',
    ];

    $url = $service->extractSubscriptionUrlFromSub($response);

    expect($url)->toBe('https://example.com/sub/test_user');
});

test('extractSubscriptionUrlFromSub converts relative URL to absolute using baseUrl', function () {
    $service = new EylandooService('https://panel.example.com', 'test-api-key');

    $response = [
        'subResponse' => [
            'sub_url' => '/sub/resell_8_cfg_53',
            'success' => true,
        ],
    ];

    $url = $service->extractSubscriptionUrlFromSub($response);

    expect($url)->toBe('https://panel.example.com/sub/resell_8_cfg_53');
});

test('extractSubscriptionUrlFromSub converts relative URL to absolute using nodeHostname', function () {
    $service = new EylandooService('https://panel.example.com', 'test-api-key', 'https://node.example.com');

    $response = [
        'data' => [
            'subscription_url' => '/sub/test_user',
        ],
    ];

    $url = $service->extractSubscriptionUrlFromSub($response);

    expect($url)->toBe('https://node.example.com/sub/test_user');
});

test('extractSubscriptionUrlFromSub returns null for empty response', function () {
    $service = new EylandooService('https://example.com', 'test-api-key');

    $url = $service->extractSubscriptionUrlFromSub(null);

    expect($url)->toBeNull();
});

test('extractSubscriptionUrlFromSub returns null when no URL found', function () {
    $service = new EylandooService('https://example.com', 'test-api-key');

    $response = [
        'data' => [
            'username' => 'test_user',
        ],
    ];

    $url = $service->extractSubscriptionUrlFromSub($response);

    expect($url)->toBeNull();
});

test('extractSubscriptionUrlFromSub prioritizes subResponse.sub_url over other shapes', function () {
    $service = new EylandooService('https://example.com', 'test-api-key');

    $response = [
        'subResponse' => [
            'sub_url' => 'https://example.com/sub/from_subResponse',
        ],
        'subscription_url' => 'https://example.com/sub/from_root',
        'data' => [
            'subscription_url' => 'https://example.com/sub/from_data',
        ],
    ];

    $url = $service->extractSubscriptionUrlFromSub($response);

    expect($url)->toBe('https://example.com/sub/from_subResponse');
});

test('extractSubscriptionUrlFromSub handles leading slashes correctly', function () {
    $service = new EylandooService('https://panel.example.com/', 'test-api-key');

    $response = [
        'subResponse' => [
            'sub_url' => '/sub/resell_8_cfg_53',
        ],
    ];

    $url = $service->extractSubscriptionUrlFromSub($response);

    // Should not have double slashes
    expect($url)->toBe('https://panel.example.com/sub/resell_8_cfg_53');
});
