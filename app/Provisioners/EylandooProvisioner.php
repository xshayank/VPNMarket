<?php

namespace App\Provisioners;

use App\Models\Panel;
use App\Models\ResellerConfig;
use App\Services\EylandooService;
use Illuminate\Support\Facades\Log;

class EylandooProvisioner extends BaseProvisioner
{
    /**
     * Enable a config on Eylandoo panel
     *
     * @param  ResellerConfig  $config  The config to enable
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function enableConfig(ResellerConfig $config): array
    {
        // Validate required fields
        if (! $config->panel_id || ! $config->panel_user_id) {
            $error = 'Missing panel_id or panel_user_id';
            Log::warning("Cannot enable Eylandoo config {$config->id}: {$error}", [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
                'panel_user_id' => $config->panel_user_id,
            ]);

            return ['success' => false, 'attempts' => 0, 'last_error' => $error];
        }

        // Load panel
        $panel = Panel::find($config->panel_id);
        if (! $panel) {
            $error = 'Panel not found';
            Log::warning("Cannot enable Eylandoo config {$config->id}: {$error}", [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
            ]);

            return ['success' => false, 'attempts' => 0, 'last_error' => $error];
        }

        // Get credentials
        try {
            $credentials = $panel->getCredentials();
        } catch (\Exception $e) {
            $error = 'Failed to get panel credentials: '.$e->getMessage();
            Log::error("Cannot enable Eylandoo config {$config->id}: {$error}", [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
            ]);

            return ['success' => false, 'attempts' => 0, 'last_error' => $error];
        }

        // Validate Eylandoo credentials
        if (empty($credentials['url']) || empty($credentials['api_token'])) {
            $error = 'Missing Eylandoo credentials (url or api_token)';
            Log::warning("Cannot enable Eylandoo config {$config->id}: {$error}", [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
                'has_url' => ! empty($credentials['url']),
                'has_api_token' => ! empty($credentials['api_token']),
            ]);

            return ['success' => false, 'attempts' => 0, 'last_error' => $error];
        }

        // Enable user with retry logic
        return $this->retryOperation(function () use ($credentials, $config) {
            $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
            $service = new EylandooService(
                $credentials['url'],
                $credentials['api_token'],
                $nodeHostname
            );

            $result = $service->enableUser($config->panel_user_id);

            // Log detailed result
            Log::info('Eylandoo enable user result', [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
                'panel_user_id' => $config->panel_user_id,
                'success' => $result,
                'base_url' => $credentials['url'],
            ]);

            return $result;
        }, "enable Eylandoo config {$config->id} (reseller:{$config->reseller_id}, panel:{$config->panel_id})");
    }

    /**
     * Disable a config on Eylandoo panel
     *
     * @param  ResellerConfig  $config  The config to disable
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function disableConfig(ResellerConfig $config): array
    {
        // Validate required fields
        if (! $config->panel_id || ! $config->panel_user_id) {
            $error = 'Missing panel_id or panel_user_id';
            Log::warning("Cannot disable Eylandoo config {$config->id}: {$error}", [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
                'panel_user_id' => $config->panel_user_id,
            ]);

            return ['success' => false, 'attempts' => 0, 'last_error' => $error];
        }

        // Load panel
        $panel = Panel::find($config->panel_id);
        if (! $panel) {
            $error = 'Panel not found';
            Log::warning("Cannot disable Eylandoo config {$config->id}: {$error}", [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
            ]);

            return ['success' => false, 'attempts' => 0, 'last_error' => $error];
        }

        // Get credentials
        try {
            $credentials = $panel->getCredentials();
        } catch (\Exception $e) {
            $error = 'Failed to get panel credentials: '.$e->getMessage();
            Log::error("Cannot disable Eylandoo config {$config->id}: {$error}", [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
            ]);

            return ['success' => false, 'attempts' => 0, 'last_error' => $error];
        }

        // Validate Eylandoo credentials
        if (empty($credentials['url']) || empty($credentials['api_token'])) {
            $error = 'Missing Eylandoo credentials (url or api_token)';
            Log::warning("Cannot disable Eylandoo config {$config->id}: {$error}", [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
                'has_url' => ! empty($credentials['url']),
                'has_api_token' => ! empty($credentials['api_token']),
            ]);

            return ['success' => false, 'attempts' => 0, 'last_error' => $error];
        }

        // Disable user with retry logic
        return $this->retryOperation(function () use ($credentials, $config) {
            $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
            $service = new EylandooService(
                $credentials['url'],
                $credentials['api_token'],
                $nodeHostname
            );

            $result = $service->disableUser($config->panel_user_id);

            // Log detailed result
            Log::info('Eylandoo disable user result', [
                'config_id' => $config->id,
                'reseller_id' => $config->reseller_id,
                'panel_id' => $config->panel_id,
                'panel_user_id' => $config->panel_user_id,
                'success' => $result,
                'base_url' => $credentials['url'],
            ]);

            return $result;
        }, "disable Eylandoo config {$config->id} (reseller:{$config->reseller_id}, panel:{$config->panel_id})");
    }
}
