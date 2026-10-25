<?php

return [
    /*
     * Integration must be verified against the deployed SeAT version before
     * enabling routes. All reads are from the local snapshot database.
     */
    'enabled' => env('SEAT_ASSETMGR_ENABLED', false),

    'sync' => [
        'enabled' => env('SEAT_ASSETMGR_SYNC_ENABLED', false),
        'queue' => env('SEAT_ASSETMGR_QUEUE', 'default'),
        'minimum_interval_seconds' => (int) env('SEAT_ASSETMGR_MIN_SYNC_INTERVAL', 900),
    ],

    'pricing' => [
        'jita_region_id' => 10000002,
        'stale_after_seconds' => (int) env('SEAT_ASSETMGR_PRICE_STALE_SECONDS', 3600),
    ],
];
