<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\JWTAuth;

class JwtAuthenticate
{
    protected JWTAuth $jwt;

    public function __construct(JWTAuth $jwt)
    {
        $this->jwt = $jwt;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            $user = $this->jwt->parseToken()->authenticate();

            if (!$user) {
                return ApiResponse::error('User not found.', 404, ['error' => 'user_not_found']);
            }

            $request->merge(['auth_user' => $user]);
        } catch (TokenExpiredException $e) {
            return ApiResponse::error('Token expired.', 401, ['error' => 'token_expired']);
        } catch (TokenInvalidException $e) {
            return ApiResponse::error('Invalid token.', 401, ['error' => 'token_invalid']);
        } catch (JWTException $e) {
            return ApiResponse::error('Token absent.', 401, ['error' => 'token_absent']);
        } catch (Exception $e) {
            return ApiResponse::error('Unauthorized.', 401, ['error' => 'unauthorized']);
        }

        return $next($request);
    }
}
