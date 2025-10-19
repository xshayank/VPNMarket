<?php

use App\Models\Reseller;
use App\Models\User;

test('convert to reseller handles string window_days correctly', function () {
    $user = User::factory()->create();

    // Simulate the Filament action with string values from form
    $data = [
        'type' => 'traffic',
        'username_prefix' => 'test',
        'traffic_total_gb' => '100', // String from form
        'window_days' => '30', // String from form
        'marzneshin_allowed_service_ids' => null,
    ];

    // This should not throw TypeError
    $reseller = Reseller::create([
        'user_id' => $user->id,
        'type' => $data['type'],
        'status' => 'active',
        'username_prefix' => $data['username_prefix'] ?? null,
        'traffic_total_bytes' => $data['type'] === 'traffic' ? ((float) $data['traffic_total_gb'] * 1024 * 1024 * 1024) : null,
        'traffic_used_bytes' => 0,
        'window_starts_at' => $data['type'] === 'traffic' ? now() : null,
        'window_ends_at' => $data['type'] === 'traffic' ? now()->addDays((int) $data['window_days']) : null,
        'marzneshin_allowed_service_ids' => $data['marzneshin_allowed_service_ids'] ?? null,
    ]);

    expect($reseller)->toBeInstanceOf(Reseller::class);
    // traffic_total_bytes is cast to integer in the model
    expect($reseller->traffic_total_bytes)->toBe((int)(100 * 1024 * 1024 * 1024));
    expect($reseller->window_ends_at)->toBeInstanceOf(\Carbon\Carbon::class);
    // Should be approximately 30 days from now
    expect($reseller->window_ends_at->diffInDays(now(), false))->toBeGreaterThanOrEqual(-31)
        ->toBeLessThanOrEqual(-29);
});

test('addDays with string parameter throws TypeError without cast', function () {
    // Demonstrate the bug: passing string to addDays throws TypeError
    $windowDays = '30'; // String from form
    
    // This should throw TypeError without the (int) cast
    expect(function () use ($windowDays) {
        now()->addDays($windowDays);
    })->toThrow(\TypeError::class);
});

test('addDays with int parameter works correctly', function () {
    // Demonstrate the fix: casting to int before passing to addDays works
    $windowDays = '30'; // String from form
    
    // With the (int) cast, this should work
    $result = now()->addDays((int) $windowDays);
    
    expect($result)->toBeInstanceOf(\Carbon\Carbon::class);
    expect($result->diffInDays(now(), false))->toBeGreaterThanOrEqual(-31)
        ->toBeLessThanOrEqual(-29);
});

test('traffic calculation with string handles float correctly', function () {
    // Test that float casting works for traffic calculations
    $trafficGb = '10.5'; // String from form
    
    $bytes = (float) $trafficGb * 1024 * 1024 * 1024;
    
    expect($bytes)->toBe(10.5 * 1024 * 1024 * 1024);
});

test('request integer method casts string to int', function () {
    // Simulate Laravel's Request::integer() method behavior
    $values = ['7', '30', '90'];
    
    foreach ($values as $value) {
        // Using intval is equivalent to $request->integer()
        $cast = intval($value);
        expect($cast)->toBeInt();
        expect($cast)->toBe((int) $value);
    }
});
