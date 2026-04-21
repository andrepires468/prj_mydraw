<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

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

    protected $appends = ['url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'Id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /**
     * URL para exibir o arquivo no frontend.
     *
     * Estratégia:
     *  - Se `MINIO_BUCKET_PUBLIC=true` (bucket com policy pública), retorna a
     *    URL direta em `MINIO_PUBLIC_URL/bucket/key`.
     *  - Caso contrário (bucket privado), devolve uma **presigned URL**
     *    temporária (padrão 60 min), permitindo ao browser baixar mesmo
     *    sem tornar o bucket público.
     */
    public function getUrlAttribute(): ?string
    {
        if (!$this->object_key) {
            return null;
        }

        $bucketPublic = filter_var(env('MINIO_BUCKET_PUBLIC', false), FILTER_VALIDATE_BOOLEAN);

        if ($bucketPublic) {
            $publicBase = rtrim((string) env('MINIO_PUBLIC_URL', env('AWS_ENDPOINT', '')), '/');
            $bucket     = env('AWS_BUCKET');
            if ($publicBase && $bucket) {
                return sprintf('%s/%s/%s', $publicBase, $bucket, $this->object_key);
            }
        }

        try {
            $ttl = (int) env('MINIO_URL_TTL_MINUTES', 60);
            return Storage::disk('s3')->temporaryUrl(
                $this->object_key,
                Carbon::now()->addMinutes($ttl)
            );
        } catch (\Throwable $e) {
            return null;
        }
    }
}
