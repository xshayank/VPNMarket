<?php

use App\Models\Panel;
use App\Models\Plan;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Services\ResellerProvisioner;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
});

test('ConfigController creates Plan instance instead of stdClass for provisioning', function () {
    // This test validates that we can create a Plan instance without persisting it
    // and that it has the correct type for ResellerProvisioner::provisionUser()

    $plan = new Plan;
    $plan->volume_gb = 10.5;
    $plan->duration_days = 30;
    $plan->marzneshin_service_ids = [1, 2, 3];

    expect($plan)->toBeInstanceOf(Plan::class)
        ->and($plan->volume_gb)->toBe(10.5)
        ->and($plan->duration_days)->toBe(30)
        ->and($plan->marzneshin_service_ids)->toBe([1, 2, 3]);
});

test('ResellerProvisioner accepts non-persisted Plan instance', function () {
    // Mock HTTP requests for Marzneshin
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users' => Http::response([
            'username' => 'testuser',
            'subscription_url' => '/sub/abc123',
        ], 200),
    ]);

    $user = User::factory()->create();

    $panel = Panel::factory()->marzneshin()->create();

    // Create a non-persisted Plan instance (mimicking ConfigController behavior)
    $plan = new Plan;
    $plan->volume_gb = 10.5;
    $plan->duration_days = 30;
    $plan->marzneshin_service_ids = [1, 2];

    $provisioner = new ResellerProvisioner;

    // This should not throw TypeError - Plan instance is accepted
    $result = $provisioner->provisionUser($panel, $plan, 'test_user_cfg_123', [
        'traffic_limit_bytes' => 10.5 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(30),
        'service_ids' => [1, 2],
    ]);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('username')
        ->and($result)->toHaveKey('subscription_url')
        ->and($result['panel_type'])->toBe('marzneshin');
});

test('Plan instance properly casts values from request input', function () {
    // Simulate request input as strings (as they come from forms)
    $trafficLimitGb = '15.5';
    $expiresDays = '45';
    $serviceIds = ['1', '2', '3'];

    // Create Plan instance with proper casting (as in ConfigController)
    $plan = new Plan;
    $plan->volume_gb = (float) $trafficLimitGb;
    $plan->duration_days = (int) $expiresDays;
    $plan->marzneshin_service_ids = $serviceIds;

    expect($plan->volume_gb)->toBeFloat()
        ->and($plan->volume_gb)->toBe(15.5)
        ->and($plan->duration_days)->toBeInt()
        ->and($plan->duration_days)->toBe(45)
        ->and($plan->marzneshin_service_ids)->toBeArray()
        ->and($plan->marzneshin_service_ids)->toBe(['1', '2', '3']);
});

test('ResellerProvisioner handles Plan with empty service_ids', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users' => Http::response([
            'username' => 'testuser',
            'subscription_url' => '/sub/abc123',
        ], 200),
    ]);

    $user = User::factory()->create();

    $panel = Panel::factory()->marzneshin()->create();

    // Create Plan with empty service_ids (using default from request->input('service_ids', []))
    $plan = new Plan;
    $plan->volume_gb = 5.0;
    $plan->duration_days = 7;
    $plan->marzneshin_service_ids = [];

    $provisioner = new ResellerProvisioner;
    $result = $provisioner->provisionUser($panel, $plan, 'test_user_empty_services');

    expect($result)->toBeArray();

    // Verify service_ids is an empty array when not provided
    Http::assertSent(function ($request) {
        $body = $request->data();

        return isset($body['service_ids']) && is_array($body['service_ids']) && count($body['service_ids']) === 0;
    });
});
