<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reseller\Services\ResellerPricingService;
use Tests\TestCase;

class ResellerPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ResellerPricingService $pricingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingService = new ResellerPricingService();
    }

    /** @test */
    public function it_returns_null_for_non_visible_plan()
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create(['user_id' => $user->id, 'type' => 'plan']);
        $plan = Plan::factory()->create(['reseller_visible' => false]);

        $result = $this->pricingService->calculatePrice($reseller, $plan);

        $this->assertNull($result);
    }

    /** @test */
    public function it_calculates_price_from_plan_level_fixed_price()
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create(['user_id' => $user->id, 'type' => 'plan']);
        $plan = Plan::factory()->create([
            'price' => 100,
            'reseller_visible' => true,
            'reseller_price' => 80,
        ]);

        $result = $this->pricingService->calculatePrice($reseller, $plan);

        $this->assertNotNull($result);
        $this->assertEquals(80, $result['price']);
        $this->assertEquals('plan_price', $result['source']);
    }

    /** @test */
    public function it_calculates_price_from_plan_level_discount_percent()
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create(['user_id' => $user->id, 'type' => 'plan']);
        $plan = Plan::factory()->create([
            'price' => 100,
            'reseller_visible' => true,
            'reseller_discount_percent' => 20,
        ]);

        $result = $this->pricingService->calculatePrice($reseller, $plan);

        $this->assertNotNull($result);
        $this->assertEquals(80, $result['price']);
        $this->assertEquals('plan_percent', $result['source']);
    }

    /** @test */
    public function it_prioritizes_override_price_over_plan_price()
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create(['user_id' => $user->id, 'type' => 'plan']);
        $plan = Plan::factory()->create([
            'price' => 100,
            'reseller_visible' => true,
            'reseller_price' => 80,
        ]);

        $reseller->allowedPlans()->attach($plan->id, [
            'override_type' => 'price',
            'override_value' => 70,
            'active' => true,
        ]);

        $result = $this->pricingService->calculatePrice($reseller, $plan);

        $this->assertNotNull($result);
        $this->assertEquals(70, $result['price']);
        $this->assertEquals('override_price', $result['source']);
    }

    /** @test */
    public function it_prioritizes_override_percent_over_everything()
    {
        $user = User::factory()->create();
        $reseller = Reseller::factory()->create(['user_id' => $user->id, 'type' => 'plan']);
        $plan = Plan::factory()->create([
            'price' => 100,
            'reseller_visible' => true,
            'reseller_price' => 80,
            'reseller_discount_percent' => 20,
        ]);

        $reseller->allowedPlans()->attach($plan->id, [
            'override_type' => 'percent',
            'override_value' => 30,
            'active' => true,
        ]);

        $result = $this->pricingService->calculatePrice($reseller, $plan);

        $this->assertNotNull($result);
        $this->assertEquals(70, $result['price']);
        $this->assertEquals('override_percent', $result['source']);
    }
}
