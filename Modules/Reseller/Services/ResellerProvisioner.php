<?php

namespace Modules\Reseller\Services;

use App\Models\Panel;
use App\Models\Plan;
use App\Models\Reseller;
use App\Models\Setting;
use App\Services\EylandooService;
use App\Services\MarzbanService;
use App\Services\MarzneshinService;
use App\Services\XUIService;
use Illuminate\Support\Facades\Log;

class ResellerProvisioner
{
    /**
     * Retry an operation with exponential backoff
     * Attempts: 0s, 1s, 3s (3 total attempts)
     *
     * @param  callable  $operation  The operation to retry (should return bool)
     * @param  string  $description  Description for logging (no sensitive data)
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    protected function retryOperation(callable $operation, string $description): array
    {
        $maxAttempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Exponential backoff: 0s, 1s, 3s
                if ($attempt > 1) {
                    $delay = pow(2, $attempt - 2); // 2^0=1, 2^1=2, but we want 1, 3
                    if ($attempt == 2) {
                        $delay = 1;
                    } else {
                        $delay = 3;
                    }
                    usleep($delay * 1000000); // Convert to microseconds
                }

                $result = $operation();

                if ($result) {
                    return [
                        'success' => true,
                        'attempts' => $attempt,
                        'last_error' => null,
                    ];
                }

                $lastError = 'Operation returned false';
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::warning("Attempt {$attempt}/{$maxAttempts} to {$description} failed: {$lastError}");
            }
        }

        Log::error("All {$maxAttempts} attempts to {$description} failed. Last error: {$lastError}");

        return [
            'success' => false,
            'attempts' => $maxAttempts,
            'last_error' => $lastError,
        ];
    }

    /**
     * Apply rate limiting with micro-sleeps to evenly distribute operations
     * Rate: 3 operations per second
     *
     * @param  int  $operationCount  Current operation count (0-indexed)
     */
    public function applyRateLimit(int $operationCount): void
    {
        if ($operationCount > 0) {
            // 3 ops/sec = 333ms between operations
            usleep(333333); // 333.333 milliseconds
        }
    }

    /**
     * Create a username following the reseller naming convention
     *
     * @param  Reseller  $reseller  The reseller instance
     * @param  string  $type  Type of resource ('order', 'config', etc.)
     * @param  int  $id  The resource ID
     * @param  int|null  $index  Optional index (for orders)
     * @param  string|null  $customPrefix  Optional custom prefix to use for this specific config
     * @param  string|null  $customName  Optional custom name that completely overrides the generator
     * @return string The generated username
     */
    public function generateUsername(Reseller $reseller, string $type, int $id, ?int $index = null, ?string $customPrefix = null, ?string $customName = null): string
    {
        // If custom name is provided, use it directly (overrides everything)
        if ($customName) {
            return $customName;
        }

        // Use custom prefix if provided, otherwise fall back to reseller's default prefix
        $prefix = $customPrefix ?? $reseller->username_prefix ?? Setting::where('key', 'reseller.username_prefix')->value('value') ?? 'resell';

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

                case 'eylandoo':
                    return $this->provisionEylandoo($credentials, $plan, $username, $options);

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
     * Provision user on Eylandoo panel
     */
    protected function provisionEylandoo(array $credentials, Plan $plan, string $username, array $options): ?array
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';

        $service = new EylandooService(
            $credentials['url'],
            $credentials['api_token'],
            $nodeHostname
        );

        $expiresAt = $options['expires_at'] ?? now()->addDays($plan->duration_days);
        $trafficLimit = $options['traffic_limit_bytes'] ?? ($plan->volume_gb * 1024 * 1024 * 1024);
        $maxClients = $options['max_clients'] ?? $options['connections'] ?? 1;
        $nodes = $options['nodes'] ?? [];

        $result = $service->createUser([
            'username' => $username,
            'expire' => $expiresAt->timestamp,
            'data_limit' => $trafficLimit,
            'max_clients' => $maxClients,
            'nodes' => $nodes,
        ]);

        if ($result && isset($result['data'])) {
            $subscriptionUrl = $service->buildAbsoluteSubscriptionUrl($result);

            return [
                'username' => $username,
                'subscription_url' => $subscriptionUrl,
                'panel_type' => 'eylandoo',
                'panel_user_id' => $username,
            ];
        }

        return null;
    }

