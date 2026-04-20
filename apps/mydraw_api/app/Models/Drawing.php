<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Drawing extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'prompt',
        'model',
        'object_key',
        'mime_type',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected $appends = ['url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'Id');
    }

    /**
     * URL pública do arquivo no MinIO.
     *
     * Usa `MINIO_PUBLIC_URL` (endpoint alcançável pelo navegador) em vez do
     * `AWS_ENDPOINT` interno (hostname do container `minio`).
     */
    public function getUrlAttribute(): ?string
    {
        if (!$this->object_key) {
            return null;
        }

        $publicBase = rtrim((string) env('MINIO_PUBLIC_URL', env('AWS_ENDPOINT', '')), '/');
        $bucket     = env('AWS_BUCKET');

        if ($publicBase && $bucket) {
            return sprintf('%s/%s/%s', $publicBase, $bucket, $this->object_key);
        }

        try {
            return Storage::disk('s3')->url($this->object_key);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
