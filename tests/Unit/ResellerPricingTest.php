<?php

namespace Tests\Unit;

use App\Models\Plan;
use Illuminate\Support\Collection;
use Modules\Reseller\Models\Reseller;
use Modules\Reseller\Models\ResellerAllowedPlan;
use PHPUnit\Framework\TestCase;

class ResellerPricingTest extends TestCase
{
    public function test_plan_not_visible_returns_null(): void
    {
        $reseller = new Reseller(['type' => 'plan']);
        $reseller->setRelation('allowedPlans', new Collection());
        $plan = new Plan(['price' => 1000, 'reseller_visible' => false]);

        $this->assertNull($reseller->resolvePlanPrice($plan));
    }

    public function test_plan_discount_is_applied(): void
    {
        $reseller = new Reseller(['type' => 'plan']);
        $reseller->setRelation('allowedPlans', new Collection());
        $plan = new Plan([
            'price' => 1000,
            'reseller_visible' => true,
            'reseller_discount_percent' => 10,
        ]);

        $result = $reseller->resolvePlanPrice($plan);

        $this->assertNotNull($result);
        $this->assertSame(900.0, $result['price']);
        $this->assertSame('plan_percent', $result['source']);
    }

    public function test_override_price_takes_precedence(): void
    {
        $reseller = new Reseller(['type' => 'plan']);
        $override = new ResellerAllowedPlan([
            'plan_id' => 1,
            'override_type' => 'price',
            'override_value' => 750,
            'active' => true,
        ]);
        $reseller->setRelation('allowedPlans', new Collection([$override]));

        $plan = new Plan([
            'price' => 1000,
            'reseller_visible' => true,
            'reseller_price' => 950,
        ]);
        $plan->id = 1;

        $result = $reseller->resolvePlanPrice($plan);

        $this->assertNotNull($result);
        $this->assertSame(750.0, $result['price']);
        $this->assertSame('override_price', $result['source']);
    }
}
