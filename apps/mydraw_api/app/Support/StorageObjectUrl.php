<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Converte uma object key do disco `s3` em URL acessível pelo frontend.
 *
 * Paridade com {@see \App\Models\Gallery}: bucket público vs URL pré-assinada.
 */
final class StorageObjectUrl
{
    public static function urlForKey(?string $key): ?string
    {
        if (!$key) {
            return null;
        }

        $bucketPublic = filter_var(env('MINIO_BUCKET_PUBLIC', false), FILTER_VALIDATE_BOOLEAN);

        if ($bucketPublic) {
            $publicBase = PublicStorageBaseUrl::base();
            $bucket     = env('AWS_BUCKET');
            if ($publicBase && $bucket) {
                return sprintf('%s/%s/%s', $publicBase, $bucket, $key);
            }
        }

        try {
            $ttl = (int) env('MINIO_URL_TTL_MINUTES', 60);

            return Storage::disk('s3')->temporaryUrl(
                $key,
                Carbon::now()->addMinutes($ttl)
            );
        } catch (Throwable $e) {
            return null;
        }
    }
}
