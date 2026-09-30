<?php

// config for Nave/IssSatellite
return [
    'oracle' => [
        'db' => [
            'driver'         => 'oracle',
            'tns'            => env('MS_SATELLITE_MEGA_DB_TNS', ''),

            // As variáveis serão passadas dinâmicamente
            // 'host'           => env('MS_SATELLITE_MEGA_DB_HOST', ''),
            // 'port'           => env('MS_SATELLITE_MEGA_DB_PORT', '1521'),
            // 'database'       => env('MS_SATELLITE_MEGA_DB_DATABASE', ''),
            // 'service_name'   => env('MS_SATELLITE_MEGA_DB_DATABASE', ''),
            // 'username'       => env('MS_SATELLITE_MEGA_DB_USERNAME', ''),
            // 'password'       => env('MS_SATELLITE_MEGA_DB_PASSWORD', ''),

            'charset'        => env('MS_SATELLITE_MEGA_DB_CHARSET', 'AL32UTF8'),
            'prefix'         => env('MS_SATELLITE_MEGA_DB_PREFIX', ''),
            'prefix_schema'  => env('MS_SATELLITE_MEGA_DB_SCHEMA_PREFIX', ''),
            'edition'        => env('MS_SATELLITE_MEGA_DB_EDITION', 'ora$base'),
            'server_version' => env('MS_SATELLITE_MEGA_DB_SERVER_VERSION', '11g'),
            'load_balance'   => env('MS_SATELLITE_MEGA_DB_LOAD_BALANCE', 'yes'),
            'dynamic'        => [],
            'max_name_len'   => env('MS_SATELLITE_MEGA_ORA_MAX_NAME_LEN', 30),
        ],
    ],

    'mega_cloud' => [
        'connect_timeout'    => env('MEGA_CLOUD_CONNECTION_TIMEOUT', 120),
        'timeout'            => env('MEGA_CLOUD_TIMEOUT', 120),
    ],
];
