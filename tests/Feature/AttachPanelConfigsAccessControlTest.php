<?php

use App\Filament\Pages\AttachPanelConfigsToReseller;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->regularUser = User::factory()->create(['is_admin' => false]);
});

test('getSlug returns correct slug', function () {
    expect(AttachPanelConfigsToReseller::getSlug())
        ->toBe('attach-panel-configs-to-reseller');
});

test('shouldRegisterNavigation returns true for admin', function () {
    $this->actingAs($this->admin);

    expect(AttachPanelConfigsToReseller::shouldRegisterNavigation())
        ->toBeTrue();
});

test('shouldRegisterNavigation returns false for non-admin', function () {
    $this->actingAs($this->regularUser);

    expect(AttachPanelConfigsToReseller::shouldRegisterNavigation())
        ->toBeFalse();
});

test('shouldRegisterNavigation returns false for guest', function () {
    expect(AttachPanelConfigsToReseller::shouldRegisterNavigation())
        ->toBeFalse();
});

test('canAccess returns true when user is_admin is true', function () {
    $this->actingAs($this->admin);

    expect(AttachPanelConfigsToReseller::canAccess())
        ->toBeTrue();
});

test('canAccess returns false when user is_admin is false', function () {
    $this->actingAs($this->regularUser);

    expect(AttachPanelConfigsToReseller::canAccess())
        ->toBeFalse();
});

test('canAccess returns false when no user is authenticated', function () {
    expect(AttachPanelConfigsToReseller::canAccess())
        ->toBeFalse();
});

test('canAccess handles null user gracefully', function () {
    // Ensure no user is authenticated
    auth()->logout();

    expect(AttachPanelConfigsToReseller::canAccess())
        ->toBeFalse();
});
