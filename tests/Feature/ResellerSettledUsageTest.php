<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ResellerSettledUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Log to avoid output during tests
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('notice')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
    }

    public function test_settled_usage_counts_as_current_reseller_usage(): void
    {
        // Create a reseller
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
            'traffic_total_bytes' => 10 * 1024 * 1024 * 1024, // 10 GB
            'traffic_used_bytes' => 0,
            'window_starts_at' => now()->subDays(10),
            'window_ends_at' => now()->addDays(20),
        ]);

        // Create a config with both current and settled usage
        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024, // 1 GB current
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzban',
            'panel_user_id' => 'test_user',
            'created_by' => $user->id,
            'meta' => ['settled_usage_bytes' => 2 * 1024 * 1024 * 1024], // 2 GB settled
        ]);

        // When SyncResellerUsageJob runs, it should count both current AND settled
        $totalUsage = $config->usage_bytes + $config->getSettledUsageBytes();
        $this->assertEquals(3 * 1024 * 1024 * 1024, $totalUsage); // 1 GB + 2 GB = 3 GB

        // The reseller's traffic_used_bytes should include both
        $expectedResellerUsage = $reseller->configs()
            ->get()
            ->sum(function ($c) {
                return $c->usage_bytes + $c->getSettledUsageBytes();
            });
        
        $this->assertEquals(3 * 1024 * 1024 * 1024, $expectedResellerUsage);
    }

    public function test_settled_usage_display_method_works(): void
    {
        // Create a config with settled usage
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create([
            'user_id' => $user->id,
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzban',
            'panel_user_id' => 'test_user',
            'created_by' => $user->id,
            'meta' => ['settled_usage_bytes' => 2 * 1024 * 1024 * 1024], // 2 GB settled
        ]);

        // Verify the getSettledUsageBytes method returns correct value
        $this->assertEquals(2 * 1024 * 1024 * 1024, $config->getSettledUsageBytes());
        
        // Verify formatting for display (2.0 GB)
        $settledGB = round($config->getSettledUsageBytes() / (1024 * 1024 * 1024), 2);
        $this->assertEquals(2.0, $settledGB);

        // Verify getTotalUsageBytes includes both
        $this->assertEquals(3 * 1024 * 1024 * 1024, $config->getTotalUsageBytes());
    }

    public function test_config_with_no_settled_usage_returns_zero(): void
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create(['user_id' => $user->id]);

        $config = ResellerConfig::create([
            'reseller_id' => $reseller->id,
            'external_username' => 'test_user',
            'traffic_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'usage_bytes' => 1 * 1024 * 1024 * 1024,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'panel_type' => 'marzban',
            'panel_user_id' => 'test_user',
            'created_by' => $user->id,
            'meta' => [], // No settled usage
        ]);

        $this->assertEquals(0, $config->getSettledUsageBytes());
        $this->assertEquals(1 * 1024 * 1024 * 1024, $config->getTotalUsageBytes());
    }
}
