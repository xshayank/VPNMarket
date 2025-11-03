<?php

use App\Filament\Pages\AttachPanelConfigsToReseller;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->regularUser = User::factory()->create(['is_admin' => false]);
});

test('admin can access attach panel configs page', function () {
    $this->actingAs($this->admin);

    expect(AttachPanelConfigsToReseller::canAccess())->toBeTrue();
});

test('regular user cannot access attach panel configs page', function () {
    $this->actingAs($this->regularUser);

    expect(AttachPanelConfigsToReseller::canAccess())->toBeFalse();
});

test('guest cannot access attach panel configs page', function () {
    expect(AttachPanelConfigsToReseller::canAccess())->toBeFalse();
});

test('page renders successfully for admin', function () {
    $this->actingAs($this->admin);

    Livewire::test(AttachPanelConfigsToReseller::class)
        ->assertSuccessful();
});

test('page form has required fields', function () {
    $this->actingAs($this->admin);

    Livewire::test(AttachPanelConfigsToReseller::class)
        ->assertFormExists()
        ->assertFormFieldExists('reseller_id')
        ->assertFormFieldExists('panel_admin')
        ->assertFormFieldExists('remote_configs');
});

test('reseller select only shows marzban and marzneshin resellers', function () {
    $this->actingAs($this->admin);

    // Create panels with different types
    $marzbanPanel = Panel::factory()->marzban()->create();
    $marzneshinPanel = Panel::factory()->marzneshin()->create();
    $otherPanel = Panel::factory()->xui()->create();

    // Create resellers for each panel type
    $marzbanReseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $marzbanPanel->id,
    ]);

    $marzneshinReseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $marzneshinPanel->id,
    ]);

    $otherReseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $otherPanel->id,
    ]);

    $component = Livewire::test(AttachPanelConfigsToReseller::class);

    // Get reseller options by evaluating the closure
    $resellerField = $component->instance()->form->getComponent('reseller_id');
    $options = $resellerField->getOptions();

    // Verify only marzban and marzneshin resellers are included
    expect($options)
        ->toHaveKey($marzbanReseller->id)
        ->toHaveKey($marzneshinReseller->id)
        ->not->toHaveKey($otherReseller->id);
});

test('panel admin field is hidden initially', function () {
    $this->actingAs($this->admin);

    Livewire::test(AttachPanelConfigsToReseller::class)
        ->assertFormFieldIsHidden('panel_admin');
});

test('panel admin field becomes visible after selecting reseller', function () {
    $this->actingAs($this->admin);

    $panel = Panel::factory()->marzban()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $panel->id,
    ]);

    Livewire::test(AttachPanelConfigsToReseller::class)
        ->fillForm(['reseller_id' => $reseller->id])
        ->assertFormFieldIsVisible('panel_admin');
});

test('remote configs field is hidden initially', function () {
    $this->actingAs($this->admin);

    Livewire::test(AttachPanelConfigsToReseller::class)
        ->assertFormFieldIsHidden('remote_configs');
});

test('remote configs field becomes visible after selecting panel admin', function () {
    $this->actingAs($this->admin);

    $panel = Panel::factory()->marzban()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $panel->id,
    ]);

    Livewire::test(AttachPanelConfigsToReseller::class)
        ->fillForm([
            'reseller_id' => $reseller->id,
            'panel_admin' => 'test_admin',
        ])
        ->assertFormFieldIsVisible('remote_configs');
});

test('form resets panel admin when reseller changes', function () {
    $this->actingAs($this->admin);

    $panel1 = Panel::factory()->marzban()->create();
    $panel2 = Panel::factory()->marzneshin()->create();
    
    $reseller1 = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $panel1->id,
    ]);
    
    $reseller2 = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $panel2->id,
    ]);

    Livewire::test(AttachPanelConfigsToReseller::class)
        ->fillForm([
            'reseller_id' => $reseller1->id,
            'panel_admin' => 'test_admin',
        ])
        ->assertSet('data.panel_admin', 'test_admin')
        ->fillForm(['reseller_id' => $reseller2->id])
        ->assertSet('data.panel_admin', null);
});

test('form resets remote configs when panel admin changes', function () {
    $this->actingAs($this->admin);

    $panel = Panel::factory()->marzban()->create();
    $reseller = Reseller::factory()->create([
        'user_id' => User::factory()->create()->id,
        'panel_id' => $panel->id,
    ]);

    Livewire::test(AttachPanelConfigsToReseller::class)
        ->fillForm([
            'reseller_id' => $reseller->id,
            'panel_admin' => 'admin1',
            'remote_configs' => ['config1'],
        ])
        ->assertSet('data.remote_configs', ['config1'])
        ->fillForm(['panel_admin' => 'admin2'])
        ->assertSet('data.remote_configs', []);
});

test('page navigation properties are set correctly', function () {
    expect(AttachPanelConfigsToReseller::getNavigationIcon())->toBe('heroicon-o-link')
        ->and(AttachPanelConfigsToReseller::getNavigationLabel())->toBe('اتصال کانفیگ‌های پنل به ریسلر')
        ->and(AttachPanelConfigsToReseller::getNavigationGroup())->toBe('مدیریت فروشندگان')
        ->and(AttachPanelConfigsToReseller::getNavigationSort())->toBe(50);
});

test('page is registered in admin panel', function () {
    // Get the admin panel configuration
    $provider = app(\App\Providers\Filament\AdminPanelProvider::class);
    $panel = $provider->panel(\Filament\Panel::make());

    // Get registered pages
    $pages = $panel->getPages();

    // Check if our page is registered
    expect($pages)->toContain(AttachPanelConfigsToReseller::class);
});
