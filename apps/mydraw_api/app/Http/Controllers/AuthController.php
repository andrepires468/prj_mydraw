<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $this->validate($request, [
            'name'     => 'required|string|max:200',
            'email'    => 'required|email|max:200|unique:users,Email',
            'password' => 'required|string|min:6|max:100',
        ]);

        $user = User::create([
            'Name'     => $request->input('name'),
            'Email'    => $request->input('email'),
            'Password' => Hash::make($request->input('password')),
        ]);

        $token = Auth::login($user);

        return $this->tokenResponse($token, $user, 201);
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $this->validate($request, [
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Mapeia o campo `email` do request para a coluna `Email` da tabela `users`.
        // A senha é validada via `User::getAuthPassword()` (coluna `Password`).
        $credentials = [
            'Email'    => $request->input('email'),
            'password' => $request->input('password'),
        ];

        if (!$token = Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ])->status(401);
        }

        return $this->tokenResponse($token, Auth::user());
    }

    /**
     * GET /api/auth/me
     */
    public function me(): JsonResponse
    {
        return response()->json(['user' => Auth::user()]);
    }

    /**
     * PUT /api/auth/profile — atualiza nome (nickname: sem acentos nem espaços).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $this->validate($request, [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-zA-Z0-9_-]+$/',
            ],
        ], [
            'name.regex' => 'O nome deve ser um nickname: apenas letras (sem acento), números, _ e -.',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $user->Name = $request->input('name');
        $user->save();

        return response()->json(['user' => $user->fresh()]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(): JsonResponse
    {
        Auth::logout();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    private function tokenResponse(string $token, $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'token_type'   => 'bearer',
            'access_token' => $token,
            'expires_in'   => Auth::factory()->getTTL() * 60,
            'user'         => $user,
        ], $status);
    }
}
