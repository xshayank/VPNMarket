<?php

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('eylandoo panel with no nodes returns default nodes 1 and 2 in controller logic', function () {
    // Create reseller user
    $resellerUser = User::factory()->create();

    // Create Eylandoo panel
    $eylandooPanel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'extra' => ['node_hostname' => 'https://node.eylandoo.example.com'],
    ]);

    // Create traffic-based reseller
    $reseller = Reseller::factory()->create([
        'user_id' => $resellerUser->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $eylandooPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024, // 100 GB
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'eylandoo_allowed_node_ids' => null, // No restrictions
    ]);

    // Fake API to return empty nodes array
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [],
            ],
        ], 200),
    ]);

    // Simulate the controller logic
    $allNodes = $eylandooPanel->getCachedEylandooNodes();
    expect($allNodes)->toBeEmpty();
    
    // Apply the default nodes logic
    $nodes = $allNodes;
    $eylandooNodes = [];
    
    if (!empty($nodes)) {
        $eylandooNodes[$eylandooPanel->id] = array_values($nodes);
    } else {
        // No nodes found - use default IDs 1 and 2
        $defaultNodeIds = config('panels.eylandoo.default_node_ids', [1, 2]);
        $eylandooNodes[$eylandooPanel->id] = array_map(function($id) {
            return [
                'id' => (string) $id,
                'name' => "Node {$id} (default)",
                'is_default' => true,
            ];
        }, $defaultNodeIds);
    }
    
    // Assert: Should have exactly 2 default nodes
    expect($eylandooNodes[$eylandooPanel->id])->toHaveCount(2)
        ->and($eylandooNodes[$eylandooPanel->id][0]['id'])->toBe('1')
        ->and($eylandooNodes[$eylandooPanel->id][0]['is_default'])->toBeTrue()
        ->and($eylandooNodes[$eylandooPanel->id][0]['name'])->toContain('default')
        ->and($eylandooNodes[$eylandooPanel->id][1]['id'])->toBe('2')
        ->and($eylandooNodes[$eylandooPanel->id][1]['is_default'])->toBeTrue()
        ->and($eylandooNodes[$eylandooPanel->id][1]['name'])->toContain('default');
});

test('eylandoo panel with actual nodes does not use defaults in controller logic', function () {
    // Create Eylandoo panel
    $eylandooPanel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'extra' => ['node_hostname' => 'https://node.eylandoo.example.com'],
    ]);

    // Fake API to return actual nodes
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => [
                'nodes' => [
                    ['id' => 10, 'name' => 'Real Node 10'],
                    ['id' => 20, 'name' => 'Real Node 20'],
                ],
            ],
        ], 200),
    ]);

    // Simulate the controller logic
    $allNodes = $eylandooPanel->getCachedEylandooNodes();
    expect($allNodes)->toHaveCount(2);
    
    // Apply the default nodes logic
    $nodes = $allNodes;
    $eylandooNodes = [];
    
    if (!empty($nodes)) {
        $eylandooNodes[$eylandooPanel->id] = array_values($nodes);
    } else {
        // No nodes found - use default IDs 1 and 2
        $defaultNodeIds = config('panels.eylandoo.default_node_ids', [1, 2]);
        $eylandooNodes[$eylandooPanel->id] = array_map(function($id) {
            return [
                'id' => (string) $id,
                'name' => "Node {$id} (default)",
                'is_default' => true,
            ];
        }, $defaultNodeIds);
    }
    
    // Assert: Check that actual nodes are used, not defaults
    expect($eylandooNodes[$eylandooPanel->id])->toHaveCount(2)
        ->and($eylandooNodes[$eylandooPanel->id][0]['id'])->toBe('10')
        ->and($eylandooNodes[$eylandooPanel->id][0]['name'])->toBe('Real Node 10')
        ->and($eylandooNodes[$eylandooPanel->id][0]['name'])->not->toContain('default')
        ->and($eylandooNodes[$eylandooPanel->id][1]['id'])->toBe('20')
        ->and($eylandooNodes[$eylandooPanel->id][1]['name'])->toBe('Real Node 20')
        ->and($eylandooNodes[$eylandooPanel->id][0])->not->toHaveKey('is_default')
        ->and($eylandooNodes[$eylandooPanel->id][1])->not->toHaveKey('is_default');
});

test('default nodes can be selected and sent to provision', function () {
    // Create reseller user
    $resellerUser = User::factory()->create();

    // Create Eylandoo panel
    $eylandooPanel = Panel::factory()->create([
        'name' => 'Test Eylandoo Panel',
        'url' => 'https://eylandoo.example.com',
        'panel_type' => 'eylandoo',
        'api_token' => 'test-token',
        'is_active' => true,
        'extra' => ['node_hostname' => 'https://node.eylandoo.example.com'],
    ]);

    // Create traffic-based reseller
    $reseller = Reseller::factory()->create([
        'user_id' => $resellerUser->id,
        'type' => 'traffic',
        'status' => 'active',
        'panel_id' => $eylandooPanel->id,
        'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
        'traffic_used_bytes' => 0,
        'window_starts_at' => now(),
        'window_ends_at' => now()->addDays(30),
        'eylandoo_allowed_node_ids' => null,
    ]);

    // Fake API responses
    Http::fake([
        'eylandoo.example.com/api/v1/nodes' => Http::response([
            'data' => ['nodes' => []],
        ], 200),
        'eylandoo.example.com/api/v1/users' => Http::response([
            'success' => true,
            'data' => [
                'subscription_url' => '/sub/test-user',
            ],
            'created_users' => ['test-user'],
        ], 200),
    ]);

    // Act: Create config with default node IDs
    $response = $this->actingAs($resellerUser)
        ->post(route('reseller.configs.store'), [
            'panel_id' => $eylandooPanel->id,
            'traffic_limit_gb' => 10,
            'expires_days' => 30,
            'connections' => 2,
            'node_ids' => [1, 2], // Using default node IDs
        ]);

    // Assert: Redirect to configs index
    $response->assertRedirect(route('reseller.configs.index'));

    // Assert: Config was created
    expect($reseller->configs()->count())->toBe(1);

    // Assert: Nodes were included in the API request
    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        return isset($body['nodes'])
            && is_array($body['nodes'])
            && in_array(1, $body['nodes'])
            && in_array(2, $body['nodes']);
    });
});
