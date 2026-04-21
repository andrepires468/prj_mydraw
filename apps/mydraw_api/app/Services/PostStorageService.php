<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Encapsula as operações de storage relacionadas a imagens de posts:
 *  - upload do arquivo original em caminho determinístico (`posts/<userId>/<postId>/<uuid>.<ext>`);
 *  - geração e envio dos thumbnails associados (320x320 e 180x180);
 *  - remoção de uma imagem original e seus thumbs;
 *  - remoções "best-effort" com log em falhas.
 *
 * Centraliza as regras compartilhadas entre o fluxo de criação de post
 * (`CreatePostController`) e o fluxo de edição (`EditPostController`).
 */
class PostStorageService
{
    /**
     * Resoluções máximas dos thumbs gerados por upload. Primeiro valor é a
     * largura máxima, segundo é a altura máxima; a proporção da imagem
     * original é sempre preservada e nunca há upscaling.
     *
     * @var array<int,array{0:int,1:int}>
     */
    public const THUMB_SIZES = [
        [320, 320],
        [180, 180],
    ];

    public const THUMB_QUALITY = 85;

    public function __construct(private readonly ThumbnailService $thumbnails)
    {
    }

    /**
     * Faz upload de uma imagem ao bucket no caminho final e gera thumbs.
     *
     * @param  array<string,mixed>  $logContext  Contexto extra para logs.
     * @return array{
     *     key: string,
     *     thumbs: array<int,string>,
     *     ext: string,
     *     mime: string,
     *     size: int|null
     * }
     *
     * @throws Throwable Em caso de falha confirmada no envio do original.
     *                   Thumbs que falham são apenas registradas em log.
     */
    public function uploadOriginalWithThumbnails(
        Filesystem $disk,
        UploadedFile $file,
        int $userId,
        int $postId,
        array $logContext = []
    ): array {
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $key  = sprintf('posts/%d/%d/%s.%s', $userId, $postId, Str::uuid(), $ext);

        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Não foi possível ler o arquivo recebido.');
        }

        try {
            $disk->put($key, $stream, [
                'visibility'  => 'public',
                'ContentType' => $mime,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (!$disk->exists($key)) {
            throw new RuntimeException('Objeto não encontrado no bucket após upload.');
        }

        $thumbs = $this->generateAndUploadThumbnails(
            $disk,
            $file->getRealPath(),
            $mime,
            $key,
            $ext,
            $logContext
        );

        return [
            'key'    => $key,
            'thumbs' => $thumbs,
            'ext'    => $ext,
            'mime'   => $mime,
            'size'   => $file->getSize() ?: null,
        ];
    }

    /**
     * Gera e envia ao bucket os thumbnails associados a uma imagem original.
     * Falhas individuais são apenas registradas em log, sem interromper o
     * fluxo principal (pois o original já está confirmado no bucket).
     *
     * @param  array<string,mixed>  $logContext
     * @return array<int,string>   Keys dos thumbs efetivamente enviados.
     */
    public function generateAndUploadThumbnails(
        Filesystem $disk,
        string $sourcePath,
        string $mime,
        string $originalKey,
        string $ext,
        array $logContext = []
    ): array {
        $baseKey   = $this->stripExtension($originalKey);
        $published = [];

        foreach (self::THUMB_SIZES as [$maxW, $maxH]) {
            $thumbKey = sprintf('%s-thumb-%dx%d.%s', $baseKey, $maxW, $maxH, $ext);

            try {
                $binary = $this->thumbnails->generate($sourcePath, $mime, $maxW, $maxH, self::THUMB_QUALITY);

                $disk->put($thumbKey, $binary, [
                    'visibility'  => 'public',
                    'ContentType' => $mime,
                ]);

                if (!$disk->exists($thumbKey)) {
                    throw new RuntimeException('Thumbnail não encontrada no bucket após upload.');
                }

                $published[] = $thumbKey;
            } catch (Throwable $e) {
                Log::warning('post-storage/thumbnail failed', array_merge($logContext, [
                    'original_key' => $originalKey,
                    'thumb_key'    => $thumbKey,
                    'max_width'    => $maxW,
                    'max_height'   => $maxH,
                    'exception'    => get_class($e),
                    'message'      => $e->getMessage(),
                ]));

                try {
                    if ($disk->exists($thumbKey)) {
                        $disk->delete($thumbKey);
                    }
                } catch (Throwable $cleanupErr) {
                    Log::warning('cleanup of failed thumbnail also failed', [
                        'thumb_key' => $thumbKey,
                        'message'   => $cleanupErr->getMessage(),
                    ]);
                }
            }
        }

        return $published;
    }

    /**
     * Retorna as keys dos thumbs derivados de uma key original, seguindo o
     * padrão `{baseKey}-thumb-{W}x{H}.{ext}`.
     *
     * @return array<int,string>
     */
    public function thumbKeysFor(string $originalKey): array
    {
        $ext     = pathinfo($originalKey, PATHINFO_EXTENSION);
        $baseKey = $this->stripExtension($originalKey);
        if ($ext === '' || $baseKey === $originalKey) {
            return [];
        }

        $keys = [];
        foreach (self::THUMB_SIZES as [$w, $h]) {
            $keys[] = sprintf('%s-thumb-%dx%d.%s', $baseKey, $w, $h, $ext);
        }

        return $keys;
    }

    /**
     * Remove do bucket a imagem original e todos os thumbs associados.
     * Falhas são apenas registradas em log (o banco continua sendo a fonte
     * de verdade; arquivos órfãos são aceitáveis e podem ser auditados).
     *
     * @param  array<string,mixed>  $logContext
     */
    public function deleteImageFiles(Filesystem $disk, string $originalKey, array $logContext = []): void
    {
        $keys = array_merge([$originalKey], $this->thumbKeysFor($originalKey));
        $this->safeDelete($disk, $keys, $logContext);
    }

    /**
     * Remove múltiplas keys do bucket ignorando ausências e registrando
     * falhas em log.
     *
     * @param  array<int,string>     $keys
     * @param  array<string,mixed>   $logContext
     */
    public function safeDelete(Filesystem $disk, array $keys, array $logContext = []): void
    {
        foreach ($keys as $key) {
            try {
                $disk->delete($key);
            } catch (Throwable $e) {
                Log::warning('post-storage/delete failed', array_merge($logContext, [
                    'object_key' => $key,
                    'exception'  => get_class($e),
                    'message'    => $e->getMessage(),
                ]));
            }
        }
    }

    /**
     * Remove a última extensão (".ext") do final de uma key, preservando o
     * restante do caminho.
     */
    private function stripExtension(string $key): string
    {
        $result = preg_replace('/\.[^.\/]+$/', '', $key);

        return $result === null ? $key : $result;
    }
}
