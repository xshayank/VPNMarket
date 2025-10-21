<?php

namespace Modules\Reseller\Services;

use App\Models\Panel;
use App\Models\Plan;
use App\Models\Reseller;
use App\Models\Setting;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use App\Services\XUIService;
use Illuminate\Support\Facades\Log;

class ResellerProvisioner
{
    /**
     * Create a username following the reseller naming convention
     */
    public function generateUsername(Reseller $reseller, string $type, int $id, ?int $index = null): string
    {
        $prefix = $reseller->username_prefix ?? Setting::where('key', 'reseller.username_prefix')->value('value') ?? 'resell';

        if ($type === 'order') {
            return "{$prefix}_{$reseller->id}_order_{$id}_{$index}";
        } elseif ($type === 'config') {
            return "{$prefix}_{$reseller->id}_cfg_{$id}";
        }

        return "{$prefix}_{$reseller->id}_{$type}_{$id}";
    }

    /**
     * Provision a user on a panel
     */
    public function provisionUser(Panel $panel, Plan $plan, string $username, array $options = []): ?array
    {
        try {
            $credentials = $panel->getCredentials();

            switch ($panel->panel_type) {
                case 'marzban':
                    return $this->provisionMarzban($credentials, $plan, $username, $options);

                case 'marzneshin':
                    return $this->provisionMarzneshin($credentials, $plan, $username, $options);

                case 'xui':
                    return $this->provisionXUI($credentials, $plan, $username, $options);

                default:
                    Log::error("Unknown panel type: {$panel->panel_type}");

                    return null;
            }
        } catch (\Exception $e) {
            Log::error("Failed to provision user on panel {$panel->id}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Provision user on Marzban panel
     */
    protected function provisionMarzban(array $credentials, Plan $plan, string $username, array $options): ?array
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';

        $service = new MarzbanService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );

        if (! $service->login()) {
            return null;
        }

        $expiresAt = $options['expires_at'] ?? now()->addDays($plan->duration_days);
        $trafficLimit = $options['traffic_limit_bytes'] ?? ($plan->volume_gb * 1024 * 1024 * 1024);

        $result = $service->createUser([
            'username' => $username,
            'expire' => $expiresAt->timestamp,
            'data_limit' => $trafficLimit,
        ]);

        if ($result && isset($result['subscription_url'])) {
            $subscriptionUrl = $service->buildAbsoluteSubscriptionUrl($result);

            return [
                'username' => $username,
                'subscription_url' => $subscriptionUrl,
                'panel_type' => 'marzban',
                'panel_user_id' => $username,
            ];
        }

        return null;
    }

    /**
     * Provision user on Marzneshin panel
     */
    protected function provisionMarzneshin(array $credentials, Plan $plan, string $username, array $options): ?array
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';

        $service = new MarzneshinService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );

        if (! $service->login()) {
            return null;
        }

        $expiresAt = $options['expires_at'] ?? now()->addDays($plan->duration_days);
        $trafficLimit = $options['traffic_limit_bytes'] ?? ($plan->volume_gb * 1024 * 1024 * 1024);
        $serviceIds = $options['service_ids'] ?? $plan->marzneshin_service_ids ?? [];

        // Prepare user data array for MarzneshinService::createUser()
        $userData = [
            'username' => $username,
            'expire' => $expiresAt->getTimestamp(),
            'data_limit' => $trafficLimit,
            'service_ids' => (array) $serviceIds,
        ];

        $result = $service->createUser($userData);

        if ($result && isset($result['subscription_url'])) {
            $subscriptionUrl = $service->buildAbsoluteSubscriptionUrl($result);

            return [
                'username' => $username,
                'subscription_url' => $subscriptionUrl,
                'panel_type' => 'marzneshin',
                'panel_user_id' => $username,
            ];
        }

        return null;
    }

    /**
     * Provision user on X-UI panel
     */
    protected function provisionXUI(array $credentials, Plan $plan, string $username, array $options): ?array
    {
        $service = new XUIService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password']
        );

        if (! $service->login()) {
            return null;
        }

        $expiresAt = $options['expires_at'] ?? now()->addDays($plan->duration_days);
        $trafficLimit = $options['traffic_limit_bytes'] ?? ($plan->volume_gb * 1024 * 1024 * 1024);

        $result = $service->createUser(
            $username,
            $trafficLimit,
            $expiresAt->timestamp
        );

        if ($result) {
            return [
                'username' => $username,
                'subscription_url' => $result['subscription_url'] ?? null,
                'panel_type' => 'xui',
                'panel_user_id' => $result['user_id'] ?? $username,
            ];
        }

        return null;
    }

    /**
     * Disable a user on a panel
     */
    public function disableUser(string $panelType, array $credentials, string $panelUserId): bool
    {
        try {
            switch ($panelType) {
                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, ['status' => 'disabled']);
                    }
                    break;

                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        // For Marzneshin, use the dedicated disable endpoint
                        $result = $service->disableUser($panelUserId);

                        return $result;
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, ['enable' => false]);
                    }
                    break;
            }
        } catch (\Exception $e) {
            Log::error("Failed to disable user {$panelUserId}: ".$e->getMessage());
        }

        return false;
    }

    /**
     * Enable a user on a panel
     */
    public function enableUser(string $panelType, array $credentials, string $panelUserId): bool
    {
        try {
            switch ($panelType) {
                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, ['status' => 'active']);
                    }
                    break;

                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        // For Marzneshin, use the dedicated enable endpoint
                        $result = $service->enableUser($panelUserId);

                        return $result;
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, ['enable' => true]);
                    }
                    break;
            }
        } catch (\Exception $e) {
            Log::error("Failed to enable user {$panelUserId}: ".$e->getMessage());
        }

        return false;
    }

    /**
     * Enable a config on its panel (convenience wrapper for enableUser)
     */
    public function enableConfig(\App\Models\ResellerConfig $config): bool
    {
        if (! $config->panel_id || ! $config->panel_user_id) {
            Log::warning("Cannot enable config {$config->id}: missing panel_id or panel_user_id");

            return false;
        }

        $panel = Panel::find($config->panel_id);
        if (! $panel) {
            Log::warning("Cannot enable config {$config->id}: panel not found");

            return false;
        }

        try {
            $credentials = $panel->getCredentials();

            return $this->enableUser($config->panel_type, $credentials, $config->panel_user_id);
        } catch (\Exception $e) {
            Log::warning("Failed to enable config {$config->id}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Delete a user on a panel
     */
    public function deleteUser(string $panelType, array $credentials, string $panelUserId): bool
    {
        try {
            switch ($panelType) {
                case 'marzban':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzbanService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->deleteUser($panelUserId);
                    }
                    break;

                case 'marzneshin':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new MarzneshinService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password'],
                        $nodeHostname
                    );
                    if ($service->login()) {
                        return $service->deleteUser($panelUserId);
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        return $service->deleteUser($panelUserId);
                    }
                    break;
            }
        } catch (\Exception $e) {
            Log::error("Failed to delete user {$panelUserId}: ".$e->getMessage());
        }

        return false;
    }
}
