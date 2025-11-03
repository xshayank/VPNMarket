<?php

use App\Filament\Pages\AttachPanelConfigsToReseller;
use App\Models\AuditLog;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\ResellerConfigEvent;
use App\Models\User;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->user = User::factory()->create(['is_admin' => false]);
    
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
        'user_id' => $this->user->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->marzneshinPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);
    
    $this->xuiReseller = Reseller::factory()->create([
        'user_id' => $this->user->id,
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
    
    $resellerOptions = $component->instance()->form->getComponent('reseller_id')->getOptions();
    
    expect($resellerOptions)->toHaveKey($this->marzbanReseller->id);
    expect($resellerOptions)->toHaveKey($this->marzneshinReseller->id);
    expect($resellerOptions)->not->toHaveKey($this->xuiReseller->id);
});

test('selecting reseller populates panel admin options for marzban', function () {
    $this->actingAs($this->admin);
    
    // Mock MarzbanService
    $this->mock(MarzbanService::class, function ($mock) {
        $mock->shouldReceive('listAdmins')
            ->andReturn([
                ['username' => 'admin1', 'is_sudo' => false],
                ['username' => 'admin2', 'is_sudo' => false],
            ]);
    });
    
    $component = Livewire::test(AttachPanelConfigsToReseller::class)
        ->set('data.reseller_id', $this->marzbanReseller->id);
    
    $adminOptions = $component->instance()->form->getComponent('panel_admin')->getOptions();
    
    expect($adminOptions)->toHaveKey('admin1');
    expect($adminOptions)->toHaveKey('admin2');
});

test('selecting panel admin populates remote configs for marzban', function () {
    $this->actingAs($this->admin);
    
    // Mock MarzbanService
    $this->mock(MarzbanService::class, function ($mock) {
        $mock->shouldReceive('listAdmins')
            ->andReturn([
                ['username' => 'admin1', 'is_sudo' => false],
            ]);
        $mock->shouldReceive('listConfigsByAdmin')
            ->with('admin1')
            ->andReturn([
                ['id' => 1, 'username' => 'user1', 'status' => 'active', 'used_traffic' => 1000, 'data_limit' => 10000000],
                ['id' => 2, 'username' => 'user2', 'status' => 'active', 'used_traffic' => 2000, 'data_limit' => 20000000],
            ]);
    });
    
    $component = Livewire::test(AttachPanelConfigsToReseller::class)
        ->set('data.reseller_id', $this->marzbanReseller->id)
        ->set('data.panel_admin', 'admin1');
    
    $configOptions = $component->instance()->form->getComponent('remote_configs')->getOptions();
    
    expect($configOptions)->toHaveKey('user1');
    expect($configOptions)->toHaveKey('user2');
});

test('importing configs creates reseller config records', function () {
    $this->actingAs($this->admin);
    
    // Mock MarzbanService
    $this->mock(MarzbanService::class, function ($mock) {
        $mock->shouldReceive('listAdmins')
            ->andReturn([
                ['username' => 'admin1', 'is_sudo' => false],
            ]);
        $mock->shouldReceive('listConfigsByAdmin')
            ->with('admin1')
            ->andReturn([
                ['id' => '123', 'username' => 'user1', 'status' => 'active', 'used_traffic' => 1000, 'data_limit' => 10000000],
                ['id' => '456', 'username' => 'user2', 'status' => 'active', 'used_traffic' => 2000, 'data_limit' => 20000000],
            ]);
    });
    
    Livewire::test(AttachPanelConfigsToReseller::class)
        ->set('data.reseller_id', $this->marzbanReseller->id)
        ->set('data.panel_admin', 'admin1')
        ->set('data.remote_configs', ['user1', 'user2'])
        ->call('importConfigs');
    
    expect(ResellerConfig::count())->toBe(2);
    
    $config1 = ResellerConfig::where('external_username', 'user1')->first();
    expect($config1)->not->toBeNull();
    expect($config1->reseller_id)->toBe($this->marzbanReseller->id);
    expect($config1->panel_id)->toBe($this->marzbanPanel->id);
    expect($config1->panel_type)->toBe('marzban');
    expect($config1->panel_user_id)->toBe('123');
    expect($config1->status)->toBe('active');
    expect($config1->usage_bytes)->toBe(1000);
    expect($config1->traffic_limit_bytes)->toBe(10000000);
    
    $config2 = ResellerConfig::where('external_username', 'user2')->first();
    expect($config2)->not->toBeNull();
});

