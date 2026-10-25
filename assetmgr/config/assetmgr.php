<?php

return [
    'enabled' => env('SEAT_ASSETMGR_ENABLED', false),

    'sync' => [
        'enabled' => env('SEAT_ASSETMGR_SYNC_ENABLED', false),
        'queue' => env('SEAT_ASSETMGR_QUEUE', 'default'),
        'minimum_interval_seconds' => (int) env('SEAT_ASSETMGR_MIN_SYNC_INTERVAL', 900),
    ],

    'esi' => [
        'base_url' => env('SEAT_ASSETMGR_ESI_BASE_URL', 'https://esi.evetech.net/latest'),
        'user_agent' => env('SEAT_ASSETMGR_USER_AGENT', 'Triglavian-Asset-Manager'),
    ],

    'pricing' => [
        'jita_region_id' => 10000002,
        'stale_after_seconds' => (int) env('SEAT_ASSETMGR_PRICE_STALE_SECONDS', 3600),
    ],
];
