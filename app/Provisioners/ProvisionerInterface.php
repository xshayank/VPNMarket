<?php

namespace App\Provisioners;

use App\Models\ResellerConfig;

interface ProvisionerInterface
{
    /**
     * Enable a config on its remote panel
     *
     * @param  ResellerConfig  $config  The config to enable
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function enableConfig(ResellerConfig $config): array;

    /**
     * Disable a config on its remote panel
     *
     * @param  ResellerConfig  $config  The config to disable
     * @return array ['success' => bool, 'attempts' => int, 'last_error' => ?string]
     */
    public function disableConfig(ResellerConfig $config): array;
}
