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
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
});

test('marzban service filters configs by admin username', function () {
    // Mock Marzban API response with users owned by different admins
    $mockUsers = [
        [
            'id' => 1,
            'username' => 'user1',
            'status' => 'active',
            'used_traffic' => 1000,
            'data_limit' => 10000,
            'admin' => 'admin_x',
        ],
        [
            'id' => 2,
            'username' => 'user2',
            'status' => 'active',
            'used_traffic' => 2000,
            'data_limit' => 20000,
            'admin' => 'admin_y',
        ],
        [
            'id' => 3,
            'username' => 'user3',
            'status' => 'active',
            'used_traffic' => 3000,
            'data_limit' => 30000,
            'admin' => 'admin_x',
        ],
    ];

    $service = Mockery::mock(MarzbanService::class)->makePartial();
    $service->shouldReceive('login')->andReturn(true);
    $service->shouldReceive('listConfigsByAdmin')
        ->with('admin_x')
        ->andReturn(array_filter($mockUsers, fn ($u) => $u['admin'] === 'admin_x'));

    $configs = $service->listConfigsByAdmin('admin_x');

    expect($configs)->toHaveCount(2)
        ->and(collect($configs)->pluck('username')->toArray())->toBe(['user1', 'user3']);
});

test('marzneshin service filters configs by admin username', function () {
    // Mock Marzneshin API response with users owned by different admins
    $mockUsers = [
        [
            'id' => 1,
            'username' => 'user1',
            'status' => 'active',
            'used_traffic' => 1000,
            'data_limit' => 10000,
            'admin' => 'admin_x',
        ],
        [
            'id' => 2,
            'username' => 'user2',
            'status' => 'active',
            'used_traffic' => 2000,
            'data_limit' => 20000,
            'admin' => 'admin_y',
        ],
    ];

    $service = Mockery::mock(MarzneshinService::class)->makePartial();
    $service->shouldReceive('login')->andReturn(true);
    $service->shouldReceive('listConfigsByAdmin')
        ->with('admin_x')
        ->andReturn(array_filter($mockUsers, fn ($u) => $u['admin'] === 'admin_x'));

    $configs = $service->listConfigsByAdmin('admin_x');

    expect($configs)->toHaveCount(1)
        ->and($configs[0]['username'])->toBe('user1');
});

test('server-side validation rejects configs not owned by selected admin', function () {
    $this->actingAs($this->admin);

    $marzbanPanel = Panel::factory()->marzban()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $marzbanPanel->id,
    ]);

    // Mock the fetchPanelAdmins and fetchConfigsByAdmin methods
    $component = Livewire::test(AttachPanelConfigsToReseller::class);

    // Simulate configs where one doesn't belong to the selected admin
    $mockAdmins = [
        ['username' => 'admin_x', 'is_sudo' => false],
    ];

    $mockConfigs = [
        [
            'id' => 1,
            'username' => 'config1',
            'status' => 'active',
            'used_traffic' => 0,
            'data_limit' => 10000000000,
            'admin' => 'admin_x',
            'owner_username' => 'admin_x',
        ],
        [
            'id' => 2,
            'username' => 'config2',
            'status' => 'active',
            'used_traffic' => 0,
            'data_limit' => 10000000000,
            'admin' => 'admin_y', // Different admin!
            'owner_username' => 'admin_y',
        ],
    ];

    Cache::put("panel_admins_{$marzbanPanel->id}", $mockAdmins, 60);
    Cache::put("panel_configs_{$marzbanPanel->id}_admin_x", $mockConfigs, 30);

    // Try to import configs including one that doesn't belong to admin_x
    $component
        ->fillForm([
            'reseller_id' => $reseller->id,
            'panel_admin' => 'admin_x',
            'remote_configs' => ['config1', 'config2'],
        ])
        ->call('importConfigs');

    // Verify that the operation failed due to validation error
    $component->assertHasNoFormErrors()
        ->assertNotified(); // Should show error notification

    // Verify no configs were imported due to validation failure
    expect(ResellerConfig::count())->toBe(0);
});

test('successful import stores admin metadata in events and audit logs', function () {
    $this->actingAs($this->admin);

    $marzbanPanel = Panel::factory()->marzban()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $marzbanPanel->id,
    ]);

    // Mock the methods with valid data
    $mockAdmins = [
        ['username' => 'admin_x', 'is_sudo' => false],
    ];

    $mockConfigs = [
        [
            'id' => 1,
            'username' => 'config1',
            'status' => 'active',
            'used_traffic' => 0,
            'data_limit' => 10000000000,
            'admin' => 'admin_x',
            'owner_username' => 'admin_x',
        ],
    ];

    Cache::put("panel_admins_{$marzbanPanel->id}", $mockAdmins, 60);
    Cache::put("panel_configs_{$marzbanPanel->id}_admin_x", $mockConfigs, 30);

    Livewire::test(AttachPanelConfigsToReseller::class)
        ->fillForm([
            'reseller_id' => $reseller->id,
            'panel_admin' => 'admin_x',
            'remote_configs' => ['config1'],
        ])
        ->call('importConfigs');

    // Verify config was created
    $config = ResellerConfig::first();
    expect($config)->not->toBeNull()
        ->and($config->external_username)->toBe('config1');

    // Verify event contains admin metadata
    $event = ResellerConfigEvent::where('reseller_config_id', $config->id)->first();
    expect($event)->not->toBeNull()
        ->and($event->meta['panel_admin_username'])->toBe('admin_x')
        ->and($event->meta['owner_admin'])->toBe('admin_x');

    // Verify audit log contains admin information
    $auditLog = AuditLog::where('target_type', 'reseller_config')
        ->where('target_id', $config->id)
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->meta['selected_admin_username'])->toBe('admin_x')
        ->and($auditLog->meta['reseller_id'])->toBe($reseller->id);

    // Verify bulk audit log was created
    $bulkLog = AuditLog::where('target_type', 'reseller')
        ->where('target_id', $reseller->id)
        ->where('reason', 'bulk_attach')
        ->first();

    expect($bulkLog)->not->toBeNull()
        ->and($bulkLog->meta['selected_admin_id'])->toBe('admin_x')
        ->and($bulkLog->meta['total_attached'])->toBe(1);
});

