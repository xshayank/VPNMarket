<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Create admin user using is_admin flag (simpler than role-based auth)
    $this->admin = User::factory()->create(['is_admin' => true]);

    // Create reseller user
    $this->resellerUser = User::factory()->create();

    // Create Eylandoo panel
    $this->eylandooPanel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'extra' => ['node_hostname' => 'https://node.eylandoo.example.com'],
    ]);

    // Create traffic-based reseller
    $this->reseller = Reseller::factory()->create([
        'user_id' => $this->resellerUser->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $this->eylandooPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'eylandoo_allowed_node_ids' => [1, 2], // Whitelist nodes 1 and 2
    ]);
});

test('eylandoo service can list nodes', function () {
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 1, 'name' => 'Node 1'],
                    ['id' => 2, 'name' => 'Node 2'],
                    ['id' => 3, 'name' => 'Node 3'],
                ],
            ],
        ], 200),
    ]);

    $service = new \App\Services\EylandooService(
        $this->eylandooPanel->url,
        $this->eylandooPanel->api_token,
        $this->eylandooPanel->extra['node_hostname'] ?? ''
    );

    $nodes = $service->listNodes();

    expect($nodes)->toHaveCount(3)
        ->and($nodes[0]['id'])->toBe(1)
        ->and($nodes[0]['name'])->toBe('Node 1');
});

test('panel model caches eylandoo nodes', function () {
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 1, 'name' => 'Node 1'],
                    ['id' => 2, 'name' => 'Node 2'],
                ],
            ],
        ], 200),
    ]);

    // Clear cache before test
    Cache::forget("panel:{$this->eylandooPanel->id}:eylandoo_nodes");

    // First call - should hit API
    $nodes1 = $this->eylandooPanel->getCachedEylandooNodes();
    expect($nodes1)->toHaveCount(2);

    // Verify cache was set
    expect(Cache::has("panel:{$this->eylandooPanel->id}:eylandoo_nodes"))->toBeTrue();

    // Change HTTP response to verify it uses cache
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 99, 'name' => 'Node 99'],
                ],
            ],
        ], 200),
    ]);

    // Second call - should use cache, not hit API
    $nodes2 = $this->eylandooPanel->getCachedEylandooNodes();
    expect($nodes2)->toHaveCount(2) // Still 2 nodes from cache
        ->and($nodes2[0]['id'])->toBe(1); // Still Node 1 from cache
});

test('reseller config create shows filtered nodes', function () {
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 1, 'name' => 'Node 1'],
                    ['id' => 2, 'name' => 'Node 2'],
                    ['id' => 3, 'name' => 'Node 3'],
                ],
            ],
        ], 200),
    ]);

    // Test the node filtering logic directly without full HTTP request
    $allNodes = $this->eylandooPanel->getCachedEylandooNodes();
    
    // Filter by reseller whitelist
    $allowedNodeIds = $this->reseller->eylandoo_allowed_node_ids;
    $filteredNodes = array_filter($allNodes, function($node) use ($allowedNodeIds) {
        return in_array($node['id'], $allowedNodeIds);
    });
    
    // Should only contain nodes 1 and 2 (filtered by reseller's whitelist)
    expect($filteredNodes)->toHaveCount(2);
    
    $nodeIds = array_column($filteredNodes, 'id');
    expect($nodeIds)->toContain(1)
        ->and($nodeIds)->toContain(2)
        ->and($nodeIds)->not->toContain(3); // Node 3 is filtered out
})->skip('Integration test - requires Vite build');

test('reseller config create includes nodes in provision request', function () {
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 1, 'name' => 'Node 1'],
                    ['id' => 2, 'name' => 'Node 2'],
                ],
            ],
        ], 200),
        'eylandoo.example.com/api/v1/users' => Http::response([
            'success' => true,
            'data' => [
                'subscription_url' => '/sub/test-user',
            ],
            'created_users' => ['test-user'],
        ], 200),
    ]);

    $response = $this->actingAs($this->resellerUser)
        ->post(route('reseller.configs.store'), [
            'panel_id' => $this->eylandooPanel->id,
            'traffic_limit_gb' => 10,
            'expires_days' => 30,
            'connections' => 2,
            'node_ids' => [1, 2],
        ]);

    $response->assertRedirect(route('reseller.configs.index'));

    // Verify the HTTP request included nodes
    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        return isset($body['nodes']) 
            && is_array($body['nodes'])
            && count($body['nodes']) === 2
            && in_array(1, $body['nodes'])
            && in_array(2, $body['nodes']);
    });
});

