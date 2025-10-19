<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\CouponService;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use App\Services\ProvisioningService;
use App\Services\XUIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Create a new pending order for a specific plan.
     */
    public function store(Plan $plan)
    {
        $order = Auth::user()->orders()->create([
            'plan_id' => $plan->id,
            'status' => 'pending',
            'source' => 'web',
        ]);

        return redirect()->route('order.show', $order->id);
    }

    /**
     * Show the payment method selection page for an order.
     */
    public function show(Order $order)
    {
        if (Auth::id() !== $order->user_id) {
            abort(403, 'شما به این صفحه دسترسی ندارید.');
        }

        if ($order->status === 'paid') {
            return redirect()->route('dashboard')->with('status', 'این سفارش قبلاً پرداخت شده است.');
        }

        return view('payment.show', ['order' => $order]);
    }

    /**
     * Show the bank card details and receipt upload form.
     */
    public function processCardPayment(Order $order)
    {
        $order->update(['payment_method' => 'card']);
        $settings = Setting::all()->pluck('value', 'key');

        return view('payment.card-receipt', [
            'order' => $order,
            'settings' => $settings,
        ]);
    }

    /**
     * Show the form to enter the wallet charge amount.
     */
    public function showChargeForm()
    {
        return view('wallet.charge');
    }

    /**
     * Create a new pending order for charging the wallet.
     */
    public function createChargeOrder(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:10000']);
        $order = Auth::user()->orders()->create([
            'plan_id' => null,
            'amount' => $request->amount,
            'status' => 'pending',
            'source' => 'web',
        ]);

        return redirect()->route('order.show', $order->id);
    }

    /**
     * Create a new pending order to renew an existing service.
     */
    public function renew(Order $order)
    {
        if (Auth::id() !== $order->user_id || $order->status !== 'paid') {
            abort(403);
        }

        $newOrder = $order->replicate();
        $newOrder->created_at = now();
        $newOrder->status = 'pending';
        $newOrder->source = 'web';
        $newOrder->config_details = null;
        $newOrder->expires_at = null;
        $newOrder->renews_order_id = $order->id;
        $newOrder->save();

        return redirect()->route('order.show', $newOrder->id)->with('status', 'سفارش تمدید شما ایجاد شد. لطفاً هزینه را پرداخت کنید.');
    }

    /**
     * Handle the submission of the payment receipt file.
     */
    public function submitCardReceipt(Request $request, Order $order)
    {
        $request->validate(['receipt' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048']);
        $path = $request->file('receipt')->store('receipts', 'public');
        $order->update(['card_payment_receipt' => $path]);

        return redirect()->route('dashboard')->with('status', 'رسید شما با موفقیت ارسال شد. پس از تایید توسط مدیر، سرویس شما فعال خواهد شد.');
    }

    /**
     * Process instant payment from the user's wallet balance.
     */
    public function processWalletPayment(Order $order)
    {
        if (auth()->id() !== $order->user_id) {
            abort(403);
        }
        if (! $order->plan) {
            return redirect()->back()->with('error', 'این عملیات برای شارژ کیف پول مجاز نیست.');
        }

        $user = auth()->user();
        $plan = $order->plan;
        // Use the order's amount if a coupon was applied, otherwise use the plan's price
        $price = $order->amount ?? $plan->price;

        if ($user->balance < $price) {
            return redirect()->back()->with('error', 'موجودی کیف پول شما برای انجام این عملیات کافی نیست.');
        }

        try {
            DB::transaction(function () use ($order, $user, $plan, $price) {
                $user->decrement('balance', $price);

                $isRenewal = (bool) $order->renews_order_id;

                // Use ProvisioningService to handle provisioning or extension
                $provisioningService = new ProvisioningService();
                $result = $provisioningService->provisionOrExtend($user, $plan, $order, $isRenewal);

                if (!$result['success']) {
                    throw new \Exception($result['message'] ?? 'خطا در ارتباط با سرور برای فعال‌سازی سرویس.');
                }

                // Update order status and payment method
                $order->update([
                    'status' => 'paid',
                    'payment_method' => 'wallet',
                ]);

                // Create transaction record
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'amount' => $price,
                    'type' => 'purchase',
                    'status' => 'completed',
                    'description' => ($isRenewal ? 'تمدید سرویس' : 'خرید سرویس') . " {$plan->name} از کیف پول"
                ]);

                // Increment promo code usage if applied
                if ($order->promo_code_id) {
                    $couponService = new CouponService;
                    $couponService->incrementUsage($order->promoCode);
                }

                // Set renewal notification if this was a renewal
                if ($isRenewal) {
                    $user->update(['show_renewal_notification' => true]);
                }

                OrderPaid::dispatch($order);
            });
        } catch (\Exception $e) {
            Log::error('Wallet Payment Failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return redirect()->route('dashboard')->with('error', 'پرداخت با خطا مواجه شد: '.$e->getMessage());
        }

        return redirect()->route('dashboard')->with('status', 'سرویس شما با موفقیت فعال شد.');
    }

    public function processCryptoPayment(Order $order)
    {
        $order->update(['payment_method' => 'crypto']);

        return redirect()->back()->with('status', '💡 پرداخت با ارز دیجیتال به زودی فعال می‌شود. لطفاً از روش کارت به کارت استفاده کنید.');
    }

    /**
     * Apply a coupon code to an order.
     */
    public function applyCoupon(Request $request, Order $order)
    {
        if (Auth::id() !== $order->user_id) {
            abort(403);
        }

        $request->validate([
            'coupon_code' => 'required|string|max:50',
        ]);

        $couponService = new CouponService;
        $result = $couponService->applyToOrder($order, $request->coupon_code);

        if (! $result['valid']) {
            return redirect()->back()->with('error', $result['message']);
        }

        return redirect()->back()->with('success', $result['message']);
    }

    /**
     * Remove a coupon code from an order.
     */
    public function removeCoupon(Order $order)
    {
        if (Auth::id() !== $order->user_id) {
            abort(403);
        }

        $couponService = new CouponService;
        $couponService->removeFromOrder($order);

        return redirect()->back()->with('status', 'کد تخفیف حذف شد.');
    }
}
