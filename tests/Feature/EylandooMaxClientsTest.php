<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Create reseller user
    $this->resellerUser = User::factory()->create();

    // Create Eylandoo panel
    $this->eylandooPanel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'extra' => ['node_hostname' => 'https://node.eylandoo.example.com'],
    ]);

    // Create traffic-based reseller
    $this->reseller = Reseller::factory()->create([
        'user_id' => $this->resellerUser->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->eylandooPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);
});

test('config creation stores max_clients in meta', function () {
    Http::fake([
        'eylandoo.example.com/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['resell_' . $this->reseller->id . '_cfg_*'],
            'data' => [
                'subscription_url' => '/sub/test',
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->resellerUser)->post(route('reseller.configs.store'), [
        'panel_id' => $this->eylandooPanel->id,
        'traffic_limit_gb' => 10,
        'expires_days' => 30,
        'max_clients' => 3,
    ]);

    $config = ResellerConfig::latest()->first();
    
    expect($config)->not->toBeNull()
        ->and($config->meta)->toHaveKey('max_clients')
        ->and($config->meta['max_clients'])->toBe(3);
});

test('config creation defaults max_clients to 1 when not provided', function () {
    Http::fake([
        'eylandoo.example.com/api/v1/users' => Http::response([
            'success' => true,
            'created_users' => ['resell_' . $this->reseller->id . '_cfg_*'],
            'data' => [
                'subscription_url' => '/sub/test',
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->resellerUser)->post(route('reseller.configs.store'), [
        'panel_id' => $this->eylandooPanel->id,
        'traffic_limit_gb' => 10,
        'expires_days' => 30,
        // max_clients not provided
    ]);

    $config = ResellerConfig::latest()->first();
    
    expect($config)->not->toBeNull()
        ->and($config->meta)->toHaveKey('max_clients')
        ->and($config->meta['max_clients'])->toBe(1);
});

test('config update changes max_clients in meta', function () {
    // Create a config first
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $this->reseller->id,
        'panel_id' => $this->eylandooPanel->id,
        'panel_type' => 'eylandoo',
        'panel_user_id' => 'test_user',
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'usage_bytes' => 1 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(30),
        'status' => 'active',
        'meta' => [
            'max_clients' => 2,
        ],
    ]);

    Http::fake([
        'eylandoo.example.com/api/v1/users/*' => Http::response([
            'success' => true,
        ], 200),
    ]);

    $response = $this->actingAs($this->resellerUser)->put(route('reseller.configs.update', $config), [
        'traffic_limit_gb' => 15,
        'expires_at' => now()->addDays(35)->format('Y-m-d'),
        'max_clients' => 5,
    ]);

    $config->refresh();
    
    expect($config->meta)->toHaveKey('max_clients')
        ->and($config->meta['max_clients'])->toBe(5);
});

test('provisioner passes max_clients to eylandoo service', function () {
    Http::fake([
        'eylandoo.example.com/api/v1/users' => function ($request) {
            $body = json_decode($request->body(), true);
            
            // Verify max_clients is included in the request
            expect($body)->toHaveKey('max_clients')
                ->and($body['max_clients'])->toBe(4);
            
            return Http::response([
                'success' => true,
                'created_users' => ['test_user'],
                'data' => [
                    'subscription_url' => '/sub/test',
                ],
            ], 200);
        },
    ]);

    $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();
    $plan = new \App\Models\Plan();
    $plan->volume_gb = 10;
    $plan->duration_days = 30;

    $result = $provisioner->provisionUser($this->eylandooPanel, $plan, 'test_user', [
        'traffic_limit_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(30),
        'max_clients' => 4,
    ]);

    expect($result)->not->toBeNull()
        ->and($result['panel_type'])->toBe('eylandoo');
});

test('provisioner updateUser passes max_clients for eylandoo', function () {
    Http::fake([
        'eylandoo.example.com/api/v1/users/*' => function ($request) {
            $body = json_decode($request->body(), true);
            
            // Verify max_clients is included in the request
            expect($body)->toHaveKey('max_clients')
                ->and($body['max_clients'])->toBe(6);
            
            return Http::response([
                'success' => true,
            ], 200);
        },
    ]);

    $provisioner = new \Modules\Reseller\Services\ResellerProvisioner();

    $result = $provisioner->updateUser(
        'eylandoo',
        $this->eylandooPanel->getCredentials(),
        'test_user',
        [
            'data_limit' => 15 * 1024 * 1024 * 1024,
            'expire' => now()->addDays(35)->timestamp,
            'max_clients' => 6,
        ]
    );

    expect($result['success'])->toBeTrue();
});

test('max_clients validation rejects values less than 1', function () {
    $response = $this->actingAs($this->resellerUser)->post(route('reseller.configs.store'), [
        'panel_id' => $this->eylandooPanel->id,
        'traffic_limit_gb' => 10,
        'expires_days' => 30,
        'max_clients' => 0, // Invalid
    ]);

    $response->assertSessionHasErrors('max_clients');
});

test('max_clients validation rejects non-integer values', function () {
    $response = $this->actingAs($this->resellerUser)->post(route('reseller.configs.store'), [
        'panel_id' => $this->eylandooPanel->id,
        'traffic_limit_gb' => 10,
        'expires_days' => 30,
        'max_clients' => 'abc', // Invalid
    ]);

    $response->assertSessionHasErrors('max_clients');
});
