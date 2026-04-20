<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // O guard "api" é resolvido pelo tymon/jwt-auth via config/auth.php.
        // Nenhum binding adicional é necessário aqui.
    }
}
