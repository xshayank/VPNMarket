<?php

namespace Modules\Reseller\Services;

use App\Models\Plan;
use App\Models\Reseller;

class ResellerPricingService
{
    /**
     * Calculate the reseller price for a plan.
     * Returns null if the plan is not available to the reseller.
     *
     * Priority:
     * 1. If reseller_allowed_plans.override_type == 'percent' -> price = round(plan.price * (1 - override_value/100))
     * 2. Else if override_type == 'price' -> price = override_value
     * 3. Else if plan.reseller_discount_percent set -> price = round(plan.price * (1 - plan.reseller_discount_percent/100))
     * 4. Else if plan.reseller_price set -> price = plan.reseller_price
     * 5. Else plan hidden to reseller (return null)
     */
    public function calculatePrice(Reseller $reseller, Plan $plan): ?array
    {
        // Check if plan is visible to resellers
        if (!$plan->reseller_visible) {
            return null;
        }

        // Check if reseller has an allowed plan entry
        $allowedPlan = $reseller->allowedPlans()
            ->where('plan_id', $plan->id)
            ->wherePivot('active', true)
            ->first();

        if ($allowedPlan) {
            // Priority 1: Percent override
            if ($allowedPlan->pivot->override_type === 'percent') {
                $price = round($plan->price * (1 - $allowedPlan->pivot->override_value / 100), 2);
                return [
                    'price' => $price,
                    'source' => 'override_percent',
                    'original_price' => $plan->price,
                ];
            }

            // Priority 2: Fixed price override
            if ($allowedPlan->pivot->override_type === 'price') {
                return [
                    'price' => $allowedPlan->pivot->override_value,
                    'source' => 'override_price',
                    'original_price' => $plan->price,
                ];
            }
        }

        // Priority 3: Plan-level discount percent
        if ($plan->reseller_discount_percent !== null) {
            $price = round($plan->price * (1 - $plan->reseller_discount_percent / 100), 2);
            return [
                'price' => $price,
                'source' => 'plan_percent',
                'original_price' => $plan->price,
            ];
        }

        // Priority 4: Plan-level fixed reseller price
        if ($plan->reseller_price !== null) {
            return [
                'price' => $plan->reseller_price,
                'source' => 'plan_price',
                'original_price' => $plan->price,
            ];
        }

        // Plan not available to reseller
        return null;
    }

    /**
     * Get all available plans for a reseller with pricing
     */
    public function getAvailablePlans(Reseller $reseller): array
    {
        $plans = Plan::where('is_active', true)
            ->where('reseller_visible', true)
            ->get();

        $availablePlans = [];

        foreach ($plans as $plan) {
            $pricing = $this->calculatePrice($reseller, $plan);
            if ($pricing !== null) {
                $availablePlans[] = [
                    'plan' => $plan,
                    'pricing' => $pricing,
                ];
            }
        }

        return $availablePlans;
    }
}
