<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    /**
     * CORS para web (Vite), Capacitor WebView e clientes allowlisted.
     */
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->headers->get('Origin');
        $allowedOrigin = $this->resolveAllowedOrigin($origin);

        if ($request->getMethod() === 'OPTIONS') {
            return response('', 204, $this->headers($allowedOrigin));
        }

        /** @var Response $response */
        $response = $next($request);

        foreach ($this->headers($allowedOrigin) as $key => $value) {
            if ($value !== null) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }

    private function resolveAllowedOrigin(?string $origin): ?string
    {
        if ($origin === null || $origin === '') {
            return null;
        }

        $allowed = $this->allowedOrigins();

        foreach ($allowed as $candidate) {
            if ($this->originMatches($origin, $candidate)) {
                return $origin;
            }
        }

        /** Dev local: preserva LAN/Vite sem listar cada IP. */
        if (env('APP_ENV', 'production') === 'local') {
            return $origin;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        $fromEnv = env('CORS_ALLOWED_ORIGINS');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $fromEnv))));
        }

        $defaults = [
            'http://localhost:5173',
            'http://localhost:8088',
            'http://localhost',
            'https://localhost',
            'capacitor://localhost',
            'ionic://localhost',
            'http://192.168.86.122:5173',
            'http://192.168.86.122:8088',
        ];

        $appUrl = env('APP_URL');
        if (is_string($appUrl) && $appUrl !== '') {
            $defaults[] = rtrim($appUrl, '/');
        }

        $frontendUrl = env('FRONTEND_URL');
        if (is_string($frontendUrl) && $frontendUrl !== '') {
            $defaults[] = rtrim($frontendUrl, '/');
        }

        return array_values(array_unique($defaults));
    }

    private function originMatches(string $origin, string $allowed): bool
    {
        return rtrim($origin, '/') === rtrim($allowed, '/');
    }

    private function headers(?string $origin): array
    {
        $headers = [
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Authorization, Content-Type, Accept, X-Requested-With, X-CSRF-TOKEN',
            'Access-Control-Max-Age'           => '86400',
        ];

        if ($origin !== null) {
            $headers['Access-Control-Allow-Origin'] = $origin;
        }

        return $headers;
    }
}
