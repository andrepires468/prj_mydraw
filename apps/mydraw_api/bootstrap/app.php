<?php

use App\Support\ApiResponse;

require_once __DIR__.'/../vendor/autoload.php';

(new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(
    dirname(__DIR__)
))->bootstrap();

date_default_timezone_set(env('APP_TIMEZONE', 'America/Sao_Paulo'));

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

$app = new Laravel\Lumen\Application(
    dirname(__DIR__)
);

$app->withFacades();
$app->withEloquent();

/*
|--------------------------------------------------------------------------
| Register Container Bindings
|--------------------------------------------------------------------------
*/

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

/*
|--------------------------------------------------------------------------
| Register Config Files
|--------------------------------------------------------------------------
*/

$app->configure('app');
$app->configure('auth');
$app->configure('jwt');
$app->configure('cache');
$app->configure('database');
$app->configure('filesystems');

/*
|--------------------------------------------------------------------------
| Register Middleware
|--------------------------------------------------------------------------
*/

$app->middleware([
    App\Http\Middleware\CorsMiddleware::class,
]);

$app->routeMiddleware([
    'auth'              => App\Http\Middleware\Authenticate::class,
    'auth.jwt'          => App\Http\Middleware\JwtAuthenticate::class,
    'auth.jwt.optional' => App\Http\Middleware\JwtAuthenticateOptional::class,
]);

/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
*/

$app->register(Illuminate\Filesystem\FilesystemServiceProvider::class);
$app->register(Illuminate\Cache\CacheServiceProvider::class);
$app->register(App\Providers\AppServiceProvider::class);
$app->register(App\Providers\AuthServiceProvider::class);
$app->register(Tymon\JWTAuth\Providers\LumenServiceProvider::class);

/*
|--------------------------------------------------------------------------
| Load The Application Routes
|--------------------------------------------------------------------------
*/

$app->router->group([
    'namespace' => 'App\Http\Controllers',
    'prefix'    => 'api',
], function ($router) {
    require __DIR__.'/../routes/api.php';
});

$app->router->get('/', function () use ($app) {
    return ApiResponse::success([
        'name'    => config('app.name', 'mydraw'),
        'version' => $app->version(),
        'status'  => 'ok',
    ]);
});

return $app;
