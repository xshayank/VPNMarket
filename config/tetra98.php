<?php

return [
    'enabled' => env('TETRA98_ENABLED', false),
    'base_url' => env('TETRA98_BASE_URL', 'https://tetra98.ir'),
    'api_key' => env('TETRA98_API_KEY'),
    'callback_path' => env('TETRA98_CALLBACK_PATH', '/webhooks/tetra98/callback'),
    'default_description' => 'شارژ کیف پول از طریق Tetra98',
    'min_amount_toman' => 10000,
];
