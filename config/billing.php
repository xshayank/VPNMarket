<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Wallet-based Reseller Billing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for wallet-based reseller hourly billing system.
    |
    */

    'wallet' => [
        /*
         * Default price per GB for wallet-based resellers (in تومان)
         * This is used when a reseller doesn't have a custom price override
         */
        'price_per_gb' => env('WALLET_PRICE_PER_GB', 780),

        /*
         * Suspension threshold (in تومان)
         * When a wallet-based reseller's balance drops to or below this value,
         * their account will be suspended and all configs disabled
         */
        'suspension_threshold' => env('WALLET_SUSPENSION_THRESHOLD', -1000),
    ],

    'reseller' => [
        /*
         * Minimum user wallet balance required to auto-upgrade a user to a wallet-based reseller (in تومان)
         */
        'min_wallet_upgrade' => env('RESELLER_MIN_WALLET_UPGRADE', 100000),
    ],
];