    /**
     * Disable a user on a panel with retry logic
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function disableUser(string $panelType, array $credentials, string $panelUserId): array
    {
        return $this->retryOperation(function () use ($panelType, $credentials, $panelUserId) {
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
                        return $service->disableUser($panelUserId);
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

                case 'eylandoo':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );
                    return $service->disableUser($panelUserId);
            }

            return false;
        }, "disable user {$panelUserId}");
    }

    /**
     * Enable a user on a panel with retry logic
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function enableUser(string $panelType, array $credentials, string $panelUserId): array
    {
        return $this->retryOperation(function () use ($panelType, $credentials, $panelUserId) {
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
                        return $service->enableUser($panelUserId);
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

                case 'eylandoo':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );
                    return $service->enableUser($panelUserId);
            }

            return false;
        }, "enable user {$panelUserId}");
    }

    /**
     * Enable a config on its panel (convenience wrapper for enableUser)
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function enableConfig(\App\Models\ResellerConfig $config): array
    {
        if (! $config->panel_id || ! $config->panel_user_id) {
            Log::warning("Cannot enable config {$config->id}: missing panel_id or panel_user_id");

            return ['success' => false, 'attempts' => 0, 'last_error' => 'Missing panel_id or panel_user_id'];
        }

        $panel = Panel::find($config->panel_id);
        if (! $panel) {
            Log::warning("Cannot enable config {$config->id}: panel not found");

            return ['success' => false, 'attempts' => 0, 'last_error' => 'Panel not found'];
        }

        try {
            $credentials = $panel->getCredentials();

            return $this->enableUser($panel->panel_type, $credentials, $config->panel_user_id);
        } catch (\Exception $e) {
            Log::warning("Failed to enable config {$config->id}: ".$e->getMessage());

            return ['success' => false, 'attempts' => 0, 'last_error' => $e->getMessage()];
        }
    }

    /**
     * Disable a config on its panel (convenience wrapper for disableUser)
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function disableConfig(\App\Models\ResellerConfig $config): array
    {
        if (! $config->panel_id || ! $config->panel_user_id) {
            Log::warning("Cannot disable config {$config->id}: missing panel_id or panel_user_id");

            return ['success' => false, 'attempts' => 0, 'last_error' => 'Missing panel_id or panel_user_id'];
        }

        $panel = Panel::find($config->panel_id);
        if (! $panel) {
            Log::warning("Cannot disable config {$config->id}: panel not found");

            return ['success' => false, 'attempts' => 0, 'last_error' => 'Panel not found'];
        }

        try {
            $credentials = $panel->getCredentials();

            return $this->disableUser($panel->panel_type, $credentials, $config->panel_user_id);
        } catch (\Exception $e) {
            Log::warning("Failed to disable config {$config->id}: ".$e->getMessage());

            return ['success' => false, 'attempts' => 0, 'last_error' => $e->getMessage()];
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

                case 'eylandoo':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );
                    return $service->deleteUser($panelUserId);
            }
        } catch (\Exception $e) {
            Log::error("Failed to delete user {$panelUserId}: ".$e->getMessage());
        }

        return false;
    }

    /**
     * Update user limits (traffic and expiry) on a panel with retry logic
     *
     * @param  \Carbon\Carbon  $expiresAt
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function updateUserLimits(string $panelType, array $credentials, string $panelUserId, int $trafficLimitBytes, $expiresAt): array
    {
        return $this->retryOperation(function () use ($panelType, $credentials, $panelUserId, $trafficLimitBytes, $expiresAt) {
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
                        return $service->updateUser($panelUserId, [
                            'data_limit' => $trafficLimitBytes,
                            'expire' => $expiresAt->timestamp,
                        ]);
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
                        return $service->updateUser($panelUserId, [
                            'data_limit' => $trafficLimitBytes,
                            'expire' => $expiresAt->getTimestamp(),
                        ]);
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        return $service->updateUser($panelUserId, [
                            'total' => $trafficLimitBytes,
                            'expiryTime' => $expiresAt->timestamp * 1000, // X-UI uses milliseconds
                        ]);
                    }
                    break;

                case 'eylandoo':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );
                    return $service->updateUser($panelUserId, [
                        'data_limit' => $trafficLimitBytes,
                        'expire' => $expiresAt->timestamp,
                    ]);
            }

            return false;
        }, "update user limits for {$panelUserId}");
    }

    /**
     * Reset user usage on a panel with retry logic
     *
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function resetUserUsage(string $panelType, array $credentials, string $panelUserId): array
    {
        return $this->retryOperation(function () use ($panelType, $credentials, $panelUserId) {
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
                        // Reset usage by setting used_traffic to 0
                        return $service->resetUserUsage($panelUserId);
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
                        return $service->resetUserUsage($panelUserId);
                    }
                    break;

                case 'xui':
                    $service = new XUIService(
                        $credentials['url'],
                        $credentials['username'],
                        $credentials['password']
                    );
                    if ($service->login()) {
                        // X-UI resets usage by setting up and down to 0
                        return $service->resetUserUsage($panelUserId);
                    }
                    break;

                case 'eylandoo':
                    $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                    $service = new EylandooService(
                        $credentials['url'],
                        $credentials['api_token'],
                        $nodeHostname
                    );
                    return $service->resetUserUsage($panelUserId);
            }

            return false;
        }, "reset user usage for {$panelUserId}");
    }
}
