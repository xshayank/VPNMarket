<?php

namespace App\Provisioners;

use App\Models\Panel;
use App\Models\ResellerConfig;
use App\Services\XUIService;
use Illuminate\Support\Facades\Log;

class XUIProvisioner extends BaseProvisioner
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
                $service = new XUIService(
                    $credentials['url'],
                    $credentials['username'],
                    $credentials['password']
                );

                if (! $service->login()) {
                    return false;
                }

                return $service->updateUser($config->panel_user_id, ['enable' => true]);
            }, "enable XUI config {$config->id}");
        } catch (\Exception $e) {
            Log::warning("Failed to enable XUI config {$config->id}: ".$e->getMessage());

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
                $service = new XUIService(
                    $credentials['url'],
                    $credentials['username'],
                    $credentials['password']
                );

                if (! $service->login()) {
                    return false;
                }

                return $service->updateUser($config->panel_user_id, ['enable' => false]);
            }, "disable XUI config {$config->id}");
        } catch (\Exception $e) {
            Log::warning("Failed to disable XUI config {$config->id}: ".$e->getMessage());

            return ['success' => false, 'attempts' => 0, 'last_error' => $e->getMessage()];
        }
    }
}
