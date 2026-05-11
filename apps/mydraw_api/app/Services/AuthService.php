<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\GoogleIdentityEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $googleIdTokenVerifier,
        private readonly GoogleAvatarSyncService $googleAvatarSyncService,
    ) {
    }

    /**
     * @return array{token_type: string, access_token: string, expires_in: int, user: User}
     */
    public function register(Request $request): array
    {
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

        return $this->buildTokenPayload($token, $user);
    }

    /**
     * @return array{token_type: string, access_token: string, expires_in: int, user: mixed}
     */
    public function login(Request $request): array
    {
        $credentials = [
            'Email'    => $request->input('email'),
            'password' => $request->input('password'),
        ];

        if (!$token = Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ])->status(401);
        }

        return $this->buildTokenPayload($token, Auth::user());
    }

    /**
     * @return array{user: mixed}
     */
    public function me(): array
    {
        return ['user' => Auth::user()];
    }

    /**
     * @return array{user: User}
     */
    public function updateProfile(Request $request): array
    {
        /** @var User $user */
        $user = Auth::user();
        $user->Name = $request->input('name');
        $user->save();

        return ['user' => $user->fresh()];
    }

    /**
     * @return array{message: string}
     */
    public function updatePassword(Request $request): array
    {
        /** @var User $user */
        $user = Auth::user();

        if (!Hash::check((string) $request->input('current_password'), $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->Password = Hash::make((string) $request->input('password'));
        $user->save();

        return ['message' => 'Password updated successfully.'];
    }

    /**
     * @return array{message: string}
     */
    public function logout(): array
    {
        Auth::logout();

        return ['message' => 'Sessão encerrada.'];
    }

    /**
     * @return array{token_type: string, access_token: string, expires_in: int, user: User}
     */
    public function googleLogin(Request $request): array
    {
        $clientId = (string) env('GOOGLE_CLIENT_ID', '');
        if ($clientId === '') {
            throw new ApiException(503, 'Google sign-in is not configured.');
        }

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
                throw new ApiException(
                    409,
                    'This email is already linked to a different Google account.'
                );
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

        return $this->buildTokenPayload($token, $user);
    }

    /**
     * @return array{user: User}
     */
    public function googleLink(Request $request): array
    {
        $clientId = (string) env('GOOGLE_CLIENT_ID', '');
        if ($clientId === '') {
            throw new ApiException(503, 'Google linking is not configured.');
        }

        /** @var User $user */
        $user = Auth::user();

        $claims = $this->googleIdTokenVerifier->verify(
            (string) $request->input('credential'),
            $clientId
        );

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
            throw new ApiException(
                409,
                'This Google account is already linked to another user.'
            );
        }

        $user->GoogleId = $sub;
        $user->save();

        $user->refresh();
        $this->googleAvatarSyncService->syncFromPayload($user, $claims);
        $user->refresh();

        return ['user' => $user];
    }

    /**
     * @return array{user: User}
     */
    public function googleUnlink(): array
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
            throw new ApiException(422, 'Google account is not linked.');
        }

        DB::update(
            'UPDATE `users` SET `GoogleId` = NULL WHERE `Id` = ?',
            [$userId]
        );

        return ['user' => User::query()->findOrFail($userId)];
    }

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

    /**
     * @return array{token_type: string, access_token: string, expires_in: int, user: mixed}
     */
    private function buildTokenPayload(string $token, mixed $user): array
    {
        return [
            'token_type'   => 'bearer',
            'access_token' => $token,
            'expires_in'   => Auth::factory()->getTTL() * 60,
            'user'         => $user,
        ];
    }
}