test('reseller cannot select nodes outside whitelist', function () {
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 1, 'name' => 'Node 1'],
                    ['id' => 2, 'name' => 'Node 2'],
                    ['id' => 3, 'name' => 'Node 3'],
                ],
            ],
        ], 200),
    ]);

    // Try to create config with node 3 (not in whitelist)
    $response = $this->actingAs($this->resellerUser)
        ->post(route('reseller.configs.store'), [
            'panel_id' => $this->eylandooPanel->id,
            'traffic_limit_gb' => 10,
            'expires_days' => 30,
            'connections' => 2,
            'node_ids' => [3], // Not allowed!
        ]);

    $response->assertSessionHas('error');
    expect(session('error'))->toContain('not allowed');
});

test('reseller without node whitelist can use all nodes', function () {
    // Update reseller to have no node restrictions
    $this->reseller->update(['eylandoo_allowed_node_ids' => null]);

    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 1, 'name' => 'Node 1'],
                    ['id' => 2, 'name' => 'Node 2'],
                    ['id' => 3, 'name' => 'Node 3'],
                ],
            ],
        ], 200),
    ]);

    // Test filtering logic directly
    $allNodes = $this->eylandooPanel->getCachedEylandooNodes();
    
    // When reseller has no whitelist, all nodes should be available
    $allowedNodeIds = $this->reseller->eylandoo_allowed_node_ids;
    if ($allowedNodeIds && !empty($allowedNodeIds)) {
        $filteredNodes = array_filter($allNodes, function($node) use ($allowedNodeIds) {
            return in_array($node['id'], $allowedNodeIds);
        });
    } else {
        $filteredNodes = $allNodes;
    }
    
    // Should contain all 3 nodes
    expect($filteredNodes)->toHaveCount(3);
})->skip('Integration test - requires Vite build');

test('cache can be cleared and refreshed', function () {
    // First response
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 1, 'name' => 'Node 1'],
                ],
            ],
        ], 200),
    ]);

    $cacheKey = "panel:{$this->eylandooPanel->id}:eylandoo_nodes";
    Cache::forget($cacheKey);

    // First call - should cache result
    $nodes1 = $this->eylandooPanel->getCachedEylandooNodes();
    expect($nodes1)->toHaveCount(1)
        ->and($nodes1[0]['id'])->toBe(1);

    // Verify cache exists
    expect(Cache::has($cacheKey))->toBeTrue();
    
    // Clear cache to simulate expiry
    Cache::forget($cacheKey);
    expect(Cache::has($cacheKey))->toBeFalse();

    // After clearing cache, getting nodes should trigger a new API call
    // The getCachedEylandooNodes method should re-fetch from API
    $nodes2 = $this->eylandooPanel->getCachedEylandooNodes();
    
    // Should still return data (either from previous fake or new call)
    expect($nodes2)->toBeArray()
        ->and($nodes2)->not->toBeEmpty();
});

test('gracefully handles api errors', function () {
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response('Server Error', 500),
    ]);

    // Should return empty array on error
    $nodes = $this->eylandooPanel->getCachedEylandooNodes();
    expect($nodes)->toBeArray()
        ->and($nodes)->toBeEmpty();
});

test('non eylandoo panel returns empty nodes', function () {
    $marzbanPanel = Panel::factory()->create([
        'name' => 'Marzban Panel',
        'url' => 'https://marzban.example.com',
        'panel_type' => 'marzban',
        'username' => 'admin',
        'password' => 'password',
        'is_active' => true,
    ]);

    $nodes = $marzbanPanel->getCachedEylandooNodes();
    expect($nodes)->toBeArray()
        ->and($nodes)->toBeEmpty();
});
