<?php

namespace App\Services;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use stdClass;
use Throwable;

/**
 * Baixa a foto do claim `picture` do Google e grava no disco S3 (JPEG/PNG/GIF apenas).
 */
final class GoogleAvatarSyncService
{
    private const MAX_BYTES = 2097152; // 2 MiB

    /** @var array<string, string> */
    private const MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
    ];

    public function syncFromPayload(User $user, stdClass $claims): void
    {
        $picture = $claims->picture ?? null;
        if (!is_string($picture) || $picture === '') {
            return;
        }

        if (!str_starts_with($picture, 'https://')) {
            Log::warning('Google avatar URL rejected (non-HTTPS)', ['user_id' => $user->Id]);

            return;
        }

        try {
            $binary = $this->download($picture);
        } catch (Throwable $e) {
            Log::warning('Google avatar download failed', [
                'user_id' => $user->Id,
                'error'   => $e->getMessage(),
            ]);

            return;
        }

        if ($binary === '' || strlen($binary) > self::MAX_BYTES) {
            Log::warning('Google avatar payload invalid size', ['user_id' => $user->Id]);

            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($binary);
        if (!is_string($mime) || !isset(self::MIME_TO_EXT[$mime])) {
            Log::warning('Google avatar MIME not allowed', [
                'user_id' => $user->Id,
                'mime'    => $mime,
            ]);

            return;
        }

        $ext = self::MIME_TO_EXT[$mime];

        if (@getimagesizefromstring($binary) === false) {
            Log::warning('Google avatar failed image decode', ['user_id' => $user->Id]);

            return;
        }

        $newKey = sprintf('avatars/%d.%s', $user->Id, $ext);
        $oldKey = $user->attributes['AvatarKey'] ?? null;

        try {
            Storage::disk('s3')->put($newKey, $binary, [
                'ContentType' => $mime,
            ]);
        } catch (Throwable $e) {
            Log::warning('Google avatar S3 put failed', [
                'user_id' => $user->Id,
                'error'   => $e->getMessage(),
            ]);

            return;
        }

        if (is_string($oldKey) && $oldKey !== '' && $oldKey !== $newKey) {
            try {
                Storage::disk('s3')->delete($oldKey);
            } catch (Throwable $e) {
                Log::warning('Google avatar old object delete failed', [
                    'key'   => $oldKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $user->AvatarKey = $newKey;
        $user->save();
    }

    /**
     * @throws GuzzleException
     */
    private function download(string $url): string
    {
        $client = new Client([
            'timeout'         => 10,
            'connect_timeout' => 5,
            'http_errors'     => true,
            'headers'         => [
                'User-Agent' => 'mydraw-api/1.0',
                'Accept'     => 'image/*,*/*',
            ],
        ]);

        $response = $client->get($url);

        return (string) $response->getBody();
    }
}