test('importing configs creates events and audit logs', function () {
    $this->actingAs($this->admin);
    
    // Mock MarzbanService
    $this->mock(MarzbanService::class, function ($mock) {
        $mock->shouldReceive('listAdmins')
            ->andReturn([
                ['username' => 'admin1', 'is_sudo' => false],
            ]);
        $mock->shouldReceive('listConfigsByAdmin')
            ->with('admin1')
            ->andReturn([
                ['id' => '123', 'username' => 'user1', 'status' => 'active', 'used_traffic' => 1000, 'data_limit' => 10000000],
            ]);
    });
    
    Livewire::test(AttachPanelConfigsToReseller::class)
        ->set('data.reseller_id', $this->marzbanReseller->id)
        ->set('data.panel_admin', 'admin1')
        ->set('data.remote_configs', ['user1'])
        ->call('importConfigs');
    
    $config = ResellerConfig::where('external_username', 'user1')->first();
    
    // Check event
    $event = ResellerConfigEvent::where('reseller_config_id', $config->id)->first();
    expect($event)->not->toBeNull();
    expect($event->type)->toBe('imported_from_panel');
    expect($event->meta['panel_id'])->toBe($this->marzbanPanel->id);
    expect($event->meta['panel_type'])->toBe('marzban');
    expect($event->meta['remote_admin_username'])->toBe('admin1');
    
    // Check audit log
    $auditLog = AuditLog::where('action', 'config_imported_from_panel')
        ->where('target_id', $config->id)
        ->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->actor_id)->toBe($this->admin->id);
    expect($auditLog->reason)->toBe('manual_import');
    expect($auditLog->meta['panel_id'])->toBe($this->marzbanPanel->id);
    expect($auditLog->meta['remote_admin_username'])->toBe('admin1');
});

test('duplicate configs are skipped', function () {
    $this->actingAs($this->admin);
    
    // Create existing config
    ResellerConfig::create([
        'reseller_id' => $this->marzbanReseller->id,
        'panel_id' => $this->marzbanPanel->id,
        'panel_type' => 'marzban',
        'panel_user_id' => '123',
        'external_username' => 'user1',
        'status' => 'active',
        'usage_bytes' => 500,
        'traffic_limit_bytes' => 5000000,
        'expires_at' => now()->addDays(30),
        'created_by' => $this->admin->id,
    ]);
    
    // Mock MarzbanService
    $this->mock(MarzbanService::class, function ($mock) {
        $mock->shouldReceive('listAdmins')
            ->andReturn([
                ['username' => 'admin1', 'is_sudo' => false],
            ]);
        $mock->shouldReceive('listConfigsByAdmin')
            ->with('admin1')
            ->andReturn([
                ['id' => '123', 'username' => 'user1', 'status' => 'active', 'used_traffic' => 1000, 'data_limit' => 10000000],
                ['id' => '456', 'username' => 'user2', 'status' => 'active', 'used_traffic' => 2000, 'data_limit' => 20000000],
            ]);
    });
    
    Livewire::test(AttachPanelConfigsToReseller::class)
        ->set('data.reseller_id', $this->marzbanReseller->id)
        ->set('data.panel_admin', 'admin1')
        ->set('data.remote_configs', ['user1', 'user2'])
        ->call('importConfigs')
        ->assertNotified(function ($notification) {
            return str_contains($notification['body'], '1 کانفیگ وارد شد') &&
                   str_contains($notification['body'], '1 کانفیگ تکراری');
        });
    
    // Only one new config should be created
    expect(ResellerConfig::count())->toBe(2);
    expect(ResellerConfig::where('external_username', 'user2')->count())->toBe(1);
});

test('xui reseller cannot be used for import', function () {
    $this->actingAs($this->admin);
    
    Livewire::test(AttachPanelConfigsToReseller::class)
        ->set('data.reseller_id', $this->xuiReseller->id)
        ->set('data.panel_admin', 'admin1')
        ->set('data.remote_configs', ['user1'])
        ->call('importConfigs')
        ->assertNotified(function ($notification) {
            return str_contains($notification['body'], 'Marzban و Marzneshin');
        });
    
    expect(ResellerConfig::count())->toBe(0);
});

test('marzneshin service methods work correctly', function () {
    $this->actingAs($this->admin);
    
    // Mock MarzneshinService
    $this->mock(MarzneshinService::class, function ($mock) {
        $mock->shouldReceive('listAdmins')
            ->andReturn([
                ['username' => 'admin1', 'is_sudo' => false],
            ]);
        $mock->shouldReceive('listConfigsByAdmin')
            ->with('admin1')
            ->andReturn([
                ['id' => '789', 'username' => 'user3', 'status' => 'active', 'used_traffic' => 3000, 'data_limit' => 30000000],
            ]);
    });
    
    Livewire::test(AttachPanelConfigsToReseller::class)
        ->set('data.reseller_id', $this->marzneshinReseller->id)
        ->set('data.panel_admin', 'admin1')
        ->set('data.remote_configs', ['user3'])
        ->call('importConfigs');
    
    expect(ResellerConfig::count())->toBe(1);
    
    $config = ResellerConfig::where('external_username', 'user3')->first();
    expect($config)->not->toBeNull();
    expect($config->panel_type)->toBe('marzneshin');
    expect($config->panel_user_id)->toBe('789');
});
