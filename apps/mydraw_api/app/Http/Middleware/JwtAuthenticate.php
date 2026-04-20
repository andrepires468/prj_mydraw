<?php

namespace App\Http\Middleware;

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
                return response()->json(['error' => 'user_not_found'], 404);
            }

            $request->merge(['auth_user' => $user]);
        } catch (TokenExpiredException $e) {
            return response()->json(['error' => 'token_expired'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['error' => 'token_invalid'], 401);
        } catch (JWTException $e) {
            return response()->json(['error' => 'token_absent'], 401);
        } catch (Exception $e) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
