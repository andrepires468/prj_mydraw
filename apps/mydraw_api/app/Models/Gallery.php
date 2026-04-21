<?php

namespace App\Models;

use App\Services\PostStorageService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Gallery extends Model
{
    use HasFactory;

    protected $table = 'gallery';

    protected $fillable = [
        'post_id',
        'user_id',
        'draft_uuid',
        'object_key',
        'mime_type',
        'size',
        'position',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected $appends = ['url', 'thumbs'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'Id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /**
     * URL para exibir o arquivo original no frontend.
     */
    public function getUrlAttribute(): ?string
    {
        return $this->resolveObjectUrl($this->object_key);
    }

    /**
     * Mapa de URLs das thumbnails geradas para esta imagem.
     *
     * As chaves são os "rótulos" das resoluções máximas (ex.: "320x320",
     * "180x180") conforme o padrão aplicado no upload — a imagem real pode
     * ter dimensões menores, já que a proporção original é preservada.
     *
     * Se uma thumb estiver ausente no bucket (geração falhou ou arquivo
     * antigo sem thumbs), o valor correspondente é `null`.
     *
     * @return array<string, string|null>
     */
    public function getThumbsAttribute(): array
    {
        $result = [];

        if (!$this->object_key) {
            foreach (PostStorageService::THUMB_SIZES as [$w, $h]) {
                $result[sprintf('%dx%d', $w, $h)] = null;
            }
            return $result;
        }

        foreach (PostStorageService::THUMB_SIZES as [$w, $h]) {
            $label = sprintf('%dx%d', $w, $h);
            $key   = $this->thumbObjectKey((string) $this->object_key, $w, $h);
            $result[$label] = $key ? $this->resolveObjectUrl($key) : null;
        }

        return $result;
    }

    /**
     * Resolve URL de uma thumbnail específica para uso em listagens.
     */
    public function thumbUrl(int $maxW, int $maxH): ?string
    {
        if (!$this->object_key) {
            return null;
        }

        $key = $this->thumbObjectKey((string) $this->object_key, $maxW, $maxH);

        return $key ? $this->resolveObjectUrl($key) : null;
    }

    /**
     * Constrói o `object_key` de uma thumb seguindo o padrão
     * `{baseKey}-thumb-{W}x{H}.{ext}`; retorna null se a key original
     * não possuir uma extensão válida.
     */
    private function thumbObjectKey(string $originalKey, int $maxW, int $maxH): ?string
    {
        $ext = pathinfo($originalKey, PATHINFO_EXTENSION);
        if ($ext === '') {
            return null;
        }

        $base = preg_replace('/\.[^.\/]+$/', '', $originalKey);
        if ($base === null || $base === $originalKey) {
            return null;
        }

        return sprintf('%s-thumb-%dx%d.%s', $base, $maxW, $maxH, $ext);
    }

    /**
     * Converte uma `object_key` em URL acessível pelo frontend.
     *
     *  - Se `MINIO_BUCKET_PUBLIC=true`, retorna a URL direta em
     *    `MINIO_PUBLIC_URL/bucket/key` (útil quando o bucket está com policy
     *    pública).
     *  - Caso contrário, devolve uma presigned URL temporária (TTL em
     *    `MINIO_URL_TTL_MINUTES`, padrão 60 min).
     */
    private function resolveObjectUrl(?string $key): ?string
    {
        if (!$key) {
            return null;
        }

        $bucketPublic = filter_var(env('MINIO_BUCKET_PUBLIC', false), FILTER_VALIDATE_BOOLEAN);

        if ($bucketPublic) {
            $publicBase = rtrim((string) env('MINIO_PUBLIC_URL', env('AWS_ENDPOINT', '')), '/');
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
