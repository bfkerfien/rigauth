<?php

return [
    'enabled' => env('SEAT_ASSETMGR_ENABLED', false),
    'corporation_sync_enabled' => env('SEAT_ASSETMGR_CORPORATION_SYNC_ENABLED', false),
    'allowed_corporation_ids' => array_values(array_filter(array_map(
        'intval', explode(',', (string) env('SEAT_ASSETMGR_CORPORATION_IDS', ''))
    ), static fn (int $id): bool => $id > 0)),

    'sync' => [
        'enabled' => env('SEAT_ASSETMGR_SYNC_ENABLED', false),
        'queue' => env('SEAT_ASSETMGR_QUEUE', 'default'),
        'minimum_interval_seconds' => (int) env('SEAT_ASSETMGR_MIN_SYNC_INTERVAL', 900),
        'requests_per_minute' => (int) env('SEAT_ASSETMGR_REQUESTS_PER_MINUTE', 30),
    ],

    'esi' => [
        'base_url' => env('SEAT_ASSETMGR_ESI_BASE_URL', 'https://esi.evetech.net'),
        'user_agent' => env('SEAT_ASSETMGR_USER_AGENT', 'Triglavian-Asset-Manager'),
        'compatibility_date' => env('SEAT_ASSETMGR_ESI_COMPATIBILITY_DATE', '2026-08-18'),
    ],

    'pricing' => [
        'jita_region_id' => 10000002,
        'stale_after_seconds' => (int) env('SEAT_ASSETMGR_PRICE_STALE_SECONDS', 3600),
    ],
];
