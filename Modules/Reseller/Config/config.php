<?php

return [
    'username_prefix' => env('RESELLER_USERNAME_PREFIX', 'resell'),
    'bulk_max_quantity' => env('RESELLER_BULK_MAX_QUANTITY', 50),
    'configs_max_active' => env('RESELLER_CONFIGS_MAX_ACTIVE', 50),
    'usage_sync_interval_minutes' => env('RESELLER_USAGE_SYNC_INTERVAL', 5),
];
