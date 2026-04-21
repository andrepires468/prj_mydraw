<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DRIVER', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app'),
        ],

        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => env('APP_URL').'/storage',
            'visibility' => 'public',
        ],

        // MinIO (S3-compatível)
        's3' => [
            'driver'                  => 's3',
            'key'                     => env('AWS_ACCESS_KEY_ID'),
            'secret'                  => env('AWS_SECRET_ACCESS_KEY'),
            'region'                  => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket'                  => env('AWS_BUCKET'),
            'endpoint'                => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', true),
            // Dispara exceções em falha de I/O, em vez de retornar false silenciosamente
            'throw'                   => true,
            'visibility'              => 'public',
            // Timeout de conexão/leitura com o endpoint S3 (segundos)
            'http' => [
                'connect_timeout' => (int) env('AWS_CONNECT_TIMEOUT', 5),
                'timeout'         => (int) env('AWS_TIMEOUT', 30),
                // Quando o MinIO remoto usa TLS self-signed, defina AWS_VERIFY_SSL=false
                'verify'          => env('AWS_VERIFY_SSL', true),
            ],
        ],
    ],

    'links' => [
        base_path('public/storage') => storage_path('app/public'),
    ],
];
