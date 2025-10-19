<?php

namespace App\Services;

use App\Models\Inbound;
use App\Models\Order;
use App\Models\Panel;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ProvisioningService
{
    /**
     * Provision or extend a user's subscription
     * 
     * @param User $user
     * @param Plan $plan
     * @param Order $order
     * @param bool $isRenewal
     * @return array ['success' => bool, 'config' => string|null, 'order' => Order|null, 'message' => string|null]
     */
    public function provisionOrExtend(User $user, Plan $plan, Order $order, bool $isRenewal = false): array
    {
        // Check if user is a reseller - resellers don't get extension logic
        if ($user->isReseller()) {
            return $this->provisionNew($user, $plan, $order, $isRenewal);
        }

        // For normal users, check if we should extend an existing config
        $existingOrder = $this->findExtendableOrder($user, $plan);
        
        if ($existingOrder && $existingOrder->canBeExtended()) {
            return $this->extendExisting($existingOrder, $plan, $order);
        }

        // Check if user has an active config that cannot be extended (>3 days remaining)
        if ($existingOrder && !$existingOrder->canBeExtended()) {
            return [
                'success' => false,
                'config' => null,
                'order' => null,
                'message' => 'شما در حال حاضر یک اشتراک فعال دارید. تمدید فقط در 3 روز آخر قبل از انقضا یا پس از اتمام ترافیک امکان‌پذیر است. برای خرید اشتراک جدید، لطفاً منتظر بمانید تا اشتراک فعلی به پایان برسد.',
            ];
        }

        // No existing order or not eligible for extension - create new
        return $this->provisionNew($user, $plan, $order, $isRenewal);
    }

    /**
     * Find an extendable order for the user and plan
     */
    protected function findExtendableOrder(User $user, Plan $plan): ?Order
    {
        return Order::where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->where('status', 'paid')
            ->whereNotNull('config_details')
            ->orderBy('expires_at', 'desc')
            ->first();
    }

    /**
     * Extend an existing order
     */
    protected function extendExisting(Order $existingOrder, Plan $plan, Order $newOrder): array
    {
        try {
            $panel = $plan->panel;
            if (!$panel) {
                throw new \Exception('هیچ پنلی به این پلن مرتبط نیست.');
            }

            $credentials = $panel->getCredentials();
            $username = $this->generateUsername($existingOrder->user_id, $existingOrder->id, false);

            // Determine new expiry and traffic based on whether the order is expired/no traffic
            if ($existingOrder->isExpiredOrNoTraffic()) {
                // Reset: start from now
                $newExpiresAt = now()->addDays($plan->duration_days);
            } else {
                // Extend: add to existing expiry
                $newExpiresAt = $existingOrder->expires_at->addDays($plan->duration_days);
            }

            $newTrafficLimit = $plan->volume_gb * 1024 * 1024 * 1024;

            // Update on panel
            $updateResult = $this->updateUserOnPanel(
                $panel,
                $username,
                $newExpiresAt,
                $newTrafficLimit
            );

            if (!$updateResult['success']) {
                throw new \Exception($updateResult['message'] ?? 'خطا در به‌روزرسانی کاربر در پنل');
            }

            // Update the existing order
            $existingOrder->update([
                'expires_at' => $newExpiresAt,
                'traffic_limit_bytes' => $newTrafficLimit,
                'usage_bytes' => 0, // Reset usage
            ]);

            // Mark the new order as paid but reference the existing config
            $newOrder->update([
                'status' => 'paid',
                'config_details' => $existingOrder->config_details,
                'expires_at' => $newExpiresAt,
                'traffic_limit_bytes' => $newTrafficLimit,
                'usage_bytes' => 0,
                'renews_order_id' => $existingOrder->id,
            ]);

            return [
                'success' => true,
                'config' => $existingOrder->config_details,
                'order' => $existingOrder,
                'message' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Extension failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return [
                'success' => false,
                'config' => null,
                'order' => null,
                'message' => 'خطا در تمدید سرویس: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Provision a new user (original flow)
     */
    protected function provisionNew(User $user, Plan $plan, Order $order, bool $isRenewal): array
    {
        try {
            $panel = $plan->panel;
            if (!$panel) {
                throw new \Exception('هیچ پنلی به این پلن مرتبط نیست.');
            }

            $credentials = $panel->getCredentials();
            $panelType = $panel->panel_type;
            
            $username = $this->generateUsername(
                $user->id,
                $isRenewal ? $order->renews_order_id : $order->id,
                $isRenewal
            );

            $newExpiresAt = $isRenewal
                ? (new \DateTime(Order::find($order->renews_order_id)->expires_at))->modify("+{$plan->duration_days} days")
                : now()->addDays($plan->duration_days);

            $trafficLimit = $plan->volume_gb * 1024 * 1024 * 1024;

            $finalConfig = null;
            $success = false;

            if ($panelType === 'marzban') {
                $result = $this->provisionMarzban($credentials, $plan, $username, $newExpiresAt, $trafficLimit, $isRenewal);
                if ($result) {
                    $finalConfig = $result['config'];
                    $success = true;
                }
            } elseif ($panelType === 'marzneshin') {
                $result = $this->provisionMarzneshin($credentials, $plan, $username, $newExpiresAt, $trafficLimit, $isRenewal);
                if ($result) {
                    $finalConfig = $result['config'];
                    $success = true;
                }
            } elseif ($panelType === 'xui') {
                if ($isRenewal) {
                    throw new \Exception('تمدید خودکار برای پنل سنایی هنوز پیاده‌سازی نشده است.');
                }
                $result = $this->provisionXUI($credentials, $plan, $username, $newExpiresAt, $trafficLimit);
                if ($result) {
                    $finalConfig = $result['config'];
                    $success = true;
                }
            }

            if (!$success) {
                throw new \Exception('خطا در ارتباط با سرور برای فعال‌سازی سرویس.');
            }

            // Update order
            if ($isRenewal) {
                $originalOrder = Order::find($order->renews_order_id);
                $originalOrder->update([
                    'config_details' => $finalConfig,
                    'expires_at' => $newExpiresAt,
                    'traffic_limit_bytes' => $trafficLimit,
                    'usage_bytes' => 0,
                ]);
                return [
                    'success' => true,
                    'config' => $finalConfig,
                    'order' => $originalOrder,
                    'message' => null,
                ];
            } else {
                $order->update([
                    'config_details' => $finalConfig,
                    'expires_at' => $newExpiresAt,
                    'traffic_limit_bytes' => $trafficLimit,
                    'usage_bytes' => 0,
                ]);
                return [
                    'success' => true,
                    'config' => $finalConfig,
                    'order' => $order,
                    'message' => null,
                ];
            }
        } catch (\Exception $e) {
            Log::error('Provisioning failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return [
                'success' => false,
                'config' => null,
                'order' => null,
                'message' => 'خطا در فعال‌سازی سرویس: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Update user on panel
     */
    protected function updateUserOnPanel(Panel $panel, string $username, $expiresAt, int $trafficLimit): array
    {
        try {
            $credentials = $panel->getCredentials();
            
            switch ($panel->panel_type) {
                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    
                    if (!$service->login()) {
                        return ['success' => false, 'message' => 'خطا در احراز هویت با پنل'];
                    }
                    
                    $result = $service->updateUser($username, [
                        'expire' => $expiresAt->timestamp,
                        'data_limit' => $trafficLimit,
                    ]);
                    
                    return ['success' => $result !== null, 'message' => null];

                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    
                    if (!$service->login()) {
                        return ['success' => false, 'message' => 'خطا در احراز هویت با پنل'];
                    }
                    
                    $result = $service->updateUser($username, [
                        'expire' => $expiresAt->getTimestamp(),
                        'data_limit' => $trafficLimit,
                    ]);
                    
                    // Marzneshin updateUser returns boolean
                    return ['success' => $result, 'message' => null];

                case 'xui':
                    // XUI doesn't support update well, return failure to trigger fallback
                    return ['success' => false, 'message' => 'پنل XUI از به‌روزرسانی پشتیبانی نمی‌کند'];

                default:
                    return ['success' => false, 'message' => 'نوع پنل ناشناخته'];
            }
        } catch (\Exception $e) {
            Log::error('Update user on panel failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Generate username for config
     */
    protected function generateUsername(int $userId, int $orderId, bool $isRenewal): string
    {
        return "user_{$userId}_order_" . ($isRenewal ? $orderId : $orderId);
    }

    /**
     * Provision user on Marzban
     */
    protected function provisionMarzban(array $credentials, Plan $plan, string $username, $expiresAt, int $trafficLimit, bool $isRenewal): ?array
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
        $service = new MarzbanService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );

        $userData = [
            'expire' => $expiresAt->getTimestamp(),
            'data_limit' => $trafficLimit,
        ];

        $response = $isRenewal
            ? $service->updateUser($username, $userData)
            : $service->createUser(array_merge($userData, ['username' => $username]));

        if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
            return ['config' => $service->generateSubscriptionLink($response)];
        }

        return null;
    }

    /**
     * Provision user on Marzneshin
     */
    protected function provisionMarzneshin(array $credentials, Plan $plan, string $username, $expiresAt, int $trafficLimit, bool $isRenewal): ?array
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
        $service = new MarzneshinService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );

        $userData = [
            'expire' => $expiresAt->getTimestamp(),
            'data_limit' => $trafficLimit,
        ];

        // Add plan-specific service_ids if available
        if ($plan->marzneshin_service_ids && is_array($plan->marzneshin_service_ids) && count($plan->marzneshin_service_ids) > 0) {
            $userData['service_ids'] = $plan->marzneshin_service_ids;
        }

        $response = $isRenewal
            ? $service->updateUser($username, $userData)
            : $service->createUser(array_merge($userData, ['username' => $username]));

        if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
            return ['config' => $service->generateSubscriptionLink($response)];
        }

        return null;
    }

    /**
     * Provision user on XUI
     */
    protected function provisionXUI(array $credentials, Plan $plan, string $username, $expiresAt, int $trafficLimit): ?array
    {
        $xuiService = new XUIService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password']
        );
        
        $defaultInboundId = $credentials['extra']['default_inbound_id'] ?? null;
        $inbound = $defaultInboundId ? Inbound::find($defaultInboundId) : null;
        
        if (!$inbound || !$inbound->inbound_data) {
            throw new \Exception('اطلاعات اینباند پیش‌فرض برای X-UI یافت نشد.');
        }
        
        if (!$xuiService->login()) {
            throw new \Exception('خطا در لاگین به پنل X-UI.');
        }

        $inboundData = json_decode($inbound->inbound_data, true);
        $clientData = [
            'email' => $username,
            'total' => $trafficLimit,
            'expiryTime' => $expiresAt->timestamp * 1000
        ];
        
        $response = $xuiService->addClient($inboundData['id'], $clientData);

        if ($response && isset($response['success']) && $response['success']) {
            $linkType = $credentials['extra']['link_type'] ?? 'single';
            if ($linkType === 'subscription') {
                $subId = $response['generated_subId'];
                $subBaseUrl = rtrim($credentials['extra']['subscription_url_base'] ?? '', '/');
                if ($subBaseUrl) {
                    return ['config' => $subBaseUrl . '/sub/' . $subId];
                }
            } else {
                $uuid = $response['generated_uuid'];
                $streamSettings = json_decode($inboundData['streamSettings'], true);
                $parsedUrl = parse_url($credentials['url']);
                $serverIpOrDomain = !empty($inboundData['listen']) ? $inboundData['listen'] : $parsedUrl['host'];
                $port = $inboundData['port'];
                $remark = $inboundData['remark'];
                $paramsArray = [
                    'type' => $streamSettings['network'] ?? null,
                    'security' => $streamSettings['security'] ?? null,
                    'path' => $streamSettings['wsSettings']['path'] ?? ($streamSettings['grpcSettings']['serviceName'] ?? null),
                    'sni' => $streamSettings['tlsSettings']['serverName'] ?? null,
                    'host' => $streamSettings['wsSettings']['headers']['Host'] ?? null
                ];
                $params = http_build_query(array_filter($paramsArray));
                $fullRemark = $username . '|' . $remark;
                return ['config' => "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#" . urlencode($fullRemark)];
            }
        }

        throw new \Exception('خطا در ساخت کاربر در پنل سنایی: ' . ($response['msg'] ?? 'پاسخ نامعتبر'));
    }
}
