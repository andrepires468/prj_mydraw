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

class CreatePostService
{
    public function __construct(private readonly PostStorageService $storage)
    {
    }

    /**
     * @return array{gallery: Gallery}
     */
    public function upload(Request $request): array
    {
        $user = $this->ensureAuthenticatedAdmin($request);

        $userId    = $this->userId($user);
        $draftUuid = $request->input('draft_uuid');

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        $post = Post::firstOrCreate(
            [
                'user_id'    => $userId,
                'draft_uuid' => $draftUuid,
                'status'     => Post::STATUS_DRAFT,
            ],
            [
                'title'       => '',
                'description' => null,
            ]
        );
        $postCreated = $post->wasRecentlyCreated;

        if ($post->status !== Post::STATUS_DRAFT) {
            throw new ApiException(
                409,
                'Este rascunho já foi publicado.',
                [
                    'error'   => 'draft_already_published',
                    'message' => 'Este rascunho já foi publicado.',
                ]
            );
        }

        $postId = (int) $post->id;
        $disk   = Storage::disk('s3');

        $logContext = [
            'user_id'    => $userId,
            'draft_uuid' => $draftUuid,
            'post_id'    => $postId,
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

            PostListCache::bump($userId, false);

            return ['gallery' => $gallery->fresh()];
        } catch (Throwable $e) {
            Log::error('create-post/upload failed', array_merge($logContext, [
                'object_key'   => $uploadedKey,
                'endpoint'     => env('AWS_ENDPOINT'),
                'bucket'       => env('AWS_BUCKET'),
                'post_created' => $postCreated,
                'exception'    => get_class($e),
                'message'      => $e->getMessage(),
            ]));

            if ($uploadedKey) {
                $this->storage->safeDelete(
                    $disk,
                    array_merge([$uploadedKey], $uploadedThumbs),
                    $logContext
                );
            }

            if ($postCreated) {
                try {
                    $hasImages = Gallery::query()->where('post_id', $postId)->exists();
                    if (!$hasImages) {
                        $post->delete();
                    }
                } catch (Throwable $cleanupErr) {
                    Log::warning('cleanup of empty draft post failed', [
                        'post_id' => $postId,
                        'message' => $cleanupErr->getMessage(),
                    ]);
                }
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
     * @return array{post: Post}
     */
    public function store(Request $request): array
    {
        $user = $this->ensureAuthenticatedAdmin($request);

        $userId    = $this->userId($user);
        $draftUuid = $request->input('draft_uuid');

        $post = Post::query()
            ->where('user_id', $userId)
            ->where('draft_uuid', $draftUuid)
            ->where('status', Post::STATUS_DRAFT)
            ->first();

        if (!$post) {
            throw new ApiException(404, 'Draft not found.', ['error' => 'draft_not_found']);
        }

        $hasImages = Gallery::query()
            ->where('post_id', $post->id)
            ->exists();

        if (!$hasImages) {
            throw new ApiException(422, 'Draft has no images.', ['error' => 'no_images_in_draft']);
        }

        try {
            DB::transaction(function () use ($post, $request): void {
                Post::prependPublishedSortOrder((int) $post->id);
                $post->update([
                    'title'       => (string) ($request->input('title') ?? ''),
                    'description' => $request->input('description'),
                    'status'      => Post::STATUS_PUBLISHED,
                    'sort_order'  => 1,
                    'draft_uuid'  => null,
                ]);
            });

            PostListCache::bump($userId, true);

            return ['post' => $post->fresh()->load('images')];
        } catch (Throwable $e) {
            Log::error('create-post/store failed', [
                'user_id'    => $userId,
                'draft_uuid' => $draftUuid,
                'post_id'    => $post->id,
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
            ]);

            throw new ApiException(
                500,
                'Falha ao criar o post.',
                [
                    'error'   => 'create_post_failed',
                    'message' => 'Falha ao criar o post.',
                    'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
                ]
            );
        }
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
}
