<?php

namespace Modules\Reseller\Http\Controllers;

use App\Models\Plan;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Modules\Reseller\Jobs\ProvisionResellerOrderJob;
use Modules\Reseller\Models\ResellerOrder;

class PlanPurchaseController
{
    public function index(): View
    {
        $user = Auth::user();
        $reseller = $user->reseller;

        abort_unless($reseller && $reseller->type === 'plan', 404);

        $reseller->load('allowedPlans');

        $plans = Plan::query()
            ->where('is_active', true)
            ->where('reseller_visible', true)
            ->with('panel')
            ->get()
            ->map(function (Plan $plan) use ($reseller) {
                $pricing = $reseller->resolvePlanPrice($plan);
                if (!$pricing) {
                    return null;
                }

                return [
                    'plan' => $plan,
                    'pricing' => $pricing,
                ];
            })
            ->filter()
            ->values();

        return view('reseller::plans.index', [
            'reseller' => $reseller,
            'plans' => $plans,
            'maxQuantity' => config('reseller.bulk_max_quantity'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $reseller = $user->reseller;

        abort_unless($reseller && $reseller->type === 'plan', 404);

        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'delivery_mode' => ['required', 'in:download,onscreen'],
        ]);

        $maxQuantity = (int) config('reseller.bulk_max_quantity', 50);
        if ($validated['quantity'] > $maxQuantity) {
            return Redirect::back()->withErrors([
                'quantity' => __('Quantity exceeds allowed maximum (:max).', ['max' => $maxQuantity]),
            ]);
        }

        $plan = Plan::findOrFail($validated['plan_id']);

        $reseller->loadMissing('allowedPlans');
        $pricing = $reseller->resolvePlanPrice($plan);

        if (!$pricing) {
            return Redirect::back()->withErrors([
                'plan_id' => __('Selected plan is not available.'),
            ]);
        }

        $unitPrice = $pricing['price'];
        $totalPrice = $unitPrice * $validated['quantity'];

        if ($user->balance < $totalPrice) {
            return Redirect::back()->withErrors([
                'balance' => __('Insufficient balance for this purchase.'),
            ]);
        }

        $order = DB::transaction(function () use ($user, $reseller, $plan, $validated, $unitPrice, $totalPrice, $pricing) {
            $user->decrement('balance', $totalPrice);

            return ResellerOrder::create([
                'reseller_id' => $reseller->getKey(),
                'plan_id' => $plan->getKey(),
                'quantity' => $validated['quantity'],
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'price_source' => $pricing['source'],
                'delivery_mode' => $validated['delivery_mode'],
                'status' => 'paid',
            ]);
        });

        ProvisionResellerOrderJob::dispatch($order);

        return Redirect::route('reseller.orders.show', $order)
            ->with('status', __('Bulk order queued for provisioning.'));
    }
}
