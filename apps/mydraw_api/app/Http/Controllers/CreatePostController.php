<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\Post;
use App\Models\User;
use App\Services\PostStorageService;
use App\Support\PostListCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class CreatePostController extends Controller
{
    public function __construct(private readonly PostStorageService $storage)
    {
    }

    /**
     * GET /api/v1/create-post/storage-check — teste de round-trip no bucket.
     * Útil para validar credenciais/endpoint/bucket sem envolver upload real.
     */
    public function storageCheck(): JsonResponse
    {
        if (!env('APP_DEBUG')) {
            return response()->json(['error' => 'disabled'], 404);
        }

        $disk = Storage::disk('s3');
        $key  = sprintf('diagnostics/%s.txt', Str::uuid());
        $body = 'mydraw storage check '.Carbon::now()->toIso8601String();

        try {
            $disk->put($key, $body, ['visibility' => 'public']);
            $exists   = $disk->exists($key);
            $readBack = $disk->get($key);
            $disk->delete($key);

            return response()->json([
                'ok'       => $exists && $readBack === $body,
                'endpoint' => env('AWS_ENDPOINT'),
                'bucket'   => env('AWS_BUCKET'),
                'region'   => env('AWS_DEFAULT_REGION'),
                'exists'   => $exists,
                'match'    => $readBack === $body,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok'        => false,
                'endpoint'  => env('AWS_ENDPOINT'),
                'bucket'    => env('AWS_BUCKET'),
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ], 502);
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

    /**
     * POST /api/v1/create-post/upload — multipart: file, draft_uuid.
     *
     * Fluxo:
     *   1) valida;
     *   2) `firstOrCreate` do Post de rascunho por `(user_id, draft_uuid)`;
     *   3) delega upload (original + thumbs) para o {@see PostStorageService};
     *   4) persiste a Gallery vinculada ao Post.
     *
     * Em caso de falha remove objetos órfãos do bucket e, se o Post de
     * rascunho foi criado neste request e ficou sem imagens, remove-o também.
     */
    public function upload(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $this->validate($request, [
            'file'       => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:10240',
            'draft_uuid' => 'required|uuid',
        ]);

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
            return response()->json([
                'error'   => 'draft_already_published',
                'message' => 'Este rascunho já foi publicado.',
            ], 409);
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

            // Serializa anexos concorrentes ao mesmo post para que `position`
            // incremente corretamente (ex.: vários arquivos soltos de uma vez).
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

            // Upload em draft afeta apenas listagens/contagens do usuário.
            PostListCache::bump($userId, false);

            return response()->json(['gallery' => $gallery->fresh()], 201);
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

            return response()->json([
                'error'   => 'storage_upload_failed',
                'message' => 'Falha ao enviar imagem para o storage.',
                'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 502);
        }
    }

    /**
     * POST /api/v1/create-post — finaliza rascunho: draft_uuid, title, description.
     *
     * Com o novo fluxo, os arquivos já estão no caminho definitivo desde o
     * upload. Publicar significa apenas preencher os metadados finais e
     * transicionar o status do Post de `draft` para `published`.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $this->validate($request, [
            'draft_uuid'  => 'required|uuid',
            'title'       => 'nullable|string|max:160',
            'description' => 'nullable|string|max:2000',
        ]);

        $userId    = $this->userId($user);
        $draftUuid = $request->input('draft_uuid');

        $post = Post::query()
            ->where('user_id', $userId)
            ->where('draft_uuid', $draftUuid)
            ->where('status', Post::STATUS_DRAFT)
            ->first();

        if (!$post) {
            return response()->json(['error' => 'draft_not_found'], 404);
        }

        $hasImages = Gallery::query()
            ->where('post_id', $post->id)
            ->exists();

        if (!$hasImages) {
            return response()->json(['error' => 'no_images_in_draft'], 422);
        }

        try {
            $post->update([
                'title'       => (string) ($request->input('title') ?? ''),
                'description' => $request->input('description'),
                'status'      => Post::STATUS_PUBLISHED,
                'draft_uuid'  => null,
            ]);

            // Publicação impacta galeria global e contagens do autor.
            PostListCache::bump($userId, true);

            return response()->json(['post' => $post->fresh()->load('images')], 201);
        } catch (Throwable $e) {
            Log::error('create-post/store failed', [
                'user_id'    => $userId,
                'draft_uuid' => $draftUuid,
                'post_id'    => $post->id,
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
            ]);

            return response()->json([
                'error'   => 'create_post_failed',
                'message' => 'Falha ao criar o post.',
                'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
