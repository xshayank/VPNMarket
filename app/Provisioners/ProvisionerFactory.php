<?php

namespace App\Provisioners;

use App\Models\Panel;
use App\Models\ResellerConfig;
use Illuminate\Support\Facades\Log;

class ProvisionerFactory
{
    /**
     * Get the appropriate provisioner for a given config
     *
     * @param  ResellerConfig  $config  The config to provision
     * @return ProvisionerInterface The provisioner instance
     *
     * @throws \InvalidArgumentException If panel type is unknown
     */
    public static function forConfig(ResellerConfig $config): ProvisionerInterface
    {
        // Determine panel type from config's panel or fall back to config's panel_type field
        $panelType = null;

        if ($config->panel_id) {
            $panel = Panel::find($config->panel_id);
            if ($panel) {
                $panelType = $panel->panel_type;
            }
        }

        // Fallback to config's panel_type field if panel not found
        if (! $panelType && $config->panel_type) {
            $panelType = $config->panel_type;
        }

        if (! $panelType) {
            Log::error('Cannot determine panel type for config', [
                'config_id' => $config->id,
                'panel_id' => $config->panel_id,
                'config_panel_type' => $config->panel_type,
            ]);
            throw new \InvalidArgumentException("Cannot determine panel type for config {$config->id}");
        }

        return self::forPanelType($panelType);
    }

    /**
     * Get the appropriate provisioner for a given panel type
     *
     * @param  string  $panelType  The panel type (e.g., 'eylandoo', 'marzban')
     * @return ProvisionerInterface The provisioner instance
     *
     * @throws \InvalidArgumentException If panel type is unknown
     */
    public static function forPanelType(string $panelType): ProvisionerInterface
    {
        return match (strtolower($panelType)) {
            'eylandoo' => new EylandooProvisioner,
            'marzban' => new MarzbanProvisioner,
            'marzneshin' => new MarzneshinProvisioner,
            'xui' => new XUIProvisioner,
            default => throw new \InvalidArgumentException("Unknown panel type: {$panelType}"),
        };
    }
}
