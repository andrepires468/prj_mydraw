<?php

return [
    'name'     => env('APP_NAME', 'mydraw'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo'),
    'locale'   => env('APP_LOCALE', 'pt_BR'),
    'fallback_locale' => 'en',
    'key'      => env('APP_KEY'),
    'cipher'   => 'AES-256-CBC',
];
