<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $this->validate($request, [
            'name'     => 'required|string|max:200',
            'email'    => 'required|email|max:200|unique:users,Email',
            'password' => 'required|string|min:6|max:100',
        ]);

        return ApiResponse::success($this->authService->register($request), '', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $this->validate($request, [
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        return ApiResponse::success($this->authService->login($request));
    }

    public function me(): JsonResponse
    {
        return ApiResponse::success($this->authService->me());
    }

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

        return ApiResponse::success($this->authService->updateProfile($request));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $this->validate($request, [
            'current_password' => 'required|string',
            'password'         => 'required|string|min:6|max:100|confirmed',
        ]);

        return ApiResponse::success($this->authService->updatePassword($request));
    }

    public function logout(): JsonResponse
    {
        return ApiResponse::success($this->authService->logout());
    }

    public function googleLogin(Request $request): JsonResponse
    {
        $this->validate($request, [
            'credential' => 'required|string',
        ]);

        return ApiResponse::success($this->authService->googleLogin($request));
    }

    public function googleLink(Request $request): JsonResponse
    {
        $this->validate($request, [
            'credential' => 'required|string',
        ]);

        return ApiResponse::success($this->authService->googleLink($request));
    }

    public function googleUnlink(): JsonResponse
    {
        return ApiResponse::success($this->authService->googleUnlink());
    }
}
