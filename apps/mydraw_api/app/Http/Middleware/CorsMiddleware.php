<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    /**
     * Manipula CORS para permitir que o frontend (Vite) consuma a API.
     */
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->headers->get('Origin', '*');

        if ($request->getMethod() === 'OPTIONS') {
            return response('', 204, $this->headers($origin));
        }

        /** @var Response $response */
        $response = $next($request);

        foreach ($this->headers($origin) as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }

    private function headers(string $origin): array
    {
        return [
            'Access-Control-Allow-Origin'      => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, Accept, X-Requested-With, X-CSRF-TOKEN',
            'Access-Control-Max-Age'           => '86400',
        ];
    }
}
