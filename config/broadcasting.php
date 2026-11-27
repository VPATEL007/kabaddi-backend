<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    | "reverb" pushes the broadcaster live feed over WebSocket (Laravel Reverb).
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            // Where THIS backend sends events. Point it at the local Reverb process
            // (e.g. 127.0.0.1:8080 http) to skip the public proxy and save time.
            'options' => [
                'host' => env('REVERB_BACKEND_HOST', env('REVERB_HOST')),
                'port' => env('REVERB_BACKEND_PORT', env('REVERB_PORT', 443)),
                'scheme' => env('REVERB_BACKEND_SCHEME', env('REVERB_SCHEME', 'https')),
                'useTLS' => env('REVERB_BACKEND_SCHEME', env('REVERB_SCHEME', 'https')) === 'https',
            ],
            'client_options' => [
                'timeout' => 2,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
