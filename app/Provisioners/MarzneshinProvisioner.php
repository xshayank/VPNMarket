<?php

namespace App\Provisioners;

use App\Models\Panel;
use App\Models\ResellerConfig;
use App\Services\MarzneshinService;
use Illuminate\Support\Facades\Log;

class MarzneshinProvisioner extends BaseProvisioner
{
    public function enableConfig(ResellerConfig $config): array
    {
        if (! $config->panel_id || ! $config->panel_user_id) {
            return ['success' => false, 'attempts' => 0, 'last_error' => 'Missing panel_id or panel_user_id'];
        }

        $panel = Panel::find($config->panel_id);
        if (! $panel) {
            return ['success' => false, 'attempts' => 0, 'last_error' => 'Panel not found'];
        }

        try {
            $credentials = $panel->getCredentials();

            return $this->retryOperation(function () use ($credentials, $config) {
                $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                $service = new MarzneshinService(
                    $credentials['url'],
                    $credentials['username'],
                    $credentials['password'],
                    $nodeHostname
                );

                if (! $service->login()) {
                    return false;
                }

                return $service->enableUser($config->panel_user_id);
            }, "enable Marzneshin config {$config->id}");
        } catch (\Exception $e) {
            Log::warning("Failed to enable Marzneshin config {$config->id}: ".$e->getMessage());

            return ['success' => false, 'attempts' => 0, 'last_error' => $e->getMessage()];
        }
    }

    public function disableConfig(ResellerConfig $config): array
    {
        if (! $config->panel_id || ! $config->panel_user_id) {
            return ['success' => false, 'attempts' => 0, 'last_error' => 'Missing panel_id or panel_user_id'];
        }

        $panel = Panel::find($config->panel_id);
        if (! $panel) {
            return ['success' => false, 'attempts' => 0, 'last_error' => 'Panel not found'];
        }

        try {
            $credentials = $panel->getCredentials();

            return $this->retryOperation(function () use ($credentials, $config) {
                $nodeHostname = $credentials['extra']['node_hostname'] ?? $credentials['node_hostname'] ?? '';
                $service = new MarzneshinService(
                    $credentials['url'],
                    $credentials['username'],
                    $credentials['password'],
                    $nodeHostname
                );

                if (! $service->login()) {
                    return false;
                }

                return $service->disableUser($config->panel_user_id);
            }, "disable Marzneshin config {$config->id}");
        } catch (\Exception $e) {
            Log::warning("Failed to disable Marzneshin config {$config->id}: ".$e->getMessage());

            return ['success' => false, 'attempts' => 0, 'last_error' => $e->getMessage()];
        }
    }
}
