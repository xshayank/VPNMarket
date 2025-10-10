<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{

    public function store(Plan $plan)
    {
        $order = Auth::user()->orders()->create([
            'plan_id' => $plan->id,
            'status' => 'pending',
            'expires_at' => now()->addMonth(),
        ]);


        return redirect()->route('order.show', $order->id);
    }


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


    public function processCardPayment(Order $order)
    {
        $order->update(['payment_method' => 'card']);


        $settings = Setting::all()->pluck('value', 'key');


        return view('payment.card-receipt', [
            'order' => $order,
            'settings' => $settings,
        ]);
    }


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
        $newOrder->save();


        return redirect()->route('order.show', $newOrder->id)->with('status', 'سفارش تمدید شما ایجاد شد. لطفاً هزینه را پرداخت کنید.');
    }


    public function submitCardReceipt(Request $request, Order $order)
    {
        $request->validate([
            'receipt' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $path = $request->file('receipt')->store('receipts', 'public');

        $order->update(['card_payment_receipt' => $path]);

        return redirect()->route('dashboard')->with('status', 'رسید شما با موفقیت ارسال شد. پس از تایید توسط مدیر، سرویس شما فعال خواهد شد.');
    }


    public function processCryptoPayment(Order $order)
    {

        $order->update(['payment_method' => 'crypto']);


        return redirect()
            ->back()
            ->with('status', '💡 پرداخت با ارز دیجیتال به زودی فعال می‌شود. لطفاً از روش کارت به کارت استفاده کنید.');
    }
}
