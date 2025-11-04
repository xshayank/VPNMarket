<?php

use App\Models\Panel;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
});

test('eylandoo panel type can be created', function () {
    $panel = Panel::factory()->eylandoo()->create();

    expect($panel->panel_type)->toBe('eylandoo')
        ->and($panel->exists)->toBeTrue();
});

test('eylandoo panel has required attributes', function () {
    $panel = Panel::factory()->eylandoo()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
    ]);

    expect($panel->name)->toBe('Test Eylandoo Panel')
        ->and($panel->url)->toBe('https://eylandoo.example.com')
        ->and($panel->panel_type)->toBe('eylandoo')
        ->and($panel->is_active)->toBeTrue();
});

test('panel factory can generate eylandoo panels randomly', function () {
    $panels = Panel::factory()->count(100)->create();

    $eylandooPanels = $panels->filter(fn ($panel) => $panel->panel_type === 'eylandoo');

    // With 4 panel types, we should get some eylandoo panels in 100 attempts
    expect($eylandooPanels->count())->toBeGreaterThan(0);
});

test('eylandoo panel can have extra configuration', function () {
    $panel = Panel::factory()->eylandoo()->create([
        'extra' => [
            'node_hostname' => 'https://node.example.com',
            'custom_setting' => 'value',
        ],
    ]);

    expect($panel->extra)->toBeArray()
        ->and($panel->extra['node_hostname'])->toBe('https://node.example.com')
        ->and($panel->extra['custom_setting'])->toBe('value');
});
