<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;

test('old max active configs limiter is not enforced', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['is_active' => true]);
    
    // Create a reseller with unlimited config_limit (using new system)
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'config_limit' => null, // Unlimited in new system
        'traffic_total_bytes' => 1000 * 1024 * 1024 * 1024, // 1000 GB
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);

    // Create 60 active configs (more than the old default limit of 50)
    // This should succeed since the old limiter is removed
    for ($i = 0; $i < 60; $i++) {
        ResellerConfig::factory()->create([
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'status' => 'active',
            'traffic_limit_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB each
        ]);
    }

    // Verify all 60 active configs were created
    expect($reseller->configs()->where('status', 'active')->count())->toBe(60);
});

test('config controller does not pass max_active_configs to view', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['is_active' => true]);
    
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
    ]);

    // Call the create method directly and inspect the view data
    $request = new \Illuminate\Http\Request();
    $request->setUserResolver(fn() => $user);
    
    $controller = new \Modules\Reseller\Http\Controllers\ConfigController();
    $response = $controller->create($request);
    
    // Verify that the old variables are not in the view data
    expect($response->getData())->not->toHaveKey('max_active_configs');
    expect($response->getData())->not->toHaveKey('active_configs_count');
    
    // Verify that required variables are still present
    expect($response->getData())->toHaveKey('reseller');
    expect($response->getData())->toHaveKey('panels');
});
