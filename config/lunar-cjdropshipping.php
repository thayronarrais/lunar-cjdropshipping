<?php

return [

    /*
    | Queue used by all importer jobs. Run a single worker on it:
    | php artisan queue:work --queue=cjdropshipping
    */
    'queue' => env('CJ_IMPORT_QUEUE', 'cjdropshipping'),

    /*
    | Maximum CJdropshipping API requests per second made by this package
    | (Free 1, Plus 2, Prime 4, Advanced 6). 0 disables throttling.
    */
    'requests_per_second' => (int) env('CJ_REQUESTS_PER_SECOND', 1),

    'schedule' => [
        'enabled' => (bool) env('CJ_SCHEDULE_ENABLED', true),
        'discover' => 'daily',
        'sync' => 'everySixHours',
    ],

    'sync' => [
        'stale_after_hours' => 6,
        'not_found_threshold' => 2,
        // 'out_of_stock' keeps the product published with zero stock; 'draft' also unpublishes it.
        'unavailable_action' => 'out_of_stock',
    ],

    'webhooks' => [
        'path' => 'cjdropshipping/webhook',
        'dedupe_ttl_hours' => 48,
    ],

    'media' => [
        // null uses config('lunar.media.collection').
        'collection' => null,
    ],

    'pricing' => [
        // Value of 1 USD in the store default currency; used only when no USD currency exists in Lunar.
        'usd_to_default_rate' => env('CJ_USD_TO_DEFAULT_RATE'),
    ],

    // null uses the application default log channel.
    'log_channel' => env('CJ_LOG_CHANNEL'),

];
