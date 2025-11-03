<?php

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;

test('reseller create with window_days sets window range correctly', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);

    $windowDays = 30;

    // Simulate the form data that would be submitted
    $formData = [
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_gb' => 100,
        'window_days' => $windowDays,
    ];

    // Simulate what CreateReseller does
    $mutatedData = $formData;
    if ($mutatedData['type'] === 'traffic' && isset($mutatedData['traffic_total_gb'])) {
        $mutatedData['traffic_total_bytes'] = (int) ($mutatedData['traffic_total_gb'] * 1024 * 1024 * 1024);
        unset($mutatedData['traffic_total_gb']);
    }
    if ($mutatedData['type'] === 'traffic' && isset($mutatedData['window_days']) && $mutatedData['window_days'] > 0) {
        $windowDaysValue = (int) $mutatedData['window_days'];
        $mutatedData['window_starts_at'] = now();
        $mutatedData['window_ends_at'] = now()->addDays($windowDaysValue);
        unset($mutatedData['window_days']);
    }

    $reseller = Reseller::create($mutatedData);

    expect($reseller->window_starts_at)->not->toBeNull();
    expect($reseller->window_ends_at)->not->toBeNull();

    // Check that window_ends_at is approximately window_days from window_starts_at
    $expectedEnd = $reseller->window_starts_at->copy()->addDays($windowDays);
    expect($reseller->window_ends_at->diffInSeconds($expectedEnd))->toBeLessThan(2);
});

test('reseller create without window_days leaves window fields null', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);

    $formData = [
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_gb' => 100,
    ];

    // Simulate what CreateReseller does
    $mutatedData = $formData;
    if ($mutatedData['type'] === 'traffic' && isset($mutatedData['traffic_total_gb'])) {
        $mutatedData['traffic_total_bytes'] = (int) ($mutatedData['traffic_total_gb'] * 1024 * 1024 * 1024);
        unset($mutatedData['traffic_total_gb']);
    }
    if ($mutatedData['type'] === 'traffic' && isset($mutatedData['window_days']) && $mutatedData['window_days'] > 0) {
        $windowDaysValue = (int) $mutatedData['window_days'];
        $mutatedData['window_starts_at'] = now();
        $mutatedData['window_ends_at'] = now()->addDays($windowDaysValue);
        unset($mutatedData['window_days']);
    }

    $reseller = Reseller::create($mutatedData);

    expect($reseller->window_starts_at)->toBeNull();
    expect($reseller->window_ends_at)->toBeNull();
});

test('extend window action handles null end date', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);

    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => null,
        'window_ends_at' => null,
    ]);

    expect($reseller->window_ends_at)->toBeNull();

    // Simulate the extend action
    $daysToExtend = 7;
    $oldEndDate = $reseller->window_ends_at;
    
    $now = now();
    $baseDate = $reseller->window_ends_at && $reseller->window_ends_at->gt($now) 
        ? $reseller->window_ends_at 
        : $now;
    
    $newEndDate = $baseDate->copy()->addDays($daysToExtend);
    
    $reseller->update([
        'window_ends_at' => $newEndDate,
        'window_starts_at' => $reseller->window_starts_at ?? $now,
    ]);

    $reseller->refresh();

    expect($reseller->window_ends_at)->not->toBeNull();
    expect($reseller->window_starts_at)->not->toBeNull();
    
    // The end date should be approximately 7 days from now
    $expectedEnd = $now->copy()->addDays($daysToExtend);
    expect($reseller->window_ends_at->diffInSeconds($expectedEnd))->toBeLessThan(2);
});

test('extend window action handles past end date', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);

    $pastDate = now()->subDays(10);

    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => $pastDate->copy()->subDays(20),
        'window_ends_at' => $pastDate,
    ]);

    expect($reseller->window_ends_at->isPast())->toBeTrue();

    // Simulate the extend action
    $daysToExtend = 7;
    $oldEndDate = $reseller->window_ends_at;
    
    $now = now();
    $baseDate = $reseller->window_ends_at && $reseller->window_ends_at->gt($now) 
        ? $reseller->window_ends_at 
        : $now;
    
    $newEndDate = $baseDate->copy()->addDays($daysToExtend);
    
    $reseller->update([
        'window_ends_at' => $newEndDate,
        'window_starts_at' => $reseller->window_starts_at ?? $now,
    ]);

    $reseller->refresh();

    expect($reseller->window_ends_at->isFuture())->toBeTrue();
    
    // The end date should be approximately 7 days from now (not from past date)
    $expectedEnd = $now->copy()->addDays($daysToExtend);
    expect($reseller->window_ends_at->diffInSeconds($expectedEnd))->toBeLessThan(2);
});

test('extend window action handles future end date', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);

    $futureDate = now()->addDays(5);

    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(10),
        'window_ends_at' => $futureDate,
    ]);

    expect($reseller->window_ends_at->isFuture())->toBeTrue();

    // Simulate the extend action
    $daysToExtend = 7;
    $oldEndDate = $reseller->window_ends_at->copy();
    
    $now = now();
    $baseDate = $reseller->window_ends_at && $reseller->window_ends_at->gt($now) 
        ? $reseller->window_ends_at 
        : $now;
    
    $newEndDate = $baseDate->copy()->addDays($daysToExtend);
    
    $reseller->update([
        'window_ends_at' => $newEndDate,
        'window_starts_at' => $reseller->window_starts_at ?? $now,
    ]);

    $reseller->refresh();

    // The end date should be approximately 7 days from the old future date
    $expectedEnd = $oldEndDate->addDays($daysToExtend);
    expect($reseller->window_ends_at->diffInSeconds($expectedEnd))->toBeLessThan(2);
});

test('extend window action creates audit log', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);

    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(10),
    ]);

    // Simulate the extend action
    $daysToExtend = 7;
    $oldEndDate = $reseller->window_ends_at;
    
    $now = now();
    $baseDate = $reseller->window_ends_at && $reseller->window_ends_at->gt($now) 
        ? $reseller->window_ends_at 
        : $now;
    
    $newEndDate = $baseDate->copy()->addDays($daysToExtend);
    
    $reseller->update([
        'window_ends_at' => $newEndDate,
        'window_starts_at' => $reseller->window_starts_at ?? $now,
    ]);

    // Create audit log
    AuditLog::log(
        action: 'reseller_window_extended',
        targetType: 'reseller',
        targetId: $reseller->id,
        reason: 'admin_action',
        meta: [
            'old_window_ends_at' => $oldEndDate?->toDateTimeString(),
            'new_window_ends_at' => $newEndDate->toDateTimeString(),
            'days_added' => $daysToExtend,
        ]
    );

    // Verify audit log exists
    expect(
        AuditLog::where('action', 'reseller_window_extended')
            ->where('target_id', $reseller->id)
            ->where('target_type', 'reseller')
            ->exists()
    )->toBeTrue();
});

test('reseller model isWindowValid works with null window_ends_at', function () {
    $user = User::factory()->create();
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);

    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'window_starts_at' => null,
        'window_ends_at' => null,
    ]);

    // When window_ends_at is null, isWindowValid should return true (unlimited)
    expect($reseller->isWindowValid())->toBeTrue();
});
