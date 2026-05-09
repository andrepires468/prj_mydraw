<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\GoogleIdentityEmail;
use App\Services\GoogleAvatarSyncService;
use App\Services\GoogleIdTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $googleIdTokenVerifier,
        private readonly GoogleAvatarSyncService $googleAvatarSyncService,
    ) {
    }

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

        $user = DB::transaction(function () use ($request): User {
            $hasAdmin = User::query()
                ->where('IsAdmin', true)
                ->exists();

            return User::create([
                'Name'     => $request->input('name'),
                'Email'    => $request->input('email'),
                'Password' => Hash::make($request->input('password')),
                'IsAdmin'  => $hasAdmin ? null : true,
            ]);
        });

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
     * PUT /api/auth/password — altera senha (sessão atual).
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $this->validate($request, [
            'current_password' => 'required|string',
            'password'         => 'required|string|min:6|max:100|confirmed',
        ]);

        /** @var User $user */
        $user = Auth::user();

        if (!Hash::check((string) $request->input('current_password'), $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->Password = Hash::make((string) $request->input('password'));
        $user->save();

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(): JsonResponse
    {
        Auth::logout();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    /**
     * POST /api/auth/google — login via Google ID Token (GIS credential).
     */
    public function googleLogin(Request $request): JsonResponse
    {
        $clientId = (string) env('GOOGLE_CLIENT_ID', '');
        if ($clientId === '') {
            return response()->json([
                'message' => 'Google sign-in is not configured.',
            ], 503);
        }

        $this->validate($request, [
            'credential' => 'required|string',
        ]);

        $claims = $this->googleIdTokenVerifier->verify(
            (string) $request->input('credential'),
            $clientId
        );

        $sub = (string) $claims->sub;

        $userByGoogle = User::where('GoogleId', $sub)->first();
        if ($userByGoogle) {
            $user = $userByGoogle;
        } else {
            $user = $this->findUserByEmailForGoogle((string) $claims->email);
            if (!$user) {
                throw ValidationException::withMessages([
                    'credential' => ['No account found for this Google user. Register first.'],
                ])->status(422);
            }

            $existingGoogleId = $user->attributes['GoogleId'] ?? null;
            if (filled($existingGoogleId) && $existingGoogleId !== $sub) {
                return response()->json([
                    'message' => 'This email is already linked to a different Google account.',
                ], 409);
            }

            if (!filled($existingGoogleId)) {
                $user->GoogleId = $sub;
                $user->save();
            }
        }

        $user->refresh();
        $this->googleAvatarSyncService->syncFromPayload($user, $claims);
        $user->refresh();

        $token = Auth::login($user);

        return $this->tokenResponse($token, $user);
    }

    /**
     * POST /api/auth/google/link — vincula Google ao usuário autenticado (email deve coincidir).
     */
    public function googleLink(Request $request): JsonResponse
    {
        $clientId = (string) env('GOOGLE_CLIENT_ID', '');
        if ($clientId === '') {
            return response()->json([
                'message' => 'Google linking is not configured.',
            ], 503);
        }

        $this->validate($request, [
            'credential' => 'required|string',
        ]);

        /** @var User $user */
        $user = Auth::user();

        $claims = $this->googleIdTokenVerifier->verify(
            (string) $request->input('credential'),
            $clientId
        );

        // Email direto da BD (evita cache / estado intermédio do modelo).
        $dbEmailRaw = (string) (User::query()
            ->whereKey($user->getKey())
            ->value('Email') ?? '');

        $tokenEmail   = GoogleIdentityEmail::canonicalForIdentityMatch((string) $claims->email);
        $profileEmail = GoogleIdentityEmail::canonicalForIdentityMatch($dbEmailRaw);

        if ($tokenEmail !== $profileEmail) {
            throw ValidationException::withMessages([
                'credential' => ['This Google account email does not match your profile email.'],
            ])->status(422);
        }

        $sub = (string) $claims->sub;

        $ownerOther = User::where('GoogleId', $sub)->where('Id', '!=', $user->Id)->first();
        if ($ownerOther) {
            return response()->json([
                'message' => 'This Google account is already linked to another user.',
            ], 409);
        }

        $user->GoogleId = $sub;
        $user->save();

        $user->refresh();
        $this->googleAvatarSyncService->syncFromPayload($user, $claims);
        $user->refresh();

        return response()->json(['user' => $user]);
    }

    /**
     * POST /api/auth/google/unlink — remove o vínculo Google (mantém senha e sessão).
     *
     * Usa SQL com identificadores entre backticks e alias no SELECT: em alguns stacks
     * PDO/MySQL devolve nomes de colunas em minúsculas e o modelo pode não expor `GoogleId`
     * em `$attributes` como esperado.
     */
    public function googleUnlink(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $userId = $user->getKey();

        $row = DB::selectOne(
            'SELECT `GoogleId` AS gid FROM `users` WHERE `Id` = ? LIMIT 1',
            [$userId]
        );

        $googleId = isset($row->gid) ? (string) $row->gid : '';

        if ($googleId === '') {
            return response()->json([
                'message' => 'Google account is not linked.',
            ], 422);
        }

        DB::update(
            'UPDATE `users` SET `GoogleId` = NULL WHERE `Id` = ?',
            [$userId]
        );

        return response()->json([
            'user' => User::query()->findOrFail($userId),
        ]);
    }

    /**
     * Localiza utilizador pelo mesmo critério que o vínculo Google (incl. equivalência Gmail).
     */
    private function findUserByEmailForGoogle(string $googleClaimEmail): ?User
    {
        $simple = GoogleIdentityEmail::normalizeBasic($googleClaimEmail);

        $user = User::whereRaw('LOWER(TRIM(Email)) = ?', [$simple])->first();
        if ($user !== null) {
            return $user;
        }

        $canonical = GoogleIdentityEmail::canonicalForIdentityMatch($simple);
        if (!str_ends_with($canonical, '@gmail.com')) {
            return null;
        }

        return User::query()
            ->where(function ($q) {
                $q->whereRaw('LOWER(Email) LIKE ?', ['%@gmail.com'])
                    ->orWhereRaw('LOWER(Email) LIKE ?', ['%@googlemail.com']);
            })
            ->get()
            ->first(function (User $u) use ($canonical) {
                $db = GoogleIdentityEmail::normalizeBasic(
                    (string) ($u->attributes['Email'] ?? ''),
                );

                return GoogleIdentityEmail::canonicalForIdentityMatch($db) === $canonical;
            });
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
