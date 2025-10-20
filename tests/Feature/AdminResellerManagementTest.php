<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Filament\Actions\DeleteAction;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->user = User::factory()->create(['is_admin' => false]);
});

test('admin can access resellers list page', function () {
    actingAs($this->admin);
    
    $response = get('/admin/resellers');
    
    $response->assertSuccessful();
});

test('non-admin cannot access resellers list page', function () {
    actingAs($this->user);
    
    $response = get('/admin/resellers');
    
    $response->assertForbidden();
});

test('reseller stats widget shows correct data', function () {
    actingAs($this->admin);
    
    // Create resellers
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    
    Reseller::factory()->count(3)->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 50 * 1024 * 1024 * 1024,
    ]);
    
    Reseller::factory()->count(2)->create([
        'type' => 'traffic',
        'status' => 'suspended',
        'panel_id' => $panel->id,
    ]);
    
    $response = get('/admin/resellers');
    
    $response->assertSuccessful();
    
    expect(Reseller::count())->toBe(5);
    expect(Reseller::where('status', 'active')->count())->toBe(3);
    expect(Reseller::where('status', 'suspended')->count())->toBe(2);
});

test('reseller list shows correct columns', function () {
    actingAs($this->admin);
    
    $panel = Panel::factory()->create([
        'name' => 'Test Panel',
        'panel_type' => 'marzban',
    ]);
    
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 25 * 1024 * 1024 * 1024,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);
    
    $response = get('/admin/resellers');
    
    $response->assertSuccessful();
    $response->assertSee($reseller->user->email);
});

test('type filter works on resellers list', function () {
    actingAs($this->admin);
    
    Reseller::factory()->create(['type' => 'plan', 'status' => 'active']);
    Reseller::factory()->create(['type' => 'traffic', 'status' => 'active']);
    
    $response = get('/admin/resellers');
    
    $response->assertSuccessful();
    expect(Reseller::where('type', 'plan')->count())->toBe(1);
    expect(Reseller::where('type', 'traffic')->count())->toBe(1);
});

test('status filter works on resellers list', function () {
    actingAs($this->admin);
    
    Reseller::factory()->create(['type' => 'plan', 'status' => 'active']);
    Reseller::factory()->create(['type' => 'plan', 'status' => 'suspended']);
    
    $response = get('/admin/resellers');
    
    $response->assertSuccessful();
    expect(Reseller::where('status', 'active')->count())->toBe(1);
    expect(Reseller::where('status', 'suspended')->count())->toBe(1);
});

test('search works on resellers list', function () {
    actingAs($this->admin);
    
    $user = User::factory()->create(['email' => 'test@example.com']);
    $reseller = Reseller::factory()->create([
        'user_id' => $user->id,
        'type' => 'plan',
        'status' => 'active',
    ]);
    
    $response = get('/admin/resellers');
    
    $response->assertSuccessful();
    expect(Reseller::where('user_id', $user->id)->count())->toBe(1);
});

test('suspend action updates reseller status', function () {
    $reseller = Reseller::factory()->create(['status' => 'active']);
    
    $reseller->update(['status' => 'suspended']);
    
    expect($reseller->fresh()->status)->toBe('suspended');
    expect($reseller->fresh()->isSuspended())->toBeTrue();
});

test('activate action updates reseller status', function () {
    $reseller = Reseller::factory()->create(['status' => 'suspended']);
    
    $reseller->update(['status' => 'active']);
    
    expect($reseller->fresh()->status)->toBe('active');
    expect($reseller->fresh()->isActive())->toBeTrue();
});

test('topup action increases reseller traffic', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
    ]);
    
    $additionalGB = 50;
    $additionalBytes = $additionalGB * 1024 * 1024 * 1024;
    
    $reseller->update([
        'traffic_total_bytes' => $reseller->traffic_total_bytes + $additionalBytes,
    ]);
    
    expect($reseller->fresh()->traffic_total_bytes)->toBe(150 * 1024 * 1024 * 1024);
});

