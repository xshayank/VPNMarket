<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\MarzbanService;
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
        $request->validate([
            'amount' => 'required|numeric|min:10000',
        ]);

        $order = Auth::user()->orders()->create([
            'plan_id' => null,
            'amount' => $request->amount,
            'status' => 'pending',
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
        $request->validate([
            'receipt' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $path = $request->file('receipt')->store('receipts', 'public');
        $order->update(['card_payment_receipt' => $path]);

        return redirect()->route('dashboard')->with('status', 'رسید شما با موفقیت ارسال شد. پس از تایید توسط مدیر، سرویس شما فعال خواهد شد.');
    }

    /**
     * Process instant payment from the user's wallet balance.
     */
    public function processWalletPayment(Order $order)
    {
        if (auth()->id() !== $order->user_id) { abort(403); }
        if (!$order->plan) { return redirect()->back()->with('error', 'این عملیات برای شارژ کیف پول مجاز نیست.'); }

        $user = auth()->user();
        $plan = $order->plan;
        $price = $plan->price;

        if ($user->balance < $price) {
            return redirect()->back()->with('error', 'موجودی کیف پول شما برای انجام این عملیات کافی نیست.');
        }

        try {
            DB::transaction(function () use ($order, $user, $plan, $price) {
                $user->decrement('balance', $price);

                $settings = Setting::all()->pluck('value', 'key');
                $marzbanService = new MarzbanService(
                    $settings->get('marzban_host'), $settings->get('marzban_sudo_username'),
                    $settings->get('marzban_sudo_password'), $settings->get('marzban_node_hostname')
                );

                $success = false;

                if ($order->renews_order_id) {
                    $originalOrder = Order::find($order->renews_order_id);
                    $username = "user-{$originalOrder->user_id}-order-{$originalOrder->id}";
                    $newExpiresAt = (new \DateTime($originalOrder->expires_at))->modify("+{$plan->duration_days} days");
                    $userData = ['expire' => $newExpiresAt->getTimestamp(), 'data_limit' => $plan->volume_gb * 1073741824];
                    $response = $marzbanService->updateUser($username, $userData);

                    if ($response && isset($response['subscription_url'])) {
                        $config = $marzbanService->generateSubscriptionLink($response);
                        $originalOrder->update(['config_details' => $config, 'expires_at' => $newExpiresAt->format('Y-m-d H:i:s')]);
                        $user->update(['show_renewal_notification' => true]);
                        $success = true;
                    }
                } else {
                    $uniqueUsername = "user-{$user->id}-order-{$order->id}";
                    $userData = ['username' => $uniqueUsername, 'data_limit' => $plan->volume_gb * 1073741824, 'expire' => now()->addDays($plan->duration_days)->timestamp];
                    $response = $marzbanService->createUser($userData);

                    if ($response && isset($response['username'])) {
                        $config = $marzbanService->generateSubscriptionLink($response);
                        $order->update(['config_details' => $config, 'expires_at' => now()->addDays($plan->duration_days)]);
                        $success = true;
                    }
                }

                if (!$success) { throw new \Exception('خطا در ارتباط با سرور برای فعال‌سازی سرویس.'); }

                $order->update(['status' => 'paid', 'payment_method' => 'wallet']);
                Transaction::create([
                    'user_id' => $user->id, 'order_id' => $order->id, 'amount' => $price,
                    'type' => Transaction::TYPE_PURCHASE, 'status' => Transaction::STATUS_COMPLETED,
                    'description' => ($order->renews_order_id ? "تمدید سرویس" : "خرید سرویس") . " {$plan->name} از کیف پول",
                ]);

                // این خط رویداد پرداخت موفق را برای سیستم دعوت از دوستان منتشر می‌کند
                OrderPaid::dispatch($order);
            });
        } catch (\Exception $e) {
            Log::error('Wallet Payment Failed: ' . $e->getMessage());
            return redirect()->route('dashboard')->with('error', 'پرداخت با خطا مواجه شد. لطفاً با پشتیبانی تماس بگیرید.');
        }

        return redirect()->route('dashboard')->with('status', 'سرویس شما با موفقیت فعال شد.');
    }

    public function processCryptoPayment(Order $order)
    {
        $order->update(['payment_method' => 'crypto']);
        return redirect()
            ->back()
            ->with('status', '💡 پرداخت با ارز دیجیتال به زودی فعال می‌شود. لطفاً از روش کارت به کارت استفاده کنید.');
    }
}

