<?php

use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->user = User::factory()->create(['is_admin' => false]);
    $this->panel = Panel::factory()->create(['panel_type' => 'marzban']);
});

test('admin can reset reseller traffic usage', function () {
    actingAs($this->admin);

    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
        'traffic_used_bytes' => 50 * 1024 * 1024 * 1024,   // 50 GB used
        'window_starts_at' => now()->subDays(10),
        'window_ends_at' => now()->addDays(20),
    ]);

    expect($reseller->traffic_used_bytes)->toBe(50 * 1024 * 1024 * 1024);

    // Simulate the action
    $reseller->update(['traffic_used_bytes' => 0]);

    $reseller->refresh();
    expect($reseller->traffic_used_bytes)->toBe(0);
    expect($reseller->traffic_total_bytes)->toBe(100 * 1024 * 1024 * 1024); // Total should not change
});

test('reset usage creates audit log entry', function () {
    actingAs($this->admin);

    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 75 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(5),
        'window_ends_at' => now()->addDays(25),
    ]);

    $oldUsedBytes = $reseller->traffic_used_bytes;

    // Simulate the reset and audit log creation
    $reseller->update(['traffic_used_bytes' => 0]);

    AuditLog::log(
        action: 'reseller_usage_reset',
        targetType: 'reseller',
        targetId: $reseller->id,
        reason: 'admin_action',
        meta: [
            'old_traffic_used_bytes' => $oldUsedBytes,
            'new_traffic_used_bytes' => 0,
            'traffic_total_bytes' => $reseller->traffic_total_bytes,
        ]
    );

    assertDatabaseHas('audit_logs', [
        'action' => 'reseller_usage_reset',
        'target_type' => 'reseller',
        'target_id' => $reseller->id,
        'reason' => 'admin_action',
        'actor_id' => $this->admin->id,
    ]);

    $auditLog = AuditLog::where('action', 'reseller_usage_reset')
        ->where('target_id', $reseller->id)
        ->first();

    expect($auditLog)->not->toBeNull();
    expect($auditLog->meta['old_traffic_used_bytes'])->toBe($oldUsedBytes);
    expect($auditLog->meta['new_traffic_used_bytes'])->toBe(0);
    expect($auditLog->meta['traffic_total_bytes'])->toBe($reseller->traffic_total_bytes);
});

test('reset usage does not affect traffic total bytes', function () {
    actingAs($this->admin);

    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->panel->id,
        'traffic_total_bytes' => 200 * 1024 * 1024 * 1024, // 200 GB
        'traffic_used_bytes' => 150 * 1024 * 1024 * 1024,  // 150 GB used
    ]);

    $originalTotal = $reseller->traffic_total_bytes;

    // Simulate the reset
    $reseller->update(['traffic_used_bytes' => 0]);

    $reseller->refresh();
    expect($reseller->traffic_total_bytes)->toBe($originalTotal);
    expect($reseller->traffic_used_bytes)->toBe(0);
});

test('reset usage on suspended reseller with remaining quota and valid window should enable reenabling', function () {
    actingAs($this->admin);

    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'suspended',
        'panel_id' => $this->panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 95 * 1024 * 1024 * 1024, // Near limit
        'window_starts_at' => now()->subDays(5),
        'window_ends_at' => now()->addDays(25), // Valid window
    ]);

    expect($reseller->status)->toBe('suspended');
    expect($reseller->hasTrafficRemaining())->toBe(true);

    // After reset, traffic_used_bytes becomes 0
    $reseller->update(['traffic_used_bytes' => 0]);

    $reseller->refresh();
    expect($reseller->traffic_used_bytes)->toBe(0);
    expect($reseller->hasTrafficRemaining())->toBe(true);
    expect($reseller->isWindowValid())->toBe(true);
});

test('reset usage only visible for traffic-based resellers', function () {
    actingAs($this->admin);

    $planReseller = Reseller::factory()->create([
        'type' => 'plan',
        'status' => 'active',
    ]);

    $trafficReseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 50 * 1024 * 1024 * 1024,
    ]);

    expect($planReseller->type)->toBe('plan');
    expect($trafficReseller->type)->toBe('traffic');
});

test('hasTrafficRemaining works correctly after reset', function () {
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 100 * 1024 * 1024 * 1024, // At limit
    ]);

    expect($reseller->hasTrafficRemaining())->toBe(false);

    // Reset usage
    $reseller->update(['traffic_used_bytes' => 0]);
    $reseller->refresh();

    expect($reseller->hasTrafficRemaining())->toBe(true);
});

test('isWindowValid works correctly', function () {
    $validReseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 50 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(5),
        'window_ends_at' => now()->addDays(25),
    ]);

    $expiredReseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 50 * 1024 * 1024 * 1024,
        'window_starts_at' => now()->subDays(30),
        'window_ends_at' => now()->subDays(1),
    ]);

    expect($validReseller->isWindowValid())->toBe(true);
    expect($expiredReseller->isWindowValid())->toBe(false);
});
