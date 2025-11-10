<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Reseller\Jobs\ReenableResellerConfigsJob;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('notice')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();

    // Set default grace settings
    Setting::create(['key' => 'reseller.auto_disable_grace_percent', 'value' => '2.0']);
    Setting::create(['key' => 'reseller.auto_disable_grace_bytes', 'value' => (string) (50 * 1024 * 1024)]);
});

test('re-enables configs with boolean true marker', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_reseller_suspension' => true, // Boolean true
            'disabled_by_reseller_id' => $reseller->id,
        ],
    ]);

    $job = new ReenableResellerConfigsJob($reseller->id);
    $job->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->meta['disabled_by_reseller_suspension'] ?? null)->toBeNull()
        ->and($config->meta['disabled_by_reseller_id'] ?? null)->toBeNull();
});

test('re-enables configs with string "1" marker', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_reseller_suspension' => '1', // String '1'
            'disabled_by_reseller_id' => $reseller->id,
        ],
    ]);

    $job = new ReenableResellerConfigsJob($reseller->id);
    $job->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->meta['disabled_by_reseller_suspension'] ?? null)->toBeNull();
});

test('re-enables configs with integer 1 marker', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_reseller_suspension' => 1, // Integer 1
            'disabled_by_reseller_id' => $reseller->id,
        ],
    ]);

    $job = new ReenableResellerConfigsJob($reseller->id);
    $job->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->meta['disabled_by_reseller_suspension'] ?? null)->toBeNull();
});

test('re-enables configs with string "true" marker', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_reseller_suspension' => 'true', // String 'true'
            'disabled_by_reseller_id' => $reseller->id,
        ],
    ]);

    $job = new ReenableResellerConfigsJob($reseller->id);
    $job->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->meta['disabled_by_reseller_suspension'] ?? null)->toBeNull();
});

test('re-enables configs even when remote enable fails', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['error' => 'Panel error'], 500),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_reseller_suspension' => true,
            'disabled_by_reseller_id' => $reseller->id,
        ],
    ]);

    $job = new ReenableResellerConfigsJob($reseller->id);
    $job->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    // Config should be re-enabled in DB even though remote failed
    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->meta['disabled_by_reseller_suspension'] ?? null)->toBeNull()
        ->and($config->meta['disabled_by_reseller_id'] ?? null)->toBeNull();
});

test('re-enables configs with time window suspension marker', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'suspended_by_time_window' => true,
            'disabled_by_reseller_id' => $reseller->id,
        ],
    ]);

    $job = new ReenableResellerConfigsJob($reseller->id);
    $job->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->meta['suspended_by_time_window'] ?? null)->toBeNull()
        ->and($config->meta['disabled_by_reseller_id'] ?? null)->toBeNull();
});

test('re-enables configs using PHP fallback when marker not in JSON query', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    // Config with no explicit marker but has disabled_by_reseller_id
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_reseller_id' => $reseller->id,
            // No explicit disabled_by_reseller_suspension marker
        ],
    ]);

    $job = new ReenableResellerConfigsJob($reseller->id);
    $job->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    // Should be caught by PHP fallback filter
    $config->refresh();
    expect($config->status)->toBe('active');
});

test('job is idempotent - running twice does not cause issues', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_reseller_suspension' => true,
            'disabled_by_reseller_id' => $reseller->id,
        ],
    ]);

    // Run job twice
    $job1 = new ReenableResellerConfigsJob($reseller->id);
    $job1->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    $job2 = new ReenableResellerConfigsJob($reseller->id);
    $job2->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    // Config should still be active and clean
    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->meta['disabled_by_reseller_suspension'] ?? null)->toBeNull();
});

test('re-enables eylandoo configs correctly', function () {
    Http::fake([
        '*/api/v1/users/*' => Http::response([
            'data' => [
                'status' => 'disabled',
                'username' => 'test_user',
            ],
        ], 200),
        '*/api/v1/users/*/toggle' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->eylandoo()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_type' => 'eylandoo',
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_reseller_suspension' => true,
            'disabled_by_reseller_id' => $reseller->id,
        ],
    ]);

    $job = new ReenableResellerConfigsJob($reseller->id);
    $job->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    // Verify the config was re-enabled locally
    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->meta['disabled_by_reseller_suspension'] ?? null)->toBeNull();
    
    // Verify that the toggle endpoint was called (the proven-good path)
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/v1/users/') 
            && str_contains($request->url(), '/toggle')
            && $request->method() === 'POST';
    });
});

test('clears all suspension meta fields on re-enable', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'disabled_at' => now(),
        'meta' => [
            'disabled_by_reseller_suspension' => true,
            'disabled_by_reseller_suspension_reason' => 'reseller_quota_exhausted',
            'disabled_by_reseller_suspension_at' => now()->toIso8601String(),
            'disabled_by_reseller_id' => $reseller->id,
            'disabled_at' => now()->toIso8601String(),
            'suspended_by_time_window' => true,
            'other_field' => 'should_remain', // Non-suspension field
        ],
    ]);

    $job = new ReenableResellerConfigsJob($reseller->id);
    $job->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->disabled_at)->toBeNull()
        ->and($config->meta['disabled_by_reseller_suspension'] ?? null)->toBeNull()
        ->and($config->meta['disabled_by_reseller_suspension_reason'] ?? null)->toBeNull()
        ->and($config->meta['disabled_by_reseller_suspension_at'] ?? null)->toBeNull()
        ->and($config->meta['disabled_by_reseller_id'] ?? null)->toBeNull()
        ->and($config->meta['disabled_at'] ?? null)->toBeNull()
        ->and($config->meta['suspended_by_time_window'] ?? null)->toBeNull()
        ->and($config->meta['other_field'])->toBe('should_remain'); // Verify non-suspension fields preserved
});

test('re-enables marzneshin configs using original path', function () {
    Http::fake([
        '*/api/admins/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/api/users/*/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $panel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'traffic_total_bytes' => 10 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 1 * 1024 * 1024 * 1024,
        'window_ends_at' => now()->addDays(30),
    ]);

    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'panel_type' => 'marzneshin',
        'panel_user_id' => 'test_user',
        'status' => 'disabled',
        'meta' => [
            'disabled_by_reseller_suspension' => true,
            'disabled_by_reseller_id' => $reseller->id,
        ],
    ]);

    $job = new ReenableResellerConfigsJob($reseller->id);
    $job->handle(app(\Modules\Reseller\Services\ResellerProvisioner::class));

    // Verify the config was re-enabled locally
    $config->refresh();
    expect($config->status)->toBe('active')
        ->and($config->meta['disabled_by_reseller_suspension'] ?? null)->toBeNull();
    
    // Verify that Marzneshin uses the /api/users/{username}/enable endpoint (not toggle)
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/users/')
            && str_contains($request->url(), '/enable')
            && $request->method() === 'POST';
    });
});
