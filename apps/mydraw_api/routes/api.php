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
        $router->post('/google',   'AuthController@googleLogin');

        $router->group(['middleware' => 'auth.jwt'], function () use ($router) {
            $router->get('/me',            'AuthController@me');
            $router->put('/profile',      'AuthController@updateProfile');
            $router->put('/password',     'AuthController@updatePassword');
            $router->post('/google/link',   'AuthController@googleLink');
            $router->post('/google/unlink', 'AuthController@googleUnlink');
            $router->post('/logout',        'AuthController@logout');
        });
    });

    // Perfil público do editor (admin)
    $router->get('/editor/profile', 'EditorProfileController@show');

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

    // Posts (listagem pública com imagens da Gallery).
    //
    // Leituras: JWT opcional — endpoints são públicos mas personalizam a
    // resposta quando o token é enviado (drafts do próprio usuário, etc.).
    $router->group(['prefix' => 'posts', 'middleware' => 'auth.jwt.optional'], function () use ($router) {
        $router->get('/',       'PostController@index');
        $router->get('/{id}',   'PostController@show');
    });

    // Escritas em posts existentes: JWT obrigatório; o controller verifica
    // ainda que o recurso pertence ao usuário autenticado.
    $router->group(['prefix' => 'posts', 'middleware' => 'auth.jwt'], function () use ($router) {
        $router->put('/{id}',                             'EditPostController@update');
        $router->delete('/{id}',                          'EditPostController@destroy');
        $router->put('/{id}/images/reorder',            'EditPostController@reorderImages');
        $router->post('/{id}/images',                     'EditPostController@uploadImage');
        $router->post('/{id}/images/metadata',             'EditPostController@extractImageMetadata');
        $router->delete('/{id}/images/{galleryId}',       'EditPostController@destroyImage');
    });

    // Criação de post (draft UUID + galeria)
    $router->group(['prefix' => 'create-post'], function () use ($router) {
        // Diagnóstico (só respondido se APP_DEBUG=true)
        $router->get('/storage-check', 'CreatePostController@storageCheck');

        $router->group(['middleware' => 'auth.jwt'], function () use ($router) {
            // Mantém JWT explícito neste endpoint para evitar regressão caso o
            // agrupamento seja alterado no futuro.
            $router->post('/upload', [
                'middleware' => 'auth.jwt',
                'uses'       => 'CreatePostController@upload',
            ]);
            $router->post('/',       'CreatePostController@store');
        });
    });
});
