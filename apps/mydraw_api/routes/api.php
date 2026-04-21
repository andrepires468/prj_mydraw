<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| API Routes (prefixo /api — registrado em bootstrap/app.php)
|--------------------------------------------------------------------------
| Todas as rotas ficam sob /api/v1/...
*/

$router->group(['prefix' => 'v1'], function () use ($router) {
    // Health check / rota de exemplo
    $router->get('/ping', 'PingController');

    // Autenticação
    $router->group(['prefix' => 'auth'], function () use ($router) {
        $router->post('/register', 'AuthController@register');
        $router->post('/login',    'AuthController@login');

        $router->group(['middleware' => 'auth.jwt'], function () use ($router) {
            $router->get('/me',      'AuthController@me');
            $router->post('/logout', 'AuthController@logout');
        });
    });

    // Galeria de desenhos
    $router->group(['prefix' => 'drawings'], function () use ($router) {
        // Públicas
        $router->get('/',     'DrawingController@index');
        $router->get('/{id}', 'DrawingController@show');

        // Autenticadas
        $router->group(['middleware' => 'auth.jwt'], function () use ($router) {
            $router->post('/',         'DrawingController@store');
            $router->delete('/{id}',   'DrawingController@destroy');
        });
    });

    // Posts (listagem pública com imagens da Gallery)
    $router->group(['prefix' => 'posts'], function () use ($router) {
        $router->get('/',     'PostController@index');
        $router->get('/{id}', 'PostController@show');
    });

    // Criação de post (draft UUID + galeria)
    $router->group(['prefix' => 'create-post'], function () use ($router) {
        // Diagnóstico (só respondido se APP_DEBUG=true)
        $router->get('/storage-check', 'CreatePostController@storageCheck');

        $router->group(['middleware' => 'auth.jwt'], function () use ($router) {
            $router->post('/upload', 'CreatePostController@upload');
            $router->post('/',       'CreatePostController@store');
        });
    });
});
