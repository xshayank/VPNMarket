<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Eylandoo Panel Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for Eylandoo panel integration.
    |
    */
    'eylandoo' => [
        /*
        | Default Node IDs
        |
        | These node IDs are used as fallback when no nodes are fetched from
        | the Eylandoo API. This ensures resellers always have nodes to select
        | from when creating configs, even if the panel has no configured nodes
        | or the API is temporarily unavailable.
        |
        | Default: [1, 2]
        */
        'default_node_ids' => env('EYLANDOO_DEFAULT_NODE_IDS', [1, 2]),
    ],
];
