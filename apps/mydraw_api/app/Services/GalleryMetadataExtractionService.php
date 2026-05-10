<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\Post;
use App\Models\User;
use App\Support\PostListCache;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads originals from storage, extracts embedded Stable Diffusion metadata,
 * persists into `gallery`. Only processes rows where `metadata_type` is still null.
 */
class GalleryMetadataExtractionService
{
    public function __construct(
        private readonly ImageMetadataExtractor $metadataExtractor,
        private readonly LoraMetadataEnricher $loraEnricher,
    ) {
    }

    /**
     * Extract metadata for gallery rows of one post that do not yet have `metadata_type`.
     *
     * @return array{summary: array<string, int>, errors: array<int, array{gallery_id: int, error: string}>, post: Post}
     */
    public function extractMissingForPost(Post $post, User $actor): array
    {
        $galleryRows = Gallery::query()
            ->where('post_id', $post->id)
            ->whereNull('metadata_type')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $disk = Storage::disk('s3');

        [$summary, $errors, $touchedPostIds] = $this->processGalleryRows(
            $galleryRows,
            $disk,
            $this->userId($actor),
        );

        $this->bumpCachesForPosts($touchedPostIds);

        return [
            'summary' => $summary,
            'errors'  => $errors,
            'post'    => $post->fresh()->load([
                'images' => static function ($q): void {
                    $q->orderBy('position')->orderBy('id');
                },
            ]),
        ];
    }

    /**
     * Extract metadata for every gallery row (any post) with null `metadata_type`.
     *
     * @return array{summary: array<string, int>, errors: array<int, array{gallery_id: int, error: string}>, posts_touched: int}
     */
    public function extractMissingForAllPosts(User $actor): array
    {
        $galleryRows = Gallery::query()
            ->whereNull('metadata_type')
            ->orderBy('post_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $disk = Storage::disk('s3');

        [$summary, $errors, $touchedPostIds] = $this->processGalleryRows(
            $galleryRows,
            $disk,
            $this->userId($actor),
        );

        $this->bumpCachesForPosts($touchedPostIds);

        return [
            'summary'        => $summary,
            'errors'         => $errors,
            'posts_touched'  => count($touchedPostIds),
        ];
    }

    /**
     * @param  Collection<int, Gallery>  $galleryRows
     * @return array{0: array<string, int>, 1: array<int, array<string, mixed>>, 2: array<int, true>}
     */
    private function processGalleryRows(
        Collection $galleryRows,
        Filesystem $disk,
        int $actorUserId,
    ): array {
        $summary = [
            'total'       => $galleryRows->count(),
            'updated'     => 0,
            'no_metadata' => 0,
            'failed'      => 0,
        ];

        $errors           = [];
        $touchedPostIds   = [];

        $clear = $this->emptyGalleryMetadataAttributes();

        foreach ($galleryRows as $gallery) {
            $key = (string) $gallery->object_key;
            if ($key === '') {
                $summary['failed']++;
                $errors[] = [
                    'gallery_id' => $gallery->id,
                    'error'      => 'missing_object_key',
                ];

                continue;
            }

            try {
                $binary = $disk->get($key);
            } catch (Throwable $e) {
                Log::warning('gallery-metadata-extraction read failed', [
                    'user_id'    => $actorUserId,
                    'post_id'    => $gallery->post_id,
                    'gallery_id' => $gallery->id,
                    'object_key' => $key,
                    'message'    => $e->getMessage(),
                ]);
                $summary['failed']++;
                $errors[] = [
                    'gallery_id' => $gallery->id,
                    'error'      => 'storage_read_failed',
                ];

                continue;
            }

            $parsed = $this->metadataExtractor->extractStableDiffusionFromBinary($binary);

            if ($parsed === null) {
                try {
                    $gallery->update($clear);
                    $touchedPostIds[(int) $gallery->post_id] = true;
                } catch (Throwable $e) {
                    Log::error('gallery-metadata-extraction clear failed', [
                        'gallery_id' => $gallery->id,
                        'message'    => $e->getMessage(),
                    ]);
                    $summary['failed']++;
                    $errors[] = [
                        'gallery_id' => $gallery->id,
                        'error'      => 'persist_failed',
                    ];

                    continue;
                }
                $summary['no_metadata']++;

                continue;
            }

            $parsed['metadata_loras'] = $this->loraEnricher->enrich($parsed['metadata_loras'] ?? []);

            try {
                $gallery->update($this->onlyGalleryMetadataPayload($parsed));
                $touchedPostIds[(int) $gallery->post_id] = true;
            } catch (Throwable $e) {
                Log::error('gallery-metadata-extraction persist failed', [
                    'gallery_id' => $gallery->id,
                    'message'    => $e->getMessage(),
                ]);
                $summary['failed']++;
                $errors[] = [
                    'gallery_id' => $gallery->id,
                    'error'      => 'persist_failed',
                ];

                continue;
            }

            $summary['updated']++;
        }

        return [$summary, $errors, $touchedPostIds];
    }

    /**
     * @param  array<int, true>  $touchedPostIds
     */
    private function bumpCachesForPosts(array $touchedPostIds): void
    {
        foreach (array_keys($touchedPostIds) as $postId) {
            $post = Post::query()->find((int) $postId);
            if (!$post) {
                continue;
            }
            PostListCache::bump(
                (int) $post->user_id,
                $post->status === Post::STATUS_PUBLISHED
            );
        }
    }

    private function userId(User $user): int
    {
        return (int) $user->getKey();
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function onlyGalleryMetadataPayload(array $parsed): array
    {
        $keys = [
            'metadata_type',
            'metadata_prompt',
            'metadata_negative_prompt',
            'metadata_steps',
            'metadata_sampler',
            'metadata_schedule_type',
            'metadata_cfg_scale',
            'metadata_seed',
            'metadata_width',
            'metadata_height',
            'metadata_model',
            'metadata_model_hash',
            'metadata_clip_skip',
            'metadata_version',
            'metadata_loras',
            'metadata_raw',
        ];

        return array_intersect_key($parsed, array_flip($keys));
    }

    /**
     * @return array<string, null>
     */
    private function emptyGalleryMetadataAttributes(): array
    {
        return [
            'metadata_type'             => null,
            'metadata_prompt'           => null,
            'metadata_negative_prompt'  => null,
            'metadata_steps'            => null,
            'metadata_sampler'          => null,
            'metadata_schedule_type'    => null,
            'metadata_cfg_scale'        => null,
            'metadata_seed'             => null,
            'metadata_width'            => null,
            'metadata_height'           => null,
            'metadata_model'            => null,
            'metadata_model_hash'       => null,
            'metadata_clip_skip'        => null,
            'metadata_version'          => null,
            'metadata_loras'            => null,
            'metadata_raw'              => null,
        ];
    }
}
