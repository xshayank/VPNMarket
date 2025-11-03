<?php

use App\Filament\Pages\AttachPanelConfigsToReseller;
use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->user = User::factory()->create(['is_admin' => false]);
    $this->user2 = User::factory()->create(['is_admin' => false]);
    $this->user3 = User::factory()->create(['is_admin' => false]);
    
    $this->marzbanPanel = Panel::factory()->create([
        'name' => 'Marzban Test Panel',
        'panel_type' => 'marzban',
        'is_active' => true,
        'url' => 'https://marzban.test',
        'username' => 'admin',
        'password' => 'password',
    ]);
    
    $this->marzneshinPanel = Panel::factory()->create([
        'name' => 'Marzneshin Test Panel',
        'panel_type' => 'marzneshin',
        'is_active' => true,
        'url' => 'https://marzneshin.test',
        'username' => 'admin',
        'password' => 'password',
    ]);
    
    $this->xuiPanel = Panel::factory()->create([
        'name' => 'XUI Test Panel',
        'panel_type' => 'xui',
        'is_active' => true,
    ]);
    
    $this->marzbanReseller = Reseller::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->marzbanPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);
    
    $this->marzneshinReseller = Reseller::factory()->create([
        'user_id' => $this->user2->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->marzneshinPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);
    
    $this->xuiReseller = Reseller::factory()->create([
        'user_id' => $this->user3->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->xuiPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);
});

test('admin can access attach panel configs page', function () {
    $this->actingAs($this->admin);
    
    Livewire::test(AttachPanelConfigsToReseller::class)
        ->assertSuccessful();
});

test('non-admin cannot access attach panel configs page', function () {
    $this->actingAs($this->user);
    
    Livewire::test(AttachPanelConfigsToReseller::class)
        ->assertForbidden();
});

test('only marzban and marzneshin resellers are listed', function () {
    $this->actingAs($this->admin);
    
    $component = Livewire::test(AttachPanelConfigsToReseller::class);
    
    $resellerOptions = $component->instance()->form->getComponent('data.reseller_id')->getOptions();
    
    expect($resellerOptions)->toHaveKey($this->marzbanReseller->id);
    expect($resellerOptions)->toHaveKey($this->marzneshinReseller->id);
    expect($resellerOptions)->not->toHaveKey($this->xuiReseller->id);
});

test('form fields are correctly defined', function () {
    $this->actingAs($this->admin);
    
    $component = Livewire::test(AttachPanelConfigsToReseller::class);
    
    // Check that required form components exist
    $form = $component->instance()->form;
    expect($form->getComponent('data.reseller_id'))->not->toBeNull();
    // panel_admin and remote_configs are reactive and may not exist until reseller is selected
});

test('page has correct title and navigation', function () {
    expect(AttachPanelConfigsToReseller::getNavigationLabel())->toBe('اتصال کانفیگ‌های پنل به ریسلر');
    expect(AttachPanelConfigsToReseller::getNavigationGroup())->toBe('مدیریت فروشندگان');
});

test('canAccess returns true for admin', function () {
    $this->actingAs($this->admin);
    expect(AttachPanelConfigsToReseller::canAccess())->toBeTrue();
});

test('canAccess returns false for non-admin', function () {
    $this->actingAs($this->user);
    expect(AttachPanelConfigsToReseller::canAccess())->toBeFalse();
});

test('xui reseller validation prevents import', function () {
    $this->actingAs($this->admin);
    
    // The import method should reject XUI panels
    Livewire::test(AttachPanelConfigsToReseller::class)
        ->set('data.reseller_id', $this->xuiReseller->id)
        ->set('data.panel_admin', 'admin1')
        ->set('data.remote_configs', ['user1'])
        ->call('importConfigs')
        ->assertNotified();
    
    // No configs should be created
    expect(ResellerConfig::count())->toBe(0);
});
