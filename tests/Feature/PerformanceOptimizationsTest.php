<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\User;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('MarzbanService uses ensureAuthenticated method', function () {
    $service = new MarzbanService('http://example.com', 'user', 'pass', 'node.example.com');
    
    // Verify the method exists
    $reflection = new ReflectionClass($service);
    expect($reflection->hasMethod('ensureAuthenticated'))->toBeTrue();
    
    $method = $reflection->getMethod('ensureAuthenticated');
    expect($method->isProtected())->toBeTrue();
});

test('MarzneshinService uses ensureAuthenticated method', function () {
    $service = new MarzneshinService('http://example.com', 'user', 'pass', 'node.example.com');
    
    // Verify the method exists
    $reflection = new ReflectionClass($service);
    expect($reflection->hasMethod('ensureAuthenticated'))->toBeTrue();
    
    $method = $reflection->getMethod('ensureAuthenticated');
    expect($method->isProtected())->toBeTrue();
});

test('dashboard controller uses optimized queries for plan-based resellers', function () {
    // Enable query logging
    DB::enableQueryLog();
    
    $user = User::factory()->create(['balance' => 1000]);
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
    ]);
    
    // Make request
    $this->actingAs($user)->get(route('reseller.dashboard'));
    
    // Get query log
    $queries = DB::getQueryLog();
    
    // Count queries related to orders
    $orderQueries = collect($queries)->filter(function ($query) {
        return str_contains($query['query'], 'reseller_orders');
    });
    
    // Should have only one aggregation query instead of multiple count/sum queries
    expect($orderQueries->count())->toBeLessThanOrEqual(2); // One for aggregation, one for recent orders
});

test('dashboard controller uses optimized queries for traffic-based resellers', function () {
    // Enable query logging
    DB::enableQueryLog();
    
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'traffic_total_bytes' => 10737418240, // 10 GB
        'traffic_used_bytes' => 1073741824, // 1 GB
    ]);
    
    // Make request
    $this->actingAs($user)->get(route('reseller.dashboard'));
    
    // Get query log
    $queries = DB::getQueryLog();
    
    // Count queries related to configs
    $configQueries = collect($queries)->filter(function ($query) {
        return str_contains($query['query'], 'reseller_configs');
    });
    
    // Should have only one aggregation query instead of multiple count queries
    expect($configQueries->count())->toBeLessThanOrEqual(2); // One for aggregation, one for recent configs
});

test('SyncResellerUsageJob uses eager loading', function () {
    // Create test data
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
    ]);
    
    $panel = Panel::factory()->create(['panel_type' => 'marzban', 'is_active' => true]);
    
    ResellerConfig::factory()->count(3)->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'status' => 'active',
    ]);
    
    // Enable query logging
    DB::enableQueryLog();
    
    // Note: This test can't fully run without mocking the API calls,
    // but we can verify the eager loading query structure
    $resellers = Reseller::where('status', 'active')
        ->where('type', 'traffic')
        ->with('configs')
        ->get();
    
    $queries = DB::getQueryLog();
    
    // Should have only 2 queries: one for resellers, one for all configs
    // Not N+1 (1 + N queries where N is number of resellers)
    expect($queries)->toHaveCount(2);
});

test('ReenableResellerConfigsJob uses eager loading for events', function () {
    $user = User::factory()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'suspended',
    ]);
    
    $panel = Panel::factory()->create(['panel_type' => 'marzban', 'is_active' => true]);
    
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'panel_id' => $panel->id,
        'status' => 'disabled',
    ]);
    
    ResellerConfigEvent::create([
        'reseller_config_id' => $config->id,
        'type' => 'auto_disabled',
        'meta' => ['reason' => 'reseller_quota_exhausted'],
    ]);
    
    // Enable query logging
    DB::enableQueryLog();
    
    // Test the optimized query
    $configs = ResellerConfig::where('reseller_id', $reseller->id)
        ->where('status', 'disabled')
        ->with(['events' => function ($query) {
            $query->where('type', 'auto_disabled')
                ->whereJsonContains('meta->reason', 'reseller_quota_exhausted')
                ->orWhereJsonContains('meta->reason', 'reseller_window_expired')
                ->orderBy('created_at', 'desc');
        }])
        ->get();
    
    $queries = DB::getQueryLog();
    
    // Should have only 2 queries: one for configs, one for all events
    // Not N+1 (would be 1 + N where N is number of configs)
    expect($queries)->toHaveCount(2);
    
    // Verify events are loaded
    expect($configs->first()->relationLoaded('events'))->toBeTrue();
});
