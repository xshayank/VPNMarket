<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Setting;
use App\Services\CouponService;
use App\Services\ProvisioningService;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use App\Services\XUIService;
use App\Models\Inbound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionExtensionController extends Controller
{
    /**
     * Display the extension page for a subscription
     */
    public function show(Order $order)
    {
        if (Auth::id() !== $order->user_id) {
            abort(403, 'شما به این صفحه دسترسی ندارید.');
        }

        if ($order->status !== 'paid' || !$order->plan) {
            abort(404, 'سرویس یافت نشد.');
        }

        $eligibility = $this->checkEligibility($order);
        
        return view('subscriptions.extend', [
            'order' => $order,
            'eligibility' => $eligibility,
        ]);
    }

    /**
     * Process the extension payment and update the subscription
     */
    public function store(Request $request, Order $order)
    {
        if (Auth::id() !== $order->user_id) {
            abort(403);
        }

        if ($order->status !== 'paid' || !$order->plan) {
            return redirect()->route('dashboard')->with('error', 'سرویس یافت نشد.');
        }

        $eligibility = $this->checkEligibility($order);
        
        if (!$eligibility['allowed']) {
            return redirect()->route('dashboard')->with('error', $eligibility['message']);
        }

        $user = Auth::user();
        $plan = $order->plan;
        $price = $plan->price;

        // Check wallet balance
        if ($user->balance < $price) {
            return redirect()->route('wallet.charge.form')->with('error', 'موجودی کیف پول شما برای انجام این عملیات کافی نیست.');
        }

        try {
            DB::transaction(function () use ($order, $user, $plan, $price, $eligibility) {
                // Deduct from wallet
                $user->decrement('balance', $price);

                // Determine new expiration and traffic based on eligibility type
                if ($eligibility['type'] === 'extend') {
                    // Extend: add duration to existing expiration
                    $newExpiresAt = \Carbon\Carbon::parse($order->expires_at)->addDays($plan->duration_days);
                } else {
                    // Reset: start from now
                    $newExpiresAt = now()->addDays($plan->duration_days);
                }

                $newTrafficLimit = $plan->volume_gb * 1024 * 1024 * 1024;
                
                // Get panel from plan
                $panel = $plan->panel;
                if (!$panel) {
                    throw new \Exception('هیچ پنلی به این پلن مرتبط نیست.');
                }

                $panelType = $panel->panel_type;
                $credentials = $panel->getCredentials();
                
                // Try to update the user on the panel
                $provisioningService = new ProvisioningService();
                $username = $order->panel_user_id ?? "user_{$user->id}_order_{$order->id}";
                
                $updateResult = $provisioningService->updateUser($panel, $plan, $username, [
                    'expires_at' => $newExpiresAt->timestamp,
                    'traffic_limit_bytes' => $newTrafficLimit,
                ]);

                $finalConfig = $order->config_details;
                
                if ($updateResult['success']) {
                    // Update was successful
                    if ($panelType === 'marzban' && isset($updateResult['response'])) {
                        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
                        $marzbanService = new MarzbanService(
                            $credentials['url'],
                            $credentials['username'],
                            $credentials['password'],
                            $nodeHostname
                        );
                        $finalConfig = $marzbanService->generateSubscriptionLink($updateResult['response']);
                    } elseif ($panelType === 'marzneshin' && isset($updateResult['response'])) {
                        // For Marzneshin, we need to fetch the user to get the subscription URL
                        // since updateUser doesn't return it
                        // For now, keep the existing config
                    }
                } else {
                    // Update failed, try fallback: delete and recreate
                    Log::warning('Panel update failed, attempting recreate for extension', [
                        'order_id' => $order->id,
                        'panel_type' => $panelType,
                    ]);
                    
                    // Recreate user logic based on panel type
                    if ($panelType === 'marzban') {
                        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
                        $marzbanService = new MarzbanService(
                            $credentials['url'],
                            $credentials['username'],
                            $credentials['password'],
                            $nodeHostname
                        );
                        $userData = [
                            'username' => $username,
                            'expire' => $newExpiresAt->timestamp,
                            'data_limit' => $newTrafficLimit,
                        ];
                        
                        $response = $marzbanService->createUser($userData);
                        if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                            $finalConfig = $marzbanService->generateSubscriptionLink($response);
                        } else {
                            throw new \Exception('خطا در بازسازی کاربر در پنل Marzban.');
                        }
                    } elseif ($panelType === 'marzneshin') {
                        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
                        $marzneshinService = new MarzneshinService(
                            $credentials['url'],
                            $credentials['username'],
                            $credentials['password'],
                            $nodeHostname
                        );
                        $userData = [
                            'username' => $username,
                            'expire' => $newExpiresAt->timestamp,
                            'data_limit' => $newTrafficLimit,
                        ];
                        
                        if ($plan->marzneshin_service_ids && is_array($plan->marzneshin_service_ids) && count($plan->marzneshin_service_ids) > 0) {
                            $userData['service_ids'] = $plan->marzneshin_service_ids;
                        }
                        
                        $response = $marzneshinService->createUser($userData);
                        if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                            $finalConfig = $marzneshinService->generateSubscriptionLink($response);
                        } else {
                            throw new \Exception('خطا در بازسازی کاربر در پنل Marzneshin.');
                        }
                    } else {
                        throw new \Exception('پنل X-UI از تمدید خودکار پشتیبانی نمی‌کند.');
                    }
                }

                // Update the order
                $order->update([
                    'expires_at' => $newExpiresAt,
                    'traffic_limit_bytes' => $newTrafficLimit,
                    'usage_bytes' => 0,
                    'config_details' => $finalConfig,
                    'panel_user_id' => $username,
                ]);

                // Create a transaction record
                \App\Models\Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'amount' => $price,
                    'type' => 'purchase',
                    'status' => 'completed',
                    'description' => "تمدید سرویس {$plan->name} از کیف پول",
                ]);
            });

            return redirect()->route('dashboard')->with('status', 'سرویس شما با موفقیت تمدید شد. در صورت تغییر لینک، لطفاً لینک جدید را کپی و در نرم‌افزار خود آپدیت کنید.');
        } catch (\Exception $e) {
            Log::error('Extension Failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return redirect()->route('dashboard')->with('error', 'تمدید با خطا مواجه شد: ' . $e->getMessage());
        }
    }

    /**
     * Check if an order is eligible for extension
     */
    protected function checkEligibility(Order $order): array
    {
        $now = now();
        $expiresAt = \Carbon\Carbon::parse($order->expires_at);
        $daysRemaining = $now->diffInDays($expiresAt, false);
        
        $usageBytes = $order->usage_bytes ?? 0;
        $trafficLimit = $order->traffic_limit_bytes ?? ($order->plan->volume_gb * 1024 * 1024 * 1024);
        
        // Check if expired
        if ($expiresAt->lte($now)) {
            return [
                'allowed' => true,
                'type' => 'reset',
                'message' => 'سرویس شما منقضی شده است. با تمدید، سرویس از اکنون شروع خواهد شد.',
            ];
        }
        
        // Check if out of traffic
        if ($usageBytes >= $trafficLimit) {
            return [
                'allowed' => true,
                'type' => 'reset',
                'message' => 'ترافیک شما تمام شده است. با تمدید، ترافیک و زمان از اکنون تنظیم می‌شود.',
            ];
        }
        
        // Check if within 3 days of expiration
        if ($daysRemaining <= 3 && $daysRemaining >= 0) {
            return [
                'allowed' => true,
                'type' => 'extend',
                'message' => 'می‌توانید سرویس خود را تمدید کنید. زمان به تاریخ انقضای فعلی اضافه می‌شود.',
            ];
        }
        
        // Not eligible
        return [
            'allowed' => false,
            'type' => null,
            'message' => 'شما فقط می‌توانید در 3 روز پایانی قبل از انقضا یا پس از اتمام ترافیک، سرویس را تمدید کنید. برای خرید اشتراک جدید، از بخش "خرید سرویس جدید" اقدام کنید.',
        ];
    }
}
