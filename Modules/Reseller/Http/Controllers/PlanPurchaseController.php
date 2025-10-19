<?php

namespace Modules\Reseller\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ResellerOrder;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Reseller\Jobs\ProvisionResellerOrderJob;
use Modules\Reseller\Services\ResellerPricingService;

class PlanPurchaseController extends Controller
{
    protected ResellerPricingService $pricingService;

    public function __construct(ResellerPricingService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    public function index(Request $request)
    {
        $reseller = $request->user()->reseller;

        if (!$reseller->isPlanBased()) {
            return redirect()->route('reseller.dashboard')
                ->with('error', 'This feature is only available for plan-based resellers.');
        }

        $availablePlans = $this->pricingService->getAvailablePlans($reseller);

        return view('reseller::plans.index', [
            'reseller' => $reseller,
            'plans' => $availablePlans,
            'max_quantity' => Setting::where('key', 'reseller.bulk_max_quantity')->value('value') ?? 50,
        ]);
    }

    public function store(Request $request)
    {
        $reseller = $request->user()->reseller;

        if (!$reseller->isPlanBased()) {
            return back()->with('error', 'This feature is only available for plan-based resellers.');
        }

        $maxQuantity = Setting::where('key', 'reseller.bulk_max_quantity')->value('value') ?? 50;

        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:plans,id',
            'quantity' => "required|integer|min:1|max:{$maxQuantity}",
            'delivery_mode' => 'required|in:download,onscreen',
        ], [
            'quantity.required' => 'مقدار الزامی است.',
            'quantity.integer' => 'مقدار باید یک عدد صحیح باشد.',
            'quantity.min' => 'مقدار باید حداقل 1 باشد.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $plan = Plan::findOrFail($request->plan_id);
        $pricing = $this->pricingService->calculatePrice($reseller, $plan);

        if (!$pricing) {
            return back()->with('error', 'This plan is not available for purchase.');
        }

        $quantity = $request->quantity;
        $unitPrice = $pricing['price'];
        $totalPrice = $unitPrice * $quantity;

        // Check balance
        if ($request->user()->balance < $totalPrice) {
            return back()->with('error', 'Insufficient balance. Please top up your wallet.');
        }

        DB::transaction(function () use ($request, $reseller, $plan, $quantity, $unitPrice, $totalPrice, $pricing) {
            // Deduct balance
            $request->user()->decrement('balance', $totalPrice);

            // Create order
            $order = ResellerOrder::create([
                'reseller_id' => $reseller->id,
                'plan_id' => $plan->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'price_source' => $pricing['source'],
                'delivery_mode' => $request->delivery_mode,
                'status' => 'paid',
            ]);

            // Queue provisioning job
            ProvisionResellerOrderJob::dispatch($order);

            session()->flash('success', 'Order created successfully. Provisioning is in progress...');
            session()->flash('order_id', $order->id);
        });

        return redirect()->route('reseller.orders.show', session('order_id'));
    }

    public function show(Request $request, ResellerOrder $order)
    {
        $reseller = $request->user()->reseller;

        if ($order->reseller_id !== $reseller->id) {
            abort(403, 'Unauthorized access to this order.');
        }

        return view('reseller::orders.show', [
            'reseller' => $reseller,
            'order' => $order->load('plan'),
        ]);
    }
}
