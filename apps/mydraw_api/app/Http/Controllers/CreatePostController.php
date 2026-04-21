<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\Post;
use App\Models\User;
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
                'url'      => $disk->url($key),
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
     * Fluxo: (1) valida → (2) envia ao MinIO → (3) confirma com headers do
     * objeto → (4) grava Gallery. Se qualquer etapa falhar, tenta remover o
     * objeto remoto e retorna 502 com detalhes (em APP_DEBUG).
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

        $position = (int) Gallery::query()
            ->where('draft_uuid', $draftUuid)
            ->where('user_id', $userId)
            ->whereNull('post_id')
            ->count();

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $key = sprintf('posts/%d/%s/%s.%s', $userId, $draftUuid, Str::uuid(), $ext);

        $disk     = Storage::disk('s3');
        $uploaded = false;

        try {
            // 1) Envia o arquivo ao MinIO (lança exceção em caso de falha).
            $stream = fopen($file->getRealPath(), 'rb');
            if ($stream === false) {
                return response()->json([
                    'error'   => 'upload_failed',
                    'message' => 'Não foi possível ler o arquivo recebido.',
                ], 500);
            }

            $disk->put($key, $stream, [
                'visibility'  => 'public',
                'ContentType' => $file->getMimeType(),
            ]);

            if (is_resource($stream)) {
                fclose($stream);
            }

            // 2) Confirma no bucket antes de gravar no banco.
            if (!$disk->exists($key)) {
                throw new \RuntimeException('Objeto não encontrado no bucket após upload.');
            }

            $uploaded = true;

            // 3) Só agora persiste no banco.
            $gallery = Gallery::create([
                'post_id'    => null,
                'user_id'    => $userId,
                'draft_uuid' => $draftUuid,
                'object_key' => $key,
                'mime_type'  => $file->getMimeType(),
                'size'       => $file->getSize(),
                'position'   => $position,
            ]);

            return response()->json(['gallery' => $gallery->fresh()], 201);
        } catch (Throwable $e) {
            Log::error('create-post/upload failed', [
                'user_id'    => $userId,
                'draft_uuid' => $draftUuid,
                'object_key' => $key,
                'endpoint'   => env('AWS_ENDPOINT'),
                'bucket'     => env('AWS_BUCKET'),
                'uploaded'   => $uploaded,
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
            ]);

            // Compensação: se o objeto chegou ao bucket mas falhou ao gravar
            // no banco, remove o arquivo remoto para não ficar órfão.
            if ($uploaded) {
                try {
                    $disk->delete($key);
                } catch (Throwable $cleanupErr) {
                    Log::warning('cleanup after failed upload also failed', [
                        'object_key' => $key,
                        'message'    => $cleanupErr->getMessage(),
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
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!$user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $this->validate($request, [
            'draft_uuid'  => 'required|uuid',
            'title'       => 'required|string|max:160',
            'description' => 'nullable|string|max:2000',
        ]);

        $userId    = $this->userId($user);
        $draftUuid = $request->input('draft_uuid');

        $pending = Gallery::query()
            ->where('draft_uuid', $draftUuid)
            ->where('user_id', $userId)
            ->whereNull('post_id');

        if ($pending->count() === 0) {
            return response()->json(['error' => 'no_images_in_draft'], 422);
        }

        try {
            $post = DB::transaction(function () use ($request, $userId, $draftUuid) {
                $post = Post::create([
                    'user_id'     => $userId,
                    'title'       => $request->input('title'),
                    'description' => $request->input('description'),
                ]);

                Gallery::query()
                    ->where('draft_uuid', $draftUuid)
                    ->where('user_id', $userId)
                    ->whereNull('post_id')
                    ->update([
                        'post_id'    => $post->id,
                        'draft_uuid' => null,
                    ]);

                return $post;
            });

            return response()->json(['post' => $post->fresh()->load('images')], 201);
        } catch (Throwable $e) {
            Log::error('create-post/store failed', [
                'user_id'    => $userId,
                'draft_uuid' => $draftUuid,
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
