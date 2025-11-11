<?php

return [
    'enabled' => env('STARSEFAR_ENABLE', false),
    'base_url' => env('STARSEFAR_BASE_URL', 'https://starsefar.xyz'),
    'api_key' => env('STARSEFAR_API_KEY'),
    'min_amount_toman' => 25000,
    'callback_path' => env('STARSEFAR_CALLBACK_PATH', '/webhooks/Stars-Callback'),
    'default_target_account' => env('STARSEFAR_DEFAULT_TARGET_ACCOUNT'),
];
