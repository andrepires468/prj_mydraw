<?php

namespace App\Services;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * Verifica ID Tokens do Google Identity Services (JWT RS256 + JWKS).
 */
final class GoogleIdTokenVerifier
{
    private const JWKS_URI = 'https://www.googleapis.com/oauth2/v3/certs';

    private const JWKS_CACHE_KEY = 'google_oauth_jwks_json';

    private const JWKS_CACHE_TTL_SECONDS = 3600;

    /** @var array<string, string> */
    private const ALLOWED_ISS = [
        'https://accounts.google.com' => '1',
        'accounts.google.com'       => '1',
    ];

    /**
     * Decodifica e valida o credential JWT.
     *
     * @throws ValidationException
     */
    public function verify(string $credentialJwt, string $clientId): stdClass
    {
        $credentialJwt = trim($credentialJwt);
        if ($credentialJwt === '') {
            throw ValidationException::withMessages([
                'credential' => ['Google credential is required.'],
            ])->status(422);
        }

        try {
            $decoded = $this->decodeWithJwks($credentialJwt);
        } catch (ExpiredException|SignatureInvalidException $e) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid or expired Google token.'],
            ])->status(401);
        } catch (UnexpectedValueException $e) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid Google token.'],
            ])->status(401);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'credential' => ['Could not verify Google token.'],
            ])->status(401);
        }

        if (!isset(self::ALLOWED_ISS[(string) ($decoded->iss ?? '')])) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid Google token issuer.'],
            ])->status(401);
        }

        if (!$this->audienceMatches($decoded, $clientId)) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid Google token audience.'],
            ])->status(401);
        }

        if (empty($decoded->email) || !is_string($decoded->email)) {
            throw ValidationException::withMessages([
                'credential' => ['Google account has no email on file.'],
            ])->status(422);
        }

        if (!isset($decoded->email_verified) || $decoded->email_verified !== true) {
            throw ValidationException::withMessages([
                'credential' => ['Google email is not verified.'],
            ])->status(422);
        }

        return $decoded;
    }

    /**
     * @throws Throwable
     */
    private function decodeWithJwks(string $jwt): stdClass
    {
        try {
            return $this->decodeOnce($jwt);
        } catch (SignatureInvalidException|UnexpectedValueException $e) {
            Cache::forget(self::JWKS_CACHE_KEY);

            return $this->decodeOnce($jwt);
        }
    }

    /**
     * @throws SignatureInvalidException
     * @throws UnexpectedValueException
     * @throws ExpiredException
     */
    private function decodeOnce(string $jwt): stdClass
    {
        $jwks = $this->loadJwks();
        $keys = JWK::parseKeySet($jwks, 'RS256');

        return JWT::decode($jwt, $keys);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJwks(): array
    {
        /** @var array<string, mixed> $jwks */
        $jwks = Cache::remember(self::JWKS_CACHE_KEY, self::JWKS_CACHE_TTL_SECONDS, function (): array {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 5,
                ],
            ]);
            $raw = @file_get_contents(self::JWKS_URI, false, $ctx);
            if ($raw === false || $raw === '') {
                throw new UnexpectedValueException('Failed to load Google JWKS');
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new UnexpectedValueException('Invalid Google JWKS');
            }

            return $data;
        });

        return $jwks;
    }

    private function audienceMatches(stdClass $decoded, string $clientId): bool
    {
        $aud = $decoded->aud ?? null;
        if (is_array($aud)) {
            return in_array($clientId, $aud, true);
        }

        return is_string($aud) && $aud === $clientId;
    }
}
