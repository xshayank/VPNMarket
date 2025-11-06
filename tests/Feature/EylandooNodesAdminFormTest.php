<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use function Pest\Livewire\livewire;

beforeEach(function () {
    // Create admin user
    $this->admin = User::factory()->create(['is_admin' => true]);
    
    // Create Eylandoo panel
    $this->eylandooPanel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-api-token',
        'is_active' => true,
        'extra' => ['node_hostname' => 'https://node.eylandoo.example.com'],
    ]);
    
    // Create Marzban panel for comparison
    $this->marzbanPanel = Panel::factory()->create([
        'name' => 'Test Marzban Panel',
        'url' => 'https://marzban.example.com',
        'panel_type' => 'marzban',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
    ]);
    
    // Create a reseller user
    $this->resellerUser = User::factory()->create();
    
    // Fake HTTP responses for Eylandoo API
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 1, 'name' => 'US Node 1'],
                    ['id' => 2, 'name' => 'EU Node 1'],
                    ['id' => 3, 'name' => 'Asia Node 1'],
                ],
            ],
        ], 200),
    ]);
});

test('admin can see nodes on reseller create page when eylandoo panel selected', function () {
    // Clear any cached nodes
    Cache::forget("panel:{$this->eylandooPanel->id}:eylandoo_nodes");
    
    // Fetch nodes to verify they are available
    $nodes = $this->eylandooPanel->getCachedEylandooNodes();
    
    expect($nodes)->toHaveCount(3)
        ->and($nodes[0]['id'])->toBe('1') // String ID
        ->and($nodes[0]['name'])->toBe('US Node 1')
        ->and($nodes[1]['id'])->toBe('2') // String ID
        ->and($nodes[1]['name'])->toBe('EU Node 1')
        ->and($nodes[2]['id'])->toBe('3') // String ID
        ->and($nodes[2]['name'])->toBe('Asia Node 1');
});

test('admin can see nodes on reseller edit page with eylandoo panel', function () {
    // Create a traffic reseller with Eylandoo panel
    $reseller = Reseller::factory()->create([
        'user_id' => $this->resellerUser->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->eylandooPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
    ]);
    
    // Clear cache and fetch nodes
    Cache::forget("panel:{$this->eylandooPanel->id}:eylandoo_nodes");
    $nodes = $this->eylandooPanel->getCachedEylandooNodes();
    
    expect($nodes)->toHaveCount(3);
    
    // Verify the reseller has the correct panel
    expect($reseller->panel_id)->toBe($this->eylandooPanel->id)
        ->and($reseller->panel->panel_type)->toBe('eylandoo');
});

test('nodes are cached for 5 minutes', function () {
    Cache::forget("panel:{$this->eylandooPanel->id}:eylandoo_nodes");
    
    // First call - should hit API
    $nodes1 = $this->eylandooPanel->getCachedEylandooNodes();
    expect($nodes1)->toHaveCount(3);
    
    // Verify cache was set
    $cacheKey = "panel:{$this->eylandooPanel->id}:eylandoo_nodes";
    expect(Cache::has($cacheKey))->toBeTrue();
    
    // Change HTTP response
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 99, 'name' => 'New Node'],
                ],
            ],
        ], 200),
    ]);
    
    // Second call - should use cache
    $nodes2 = $this->eylandooPanel->getCachedEylandooNodes();
    expect($nodes2)->toHaveCount(3) // Still 3 nodes from cache
        ->and($nodes2[0]['id'])->toBe('1'); // Still first node from cache, string ID
});

test('marzban panel returns empty nodes array', function () {
    $nodes = $this->marzbanPanel->getCachedEylandooNodes();
    
    expect($nodes)->toBeArray()
        ->and($nodes)->toBeEmpty();
});

test('eylandoo panel with no api_token returns empty nodes with warning log', function () {
    // Create panel without API token
    $panelNoToken = Panel::factory()->create([
        'name' => 'Eylandoo No Token',
        'url' => 'https://eylandoo-no-token.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => null,
        'is_active' => true,
    ]);
    
    Cache::forget("panel:{$panelNoToken->id}:eylandoo_nodes");
    
    $nodes = $panelNoToken->getCachedEylandooNodes();
    
    expect($nodes)->toBeArray()
        ->and($nodes)->toBeEmpty();
});

test('eylandoo panel with api error returns empty nodes with error log', function () {
    // Create a new panel for this test
    $errorPanel = Panel::factory()->create([
        'name' => 'Error Panel',
        'url' => 'https://error.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'error-token',
        'is_active' => true,
    ]);
    
    // Fake HTTP error response
    Http::fake([
        'error.example.com/api/v1/nodes' => Http::response('Internal Server Error', 500),
    ]);
    
    Cache::forget("panel:{$errorPanel->id}:eylandoo_nodes");
    
    $nodes = $errorPanel->getCachedEylandooNodes();
    
    expect($nodes)->toBeArray()
        ->and($nodes)->toBeEmpty();
});

test('reseller can be created with selected eylandoo nodes', function () {
    // Create a traffic reseller with specific nodes
    $reseller = Reseller::factory()->create([
        'user_id' => $this->resellerUser->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->eylandooPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'eylandoo_allowed_node_ids' => [1, 2], // Only allow nodes 1 and 2
    ]);
    
    expect($reseller->eylandoo_allowed_node_ids)->toBe([1, 2])
        ->and($reseller->panel->panel_type)->toBe('eylandoo');
});

test('reseller with no node restrictions has null eylandoo_allowed_node_ids', function () {
    // Create reseller without node restrictions
    $reseller = Reseller::factory()->create([
        'user_id' => $this->resellerUser->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->eylandooPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'eylandoo_allowed_node_ids' => null, // No restrictions
    ]);
    
    expect($reseller->eylandoo_allowed_node_ids)->toBeNull();
});

test('panel credentials shape is validated before fetching nodes', function () {
    // Panel with empty URL (not null, but empty string)
    $panelNoUrl = Panel::factory()->create([
        'name' => 'No URL Panel',
        'url' => '', // Empty URL
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
    ]);
    
    Cache::forget("panel:{$panelNoUrl->id}:eylandoo_nodes");
    
    $nodes = $panelNoUrl->getCachedEylandooNodes();
    
    // Should return empty array and log warning
    expect($nodes)->toBeArray()
        ->and($nodes)->toBeEmpty();
});
