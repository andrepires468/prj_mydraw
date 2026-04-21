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
use Throwable;

/**
 * Endpoints de edição de post e suas imagens.
 *
 *  - `PUT    /api/v1/posts/{id}`                 — atualiza título/descrição/status
 *  - `DELETE /api/v1/posts/{id}`                 — remove post + imagens + arquivos
 *  - `PUT    /api/v1/posts/{id}/images/reorder`   — persiste ordem (`gallery.position`)
 *  - `POST   /api/v1/posts/{id}/images`          — anexa nova imagem a post existente
 *  - `DELETE /api/v1/posts/{id}/images/{gid}`    — remove uma imagem (banco + storage)
 *
 * Todas as rotas exigem JWT e rejeitam operações em posts que não pertençam
 * ao usuário autenticado.
 */
class EditPostController extends Controller
{
    public function __construct(private readonly PostStorageService $storage)
    {
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
     * Localiza um post pertencente ao usuário autenticado; retorna null
     * caso não exista ou pertença a outro usuário.
     */
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

    /**
     * PUT /api/v1/posts/{id} — atualiza metadados do post.
     *
     * Campos aceitos: `title`, `description`, `status`.
     *  - Publicar: enviar `status=published` (limpa `draft_uuid`).
     *  - Despublicar (voltar a draft): enviar `status=draft`.
     *  - Somente `title`/`description`: mantém status atual.
     *
     * O título pode ficar vazio; a UI pode exibir um rótulo alternativo.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $post = $this->findOwnedPost($request, $id, $user);
        if (!$post) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $this->validate($request, [
            'title'       => 'sometimes|nullable|string|max:160',
            'description' => 'sometimes|nullable|string|max:2000',
            'status'      => 'sometimes|string|in:draft,published',
        ]);

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
                return response()->json([
                    'error'   => 'no_images_in_post',
                    'message' => 'O post precisa ter ao menos uma imagem para ser publicado.',
                ], 422);
            }
        }

        if ($request->has('status') && $targetStatus !== $post->status) {
            $updates['status'] = $targetStatus;
            // Ao publicar, limpa o draft_uuid. Ao voltar a draft, mantém null
            // (a associação com uploads passou a ser via post_id).
            $updates['draft_uuid'] = null;
        }

        if (empty($updates)) {
            return response()->json(['post' => $post->fresh()->load('images')]);
        }

        try {
            $post->update($updates);
            $finalStatus = (string) ($updates['status'] ?? $post->status);
            $touchGlobal = $originalStatus === Post::STATUS_PUBLISHED
                || $finalStatus === Post::STATUS_PUBLISHED;
            PostListCache::bump($this->userId($user), $touchGlobal);

            return response()->json(['post' => $post->fresh()->load('images')]);
        } catch (Throwable $e) {
            Log::error('edit-post/update failed', [
                'user_id'   => $this->userId($user),
                'post_id'   => $post->id,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            return response()->json([
                'error'   => 'update_post_failed',
                'message' => 'Falha ao atualizar o post.',
                'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * DELETE /api/v1/posts/{id} — remove post + imagens + arquivos do bucket.
     *
     * Estratégia: coleta as keys em memória, apaga os registros no banco
     * (cascade em `gallery`), depois remove os arquivos do storage em
     * modo best-effort. Falhas no storage são apenas logadas (arquivos
     * órfãos podem ser auditados em lote depois).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $post = $this->findOwnedPost($request, $id, $user);
        if (!$post) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $objectKeys = Gallery::query()
            ->where('post_id', $post->id)
            ->pluck('object_key')
            ->filter()
            ->values()
            ->all();

        try {
            DB::transaction(function () use ($post) {
                // A FK em gallery tem cascadeOnDelete; remover o post
                // remove também todas as galerias associadas.
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

            return response()->json([
                'error'   => 'delete_post_failed',
                'message' => 'Falha ao excluir o post.',
                'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }

        $disk = Storage::disk('s3');
        $logContext = [
            'user_id' => $this->userId($user),
            'post_id' => $post->id,
        ];
        foreach ($objectKeys as $key) {
            $this->storage->deleteImageFiles($disk, (string) $key, $logContext);
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /api/v1/posts/{id}/images — adiciona nova imagem a post existente.
     *
     * Similar ao upload do `CreatePostController`, mas alvo é um post já
     * existente (rascunho ou publicado). A posição recebida é a próxima
     * disponível (final da fila).
     */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $post = $this->findOwnedPost($request, $id, $user);
        if (!$post) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $this->validate($request, [
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:10240',
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file   = $request->file('file');
        $userId = $this->userId($user);
        $postId = (int) $post->id;

        $position = (int) Gallery::query()->where('post_id', $postId)->count();
        $disk     = Storage::disk('s3');

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

            $gallery = Gallery::create([
                'post_id'    => $postId,
                'user_id'    => $userId,
                'draft_uuid' => null,
                'object_key' => $result['key'],
                'mime_type'  => $result['mime'],
                'size'       => $result['size'],
                'position'   => $position,
            ]);
            $touchGlobal = $post->status === Post::STATUS_PUBLISHED;
            PostListCache::bump($userId, $touchGlobal);

            return response()->json(['gallery' => $gallery->fresh()], 201);
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

            return response()->json([
                'error'   => 'storage_upload_failed',
                'message' => 'Falha ao enviar imagem para o storage.',
                'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 502);
        }
    }

    /**
     * DELETE /api/v1/posts/{id}/images/{galleryId} — remove uma imagem
     * específica de um post (banco + storage).
     */
    public function destroyImage(Request $request, int $id, int $galleryId): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $post = $this->findOwnedPost($request, $id, $user);
        if (!$post) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $gallery = Gallery::query()
            ->where('id', $galleryId)
            ->where('post_id', $post->id)
            ->first();

        if (!$gallery) {
            return response()->json(['error' => 'image_not_found'], 404);
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

            return response()->json([
                'error'   => 'delete_image_failed',
                'message' => 'Falha ao remover a imagem.',
                'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }

        $disk = Storage::disk('s3');
        $this->storage->deleteImageFiles($disk, $objectKey, [
            'user_id'    => $this->userId($user),
            'post_id'    => $post->id,
            'gallery_id' => $galleryId,
        ]);

        return response()->json(['deleted' => true]);
    }

    /**
     * PUT /api/v1/posts/{id}/images/reorder — persiste a ordem das imagens
     * (`gallery.position`) conforme a fila exibida na edição.
     *
     * Body JSON: `{ "order": [galleryId, ...] }` — deve listar cada id de
     * `gallery` deste post exatamente uma vez, na ordem desejada.
     */
    public function reorderImages(Request $request, int $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $post = $this->findOwnedPost($request, $id, $user);
        if (!$post) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $this->validate($request, [
            'order'   => 'required|array',
            'order.*' => 'integer|distinct|min:1',
        ]);

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
            return response()->json([
                'error'   => 'order_mismatch',
                'message' => 'A lista de ids não corresponde às imagens deste post.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($order, $post): void {
                foreach ($order as $position => $galleryId) {
                    Gallery::query()
                        ->where('id', $galleryId)
                        ->where('post_id', $post->id)
                        ->update(['position' => $position]);
                }
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

            return response()->json([
                'error'   => 'reorder_failed',
                'message' => 'Falha ao reordenar imagens.',
                'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }

        return response()->json(['ok' => true]);
    }
}
