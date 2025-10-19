<?php

namespace App\Services;

use App\Models\Panel;
use App\Models\Plan;
use Illuminate\Support\Facades\Log;

class ProvisioningService
{
    /**
     * Update an existing user on the panel
     * 
     * @param Panel $panel The panel to update the user on
     * @param Plan $plan The plan containing the configuration details
     * @param string $username The username to update
     * @param array $options Additional options like expires_at timestamp, traffic_limit_bytes
     * @return array ['success' => bool, 'message' => string, 'response' => array|null]
     */
    public function updateUser(Panel $panel, Plan $plan, string $username, array $options): array
    {
        $panelType = $panel->panel_type;
        $credentials = $panel->getCredentials();
        
        $expiresTimestamp = $options['expires_at'] ?? now()->addDays($plan->duration_days)->timestamp;
        $trafficLimit = $options['traffic_limit_bytes'] ?? ($plan->volume_gb * 1024 * 1024 * 1024);
        
        try {
            if ($panelType === 'marzban') {
                return $this->updateMarzbanUser($credentials, $username, $expiresTimestamp, $trafficLimit);
            } elseif ($panelType === 'marzneshin') {
                return $this->updateMarzneshinUser($credentials, $plan, $username, $expiresTimestamp, $trafficLimit);
            } elseif ($panelType === 'xui') {
                return $this->updateXUIUser($credentials, $username, $expiresTimestamp, $trafficLimit, $options);
            }
            
            return [
                'success' => false,
                'message' => "Panel type '{$panelType}' is not supported for updates.",
                'response' => null,
            ];
        } catch (\Exception $e) {
            Log::error('ProvisioningService updateUser failed', [
                'panel_type' => $panelType,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to update user on panel: ' . $e->getMessage(),
                'response' => null,
            ];
        }
    }
    
    /**
     * Update user on Marzban panel
     */
    protected function updateMarzbanUser(array $credentials, string $username, int $expiresTimestamp, int $trafficLimit): array
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
        $marzbanService = new MarzbanService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );
        
        $userData = [
            'expire' => $expiresTimestamp,
            'data_limit' => $trafficLimit,
        ];
        
        $response = $marzbanService->updateUser($username, $userData);
        
        if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
            return [
                'success' => true,
                'message' => 'User updated successfully on Marzban panel.',
                'response' => $response,
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to update user on Marzban panel.',
            'response' => $response,
        ];
    }
    
    /**
     * Update user on Marzneshin panel
     */
    protected function updateMarzneshinUser(array $credentials, Plan $plan, string $username, int $expiresTimestamp, int $trafficLimit): array
    {
        $nodeHostname = $credentials['extra']['node_hostname'] ?? '';
        $marzneshinService = new MarzneshinService(
            $credentials['url'],
            $credentials['username'],
            $credentials['password'],
            $nodeHostname
        );
        
        $userData = [
            'expire' => $expiresTimestamp,
            'data_limit' => $trafficLimit,
        ];
        
        // Add plan-specific service_ids if available
        if ($plan->marzneshin_service_ids && is_array($plan->marzneshin_service_ids) && count($plan->marzneshin_service_ids) > 0) {
            $userData['service_ids'] = $plan->marzneshin_service_ids;
        }
        
        $success = $marzneshinService->updateUser($username, $userData);
        
        if ($success) {
            return [
                'success' => true,
                'message' => 'User updated successfully on Marzneshin panel.',
                'response' => ['username' => $username],
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to update user on Marzneshin panel.',
            'response' => null,
        ];
    }
    
    /**
     * Update user on X-UI panel
     * Note: X-UI doesn't have native update support, so we return an error
     */
    protected function updateXUIUser(array $credentials, string $username, int $expiresTimestamp, int $trafficLimit, array $options): array
    {
        // X-UI panel doesn't support user updates via API in most implementations
        // We would need inbound_id and client_id which are not readily available
        // The fallback is to inform that update is not supported
        return [
            'success' => false,
            'message' => 'X-UI panel does not support direct user updates. Please use delete and recreate workflow.',
            'response' => null,
        ];
    }
}
