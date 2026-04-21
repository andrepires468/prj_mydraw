<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Throwable;
use Tymon\JWTAuth\JWTAuth;

/**
 * Autenticação JWT opcional.
 *
 * Diferente do {@see JwtAuthenticate}, este middleware **não rejeita** a
 * requisição quando o token está ausente, expirado ou inválido: apenas deixa
 * o controller seguir sem `auth_user`. Útil em endpoints majoritariamente
 * públicos que têm comportamento adicional quando o usuário está logado
 * (ex.: listar drafts do próprio usuário ou retornar contagens personalizadas).
 */
class JwtAuthenticateOptional
{
    protected JWTAuth $jwt;

    public function __construct(JWTAuth $jwt)
    {
        $this->jwt = $jwt;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            if ($this->jwt->setRequest($request)->getToken()) {
                $user = $this->jwt->authenticate();
                if ($user) {
                    $request->merge(['auth_user' => $user]);
                }
            }
        } catch (Throwable $e) {
            // Silencia falhas: token ausente/expirado/inválido apenas significa
            // que o request segue como anônimo.
        }

        return $next($request);
    }
}