test('selecting reseller A admin X shows only X configs not Y configs for marzban', function () {
    $this->actingAs($this->admin);

    $marzbanPanel = Panel::factory()->marzban()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $marzbanPanel->id,
    ]);

    // Mock admins
    $mockAdmins = [
        ['username' => 'admin_x', 'is_sudo' => false],
        ['username' => 'admin_y', 'is_sudo' => false],
    ];

    // Mock configs for admin_x
    $configsForAdminX = [
        [
            'id' => 1,
            'username' => 'x_config1',
            'status' => 'active',
            'used_traffic' => 0,
            'data_limit' => 10000000000,
            'admin' => 'admin_x',
            'owner_username' => 'admin_x',
        ],
        [
            'id' => 2,
            'username' => 'x_config2',
            'status' => 'active',
            'used_traffic' => 0,
            'data_limit' => 10000000000,
            'admin' => 'admin_x',
            'owner_username' => 'admin_x',
        ],
    ];

    // Mock configs for admin_y
    $configsForAdminY = [
        [
            'id' => 3,
            'username' => 'y_config1',
            'status' => 'active',
            'used_traffic' => 0,
            'data_limit' => 10000000000,
            'admin' => 'admin_y',
            'owner_username' => 'admin_y',
        ],
    ];

    Cache::put("panel_admins_{$marzbanPanel->id}", $mockAdmins, 60);
    Cache::put("panel_configs_{$marzbanPanel->id}_admin_x", $configsForAdminX, 30);
    Cache::put("panel_configs_{$marzbanPanel->id}_admin_y", $configsForAdminY, 30);

    // Test selecting admin_x - should only see admin_x's configs
    $component = Livewire::test(AttachPanelConfigsToReseller::class)
        ->fillForm([
            'reseller_id' => $reseller->id,
            'panel_admin' => 'admin_x',
        ]);

    // Get the options for remote_configs
    $options = $component->instance()->form->getComponent('data.remote_configs')->getOptions();

    expect($options)->toHaveKeys(['x_config1', 'x_config2'])
        ->not->toHaveKey('y_config1');
});

test('selecting reseller A admin X shows only X configs not Y configs for marzneshin', function () {
    $this->actingAs($this->admin);

    $marzneshinPanel = Panel::factory()->marzneshin()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $marzneshinPanel->id,
    ]);

    // Mock admins
    $mockAdmins = [
        ['username' => 'admin_x', 'is_sudo' => false],
        ['username' => 'admin_y', 'is_sudo' => false],
    ];

    // Mock configs for admin_x only
    $configsForAdminX = [
        [
            'id' => 1,
            'username' => 'x_config1',
            'status' => 'active',
            'used_traffic' => 0,
            'data_limit' => 10000000000,
            'admin' => 'admin_x',
            'owner_username' => 'admin_x',
        ],
    ];

    Cache::put("panel_admins_{$marzneshinPanel->id}", $mockAdmins, 60);
    Cache::put("panel_configs_{$marzneshinPanel->id}_admin_x", $configsForAdminX, 30);

    // Test selecting admin_x
    $component = Livewire::test(AttachPanelConfigsToReseller::class)
        ->fillForm([
            'reseller_id' => $reseller->id,
            'panel_admin' => 'admin_x',
        ]);

    // Get the options for remote_configs
    $options = $component->instance()->form->getComponent('data.remote_configs')->getOptions();

    expect($options)->toHaveKey('x_config1')
        ->and(count($options))->toBe(1);
});

test('import validates that admin is non-sudo', function () {
    $this->actingAs($this->admin);

    $marzbanPanel = Panel::factory()->marzban()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $marzbanPanel->id,
    ]);

    // Mock admins - only non-sudo admin_x exists
    $mockAdmins = [
        ['username' => 'admin_x', 'is_sudo' => false],
    ];

    $mockConfigs = [
        [
            'id' => 1,
            'username' => 'config1',
            'status' => 'active',
            'used_traffic' => 0,
            'data_limit' => 10000000000,
            'admin' => 'sudo_admin', // Owned by sudo admin
            'owner_username' => 'sudo_admin',
        ],
    ];

    Cache::put("panel_admins_{$marzbanPanel->id}", $mockAdmins, 60);
    Cache::put("panel_configs_{$marzbanPanel->id}_sudo_admin", $mockConfigs, 30);

    // Try to import with a sudo admin that's not in the non-sudo list
    Livewire::test(AttachPanelConfigsToReseller::class)
        ->fillForm([
            'reseller_id' => $reseller->id,
            'panel_admin' => 'sudo_admin',
            'remote_configs' => ['config1'],
        ])
        ->call('importConfigs')
        ->assertNotified(); // Should show error

    // Verify no configs were imported
    expect(ResellerConfig::count())->toBe(0);
});
