<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Gallery;
use App\Models\Post;
use App\Models\User;
use App\Support\PostListCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EditPostService
{
    public function __construct(
        private readonly PostStorageService $storage,
        private readonly GalleryMetadataExtractionService $galleryMetadataExtraction,
        private readonly GeminiPostCategorizationService $geminiPostCategorization,
    ) {
    }

    /**
     * @return array{post: Post}
     */
    public function update(Request $request, int $id): array
    {
        $user = $this->ensureAuthenticatedAdmin($request);

        $post = $this->findOwnedPost($request, $id, $user);
        if (!$post) {
            throw new ApiException(404, 'Not found.', ['error' => 'not_found']);
        }

        $updates = [];
        $originalStatus = $post->status;

        if ($request->has('title')) {
            $updates['title'] = (string) ($request->input('title') ?? '');
        }
        if ($request->has('description')) {
            $desc = $request->input('description');
            $updates['description'] = $desc === null ? null : (string) $desc;
        }

        $targetStatus = $request->input('status', $post->status);

        if ($targetStatus === Post::STATUS_PUBLISHED) {
            $hasImages = Gallery::query()->where('post_id', $post->id)->exists();
            if (!$hasImages) {
                throw new ApiException(
                    422,
                    'O post precisa ter ao menos uma imagem para ser publicado.',
                    [
                        'error'   => 'no_images_in_post',
                        'message' => 'O post precisa ter ao menos uma imagem para ser publicado.',
                    ]
                );
            }
        }

        $prependOnPublish = false;
        if ($request->has('status') && $targetStatus !== $post->status) {
            $updates['status'] = $targetStatus;
            $updates['draft_uuid'] = null;
            if ($targetStatus === Post::STATUS_PUBLISHED && $post->status !== Post::STATUS_PUBLISHED) {
                $updates['sort_order'] = 1;
                $prependOnPublish = true;
            }
        }

        if (empty($updates)) {
            return ['post' => $post->fresh()->load('images')];
        }

        try {
            DB::transaction(function () use ($post, $updates, $prependOnPublish): void {
                if ($prependOnPublish) {
                    Post::prependPublishedSortOrder((int) $post->id);
                }
                $post->update($updates);
            });
            $finalStatus = (string) ($updates['status'] ?? $post->status);
            $touchGlobal = $originalStatus === Post::STATUS_PUBLISHED
                || $finalStatus === Post::STATUS_PUBLISHED;
            PostListCache::bump($this->userId($user), $touchGlobal);

            $metadataExtractionError = null;
            try {
                $fresh = $post->fresh();
                if ($fresh) {
                    $this->galleryMetadataExtraction->extractMissingForPost($fresh, $user);
                }
            } catch (Throwable $e) {
                $metadataExtractionError = mb_substr(trim($e->getMessage()), 0, 500);
                if ($metadataExtractionError === '') {
                    $metadataExtractionError = 'Gallery metadata extraction failed.';
                }
            }

            $payload = ['post' => $post->fresh()->load('images')];
            if ($metadataExtractionError !== null) {
                $payload['metadata_extraction_error'] = $metadataExtractionError;
            }

            return $payload;
        } catch (Throwable $e) {
            Log::error('edit-post/update failed', [
                'user_id'   => $this->userId($user),
                'post_id'   => $post->id,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            throw new ApiException(
                500,
                'Falha ao atualizar o post.',
                [
                    'error'   => 'update_post_failed',
                    'message' => 'Falha ao atualizar o post.',
                    'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
                ]
            );
        }
    }

    /**
     * @return array{deleted: bool}
     */
    public function destroy(Request $request, int $id): array
    {
        $user = $this->ensureAuthenticatedAdmin($request);

        $post = $this->findOwnedPost($request, $id, $user);
        if (!$post) {
            throw new ApiException(404, 'Not found.', ['error' => 'not_found']);
        }

        $objectKeys = Gallery::query()
            ->where('post_id', $post->id)
            ->pluck('object_key')
            ->filter()
            ->values()
            ->all();

        $disk       = Storage::disk('s3');
        $logContext = [
            'user_id' => $this->userId($user),
            'post_id' => $post->id,
        ];

        foreach ($objectKeys as $key) {
            $this->storage->deleteImageFiles($disk, (string) $key, $logContext);
        }

        try {
            DB::transaction(function () use ($post) {
                $post->delete();
            });
            $touchGlobal = $post->status === Post::STATUS_PUBLISHED;
            PostListCache::bump($this->userId($user), $touchGlobal);
        } catch (Throwable $e) {
            Log::error('edit-post/destroy failed', [
                'user_id'   => $this->userId($user),
                'post_id'   => $post->id,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            throw new ApiException(
                500,
                'Falha ao excluir o post.',
                [
                    'error'   => 'delete_post_failed',
                    'message' => 'Falha ao excluir o post.',
                    'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
                ]
            );
        }

        return ['deleted' => true];
    }

    /**
     * @return array{gallery: Gallery}
     */
    public function uploadImage(Request $request, int $id): array
    {
        $user = $this->ensureAuthenticatedAdmin($request);

        $post = $this->findOwnedPost($request, $id, $user);
        if (!$post) {
            throw new ApiException(404, 'Not found.', ['error' => 'not_found']);
        }

        /** @var \Illuminate\Http\UploadedFile $file */
        $file   = $request->file('file');
        $userId = $this->userId($user);
        $postId = (int) $post->id;
        $disk   = Storage::disk('s3');

        $logContext = [
            'user_id' => $userId,
            'post_id' => $postId,
        ];

        $uploadedKey    = null;
        $uploadedThumbs = [];

        try {
            $result = $this->storage->uploadOriginalWithThumbnails(
                $disk,
                $file,
                $userId,
                $postId,
                $logContext
            );
            $uploadedKey    = $result['key'];
            $uploadedThumbs = $result['thumbs'];

            $gallery = DB::transaction(function () use ($postId, $userId, $result) {
                Post::query()->whereKey($postId)->lockForUpdate()->first();
                $position = (int) Gallery::query()
                    ->where('post_id', $postId)
                    ->count();

                return Gallery::create([
                    'post_id'    => $postId,
                    'user_id'    => $userId,
                    'draft_uuid' => null,
                    'object_key' => $result['key'],
                    'mime_type'  => $result['mime'],
                    'size'       => $result['size'],
                    'position'   => $position,
                ]);
            });
            $touchGlobal = $post->status === Post::STATUS_PUBLISHED;
            PostListCache::bump($userId, $touchGlobal);

            return ['gallery' => $gallery->fresh()];
        } catch (Throwable $e) {
            Log::error('edit-post/upload-image failed', array_merge($logContext, [
                'object_key' => $uploadedKey,
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
            ]));

            if ($uploadedKey) {
                $this->storage->safeDelete(
                    $disk,
                    array_merge([$uploadedKey], $uploadedThumbs),
                    $logContext
                );
            }

            throw new ApiException(
                502,
                'Falha ao enviar imagem para o storage.',
                [
                    'error'   => 'storage_upload_failed',
                    'message' => 'Falha ao enviar imagem para o storage.',
                    'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
                ]
            );
        }
    }

    /**
     * @return array{deleted: bool}
     */
    public function destroyImage(Request $request, int $id, int $galleryId): array
    {
        $user = $this->ensureAuthenticatedAdmin($request);

        $post = $this->findOwnedPost($request, $id, $user);
        if (!$post) {
            throw new ApiException(404, 'Not found.', ['error' => 'not_found']);
        }

        $gallery = Gallery::query()
            ->where('id', $galleryId)
            ->where('post_id', $post->id)
            ->first();

        if (!$gallery) {
            throw new ApiException(404, 'Image not found.', ['error' => 'image_not_found']);
        }

        $objectKey = (string) $gallery->object_key;

        try {
            $gallery->delete();
            $touchGlobal = $post->status === Post::STATUS_PUBLISHED;
            PostListCache::bump($this->userId($user), $touchGlobal);
        } catch (Throwable $e) {
            Log::error('edit-post/destroy-image failed', [
                'user_id'    => $this->userId($user),
                'post_id'    => $post->id,
                'gallery_id' => $galleryId,
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
            ]);

            throw new ApiException(
                500,
                'Falha ao remover a imagem.',
                [
                    'error'   => 'delete_image_failed',
                    'message' => 'Falha ao remover a imagem.',
                    'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
                ]
            );
        }

        $disk = Storage::disk('s3');
        $this->storage->deleteImageFiles($disk, $objectKey, [
            'user_id'    => $this->userId($user),
            'post_id'    => $post->id,
            'gallery_id' => $galleryId,
        ]);

        return ['deleted' => true];
    }

    /**
     * @return array{ok: bool}
     */
    public function reorderImages(Request $request, int $id): array
    {
        $user = $this->ensureAuthenticatedAdmin($request);

        $post = $this->findOwnedPost($request, $id, $user);
        if (!$post) {
            throw new ApiException(404, 'Not found.', ['error' => 'not_found']);
        }

        /** @var int[] $order */
        $order = array_map('intval', $request->input('order'));

        $existingIds = Gallery::query()
            ->where('post_id', $post->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($gid) => (int) $gid)
            ->values()
            ->all();

        $sortedRequested = $order;
        sort($sortedRequested);

        if ($existingIds !== $sortedRequested) {
            throw new ApiException(
                422,
                'A lista de ids não corresponde às imagens deste post.',
                [
                    'error'   => 'order_mismatch',
                    'message' => 'A lista de ids não corresponde às imagens deste post.',
                ]
            );
        }

        try {
            DB::transaction(function () use ($order, $post): void {
                if ($order === []) {
                    return;
                }

                $caseFragments = [];
                $bindings      = [];
                foreach ($order as $position => $galleryId) {
                    $caseFragments[] = 'WHEN ? THEN ?';
                    $bindings[]      = $galleryId;
                    $bindings[]      = $position;
                }

                $placeholders = implode(',', array_fill(0, count($order), '?'));
                $bindings[] = $post->id;
                foreach ($order as $galleryId) {
                    $bindings[] = $galleryId;
                }

                $sql = 'UPDATE gallery SET position = CASE id '
                    .implode(' ', $caseFragments)
                    .' END WHERE post_id = ? AND id IN ('.$placeholders.')';

                DB::update($sql, $bindings);
            });
            $touchGlobal = $post->status === Post::STATUS_PUBLISHED;
            PostListCache::bump($this->userId($user), $touchGlobal);
        } catch (Throwable $e) {
            Log::error('edit-post/reorder-images failed', [
                'user_id'   => $this->userId($user),
                'post_id'   => $post->id,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            throw new ApiException(
                500,
                'Falha ao reordenar imagens.',
                [
                    'error'   => 'reorder_failed',
                    'message' => 'Falha ao reordenar imagens.',
                    'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
                ]
            );
        }

        return ['ok' => true];
    }

    /**
     * Extrai metadados embutidos de um post (post_id obrigatório).
     * Com include_ai_categorize, também categoriza tags/rating via Gemini nesse post.
     *
     * @return array<string, mixed>
     */
    public function extractGalleryMetadata(Request $request): array
    {
        $user = $this->ensureAuthenticatedAdmin($request);

        if (!$request->filled('post_id')) {
            throw new ApiException(
                422,
                'post_id is required.',
                ['error' => 'post_id_required']
            );
        }

        $postId = (int) $request->input('post_id');
        if ($postId < 1) {
            throw new ApiException(
                422,
                'post_id is required.',
                ['error' => 'post_id_required']
            );
        }

        $post = Post::query()->find($postId);
        if (!$post) {
            throw new ApiException(404, 'Not found.', ['error' => 'not_found']);
        }

        $imageCount = Gallery::query()->where('post_id', $postId)->count();
        $this->geminiPostCategorization->raiseExecutionTimeBudget(max(1, $imageCount));

        $out = $this->galleryMetadataExtraction->extractMissingForPost($post, $user);
        $out['batch'] = [
            'post_id'        => $postId,
            'scope_post_ids' => [$postId],
        ];

        if ($request->boolean('include_ai_categorize')) {
            $processTags = $request->exists('process_ai_tags')
                ? $request->boolean('process_ai_tags')
                : ($request->exists('reprocess_ai_tags')
                    ? $request->boolean('reprocess_ai_tags')
                    : true);
            $processRatings = $request->exists('process_ai_ratings')
                ? $request->boolean('process_ai_ratings')
                : ($request->exists('reprocess_ai_ratings')
                    ? $request->boolean('reprocess_ai_ratings')
                    : true);

            if (!$processTags && !$processRatings) {
                throw new ApiException(
                    422,
                    'Select at least one of process_ai_tags or process_ai_ratings.',
                    ['error' => 'nothing_selected']
                );
            }

            $out['ai_categorization_batch'] = $this->geminiPostCategorization->categorizeMissingForAllPosts(
                $user,
                [$postId],
                $processTags,
                $processRatings,
            );
            // Refresh post so content_rating / ai_tags reflect categorization.
            $refreshed = Post::query()
                ->with([
                    'images' => static function ($q): void {
                        $q->orderBy('position')->orderBy('id');
                    },
                ])
                ->find($postId);
            if ($refreshed) {
                $out['post'] = $refreshed;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function categorizeWithAi(Request $request, int $id): array
    {
        $user = $this->ensureAuthenticatedAdmin($request);

        $post = Post::query()->find($id);
        if (!$post) {
            throw new ApiException(404, 'Not found.', ['error' => 'not_found']);
        }

        $imageCount = Gallery::query()->where('post_id', $post->id)->count();
        $this->geminiPostCategorization->raiseExecutionTimeBudget(max(1, $imageCount));

        return $this->geminiPostCategorization->categorizePost($post, $user);
    }

    private function authUser(Request $request): ?User
    {
        $user = $request->input('auth_user');

        return $user instanceof User ? $user : null;
    }

    private function userId(User $user): int
    {
        return (int) $user->getKey();
    }

    private function ensureAuthenticatedAdmin(Request $request): User
    {
        $user = $this->authUser($request);
        if (!$user) {
            throw new ApiException(401, 'Unauthorized.', ['error' => 'unauthorized']);
        }
        if (!$user->is_admin) {
            throw new ApiException(403, 'Forbidden.', ['error' => 'forbidden']);
        }

        return $user;
    }

    private function findOwnedPost(Request $request, int $id, ?User $user): ?Post
    {
        $post = Post::query()->find($id);

        if (!$post) {
            return null;
        }

        if (!$user || (int) $post->user_id !== $this->userId($user)) {
            return null;
        }

        return $post;
    }

}