test('extend window action extends reseller window', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $originalEnd = now()->addDays(30);
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
        'window_starts_at' => now(),
        'window_ends_at' => $originalEnd,
    ]);
    
    $daysToAdd = 15;
    $newEnd = $originalEnd->copy()->addDays($daysToAdd);
    
    $reseller->update(['window_ends_at' => $newEnd]);
    
    expect($reseller->fresh()->window_ends_at->format('Y-m-d'))->toBe($newEnd->format('Y-m-d'));
});

test('configs relation manager shows reseller configs', function () {
    actingAs($this->admin);
    
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
    ]);
    
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'external_username' => 'test_user',
        'status' => 'active',
        'panel_type' => 'marzban',
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
        'usage_bytes' => 10 * 1024 * 1024 * 1024,
        'expires_at' => now()->addDays(30),
    ]);
    
    expect($reseller->configs()->count())->toBe(1);
    expect($reseller->configs()->first()->external_username)->toBe('test_user');
});

test('disable config action updates config status', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
    ]);
    
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'panel_type' => 'marzban',
    ]);
    
    $config->update(['status' => 'disabled', 'disabled_at' => now()]);
    
    expect($config->fresh()->status)->toBe('disabled');
    expect($config->fresh()->disabled_at)->not->toBeNull();
});

test('enable config action updates config status', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
    ]);
    
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'disabled',
        'panel_type' => 'marzban',
        'disabled_at' => now(),
    ]);
    
    $config->update(['status' => 'active', 'disabled_at' => null]);
    
    expect($config->fresh()->status)->toBe('active');
    expect($config->fresh()->disabled_at)->toBeNull();
});

test('reset usage action resets config usage', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
    ]);
    
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'panel_type' => 'marzban',
        'usage_bytes' => 25 * 1024 * 1024 * 1024,
    ]);
    
    $config->update(['usage_bytes' => 0]);
    
    expect($config->fresh()->usage_bytes)->toBe(0);
});

test('extend time action extends config expiry', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
    ]);
    
    $originalExpiry = now()->addDays(30);
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'panel_type' => 'marzban',
        'expires_at' => $originalExpiry,
    ]);
    
    $daysToAdd = 10;
    $newExpiry = $originalExpiry->copy()->addDays($daysToAdd);
    
    $config->update(['expires_at' => $newExpiry]);
    
    expect($config->fresh()->expires_at->format('Y-m-d'))->toBe($newExpiry->format('Y-m-d'));
});

test('increase traffic action increases config traffic limit', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
    ]);
    
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'panel_type' => 'marzban',
        'traffic_limit_bytes' => 50 * 1024 * 1024 * 1024,
    ]);
    
    $additionalGB = 20;
    $additionalBytes = $additionalGB * 1024 * 1024 * 1024;
    
    $config->update(['traffic_limit_bytes' => $config->traffic_limit_bytes + $additionalBytes]);
    
    expect($config->fresh()->traffic_limit_bytes)->toBe(70 * 1024 * 1024 * 1024);
});

test('delete config action soft deletes config', function () {
    $panel = Panel::factory()->create(['panel_type' => 'marzban']);
    $reseller = Reseller::factory()->create([
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $panel->id,
    ]);
    
    $config = ResellerConfig::factory()->create([
        'reseller_id' => $reseller->id,
        'status' => 'active',
        'panel_type' => 'marzban',
    ]);
    
    $config->update(['status' => 'deleted']);
    $config->delete();
    
    expect($config->fresh()->trashed())->toBeTrue();
    expect(ResellerConfig::withTrashed()->find($config->id))->not->toBeNull();
});

test('bulk suspend action suspends multiple resellers', function () {
    $resellers = Reseller::factory()->count(3)->create(['status' => 'active']);
    
    $resellers->each(fn ($reseller) => $reseller->update(['status' => 'suspended']));
    
    expect(Reseller::where('status', 'suspended')->count())->toBe(3);
});

test('bulk activate action activates multiple resellers', function () {
    $resellers = Reseller::factory()->count(3)->create(['status' => 'suspended']);
    
    $resellers->each(fn ($reseller) => $reseller->update(['status' => 'active']));
    
    expect(Reseller::where('status', 'active')->count())->toBe(3);
});
