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
use Throwable;

class PostReorderService
{
    /**
     * @return array{posts: array<int, array<string, mixed>>}
     */
    public function listForReorder(Request $request): array
    {
        $this->ensureAuthenticatedAdmin($request);

        $posts = Post::query()
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->leftJoin('users', 'users.Id', '=', 'posts.user_id')
            ->where('users.IsAdmin', true)
            ->select('posts.id', 'posts.title', 'posts.sort_order')
            ->orderBy('posts.sort_order')
            ->orderBy('posts.id')
            ->get();

        $postIds = $posts->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $coversByPostId = [];

        if ($postIds !== []) {
            $covers = Gallery::query()
                ->whereIn('post_id', $postIds)
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            foreach ($covers as $cover) {
                $pid = (int) $cover->post_id;
                if (!isset($coversByPostId[$pid])) {
                    $coversByPostId[$pid] = $cover;
                }
            }
        }

        $items = [];
        foreach ($posts as $post) {
            $cover = $coversByPostId[(int) $post->id] ?? null;
            $thumb = $cover instanceof Gallery ? $cover->thumbUrl(320, 320) : null;

            $items[] = [
                'id'         => (int) $post->id,
                'title'      => (string) $post->title,
                'sort_order' => (int) $post->sort_order,
                'cover_url'  => $thumb,
            ];
        }

        return ['posts' => $items];
    }

    /**
     * @return array{ok: bool}
     */
    public function reorder(Request $request): array
    {
        $user = $this->ensureAuthenticatedAdmin($request);

        /** @var int[] $order */
        $order = array_map('intval', $request->input('order', []));

        $existingIds = Post::query()
            ->where('posts.status', Post::STATUS_PUBLISHED)
            ->leftJoin('users', 'users.Id', '=', 'posts.user_id')
            ->where('users.IsAdmin', true)
            ->orderBy('posts.id')
            ->pluck('posts.id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();

        $sortedRequested = $order;
        sort($sortedRequested);

        if ($existingIds !== $sortedRequested) {
            throw new ApiException(
                422,
                'A lista de ids não corresponde aos posts publicados.',
                [
                    'error'   => 'order_mismatch',
                    'message' => 'A lista de ids não corresponde aos posts publicados.',
                ]
            );
        }

        try {
            DB::transaction(function () use ($order): void {
                if ($order === []) {
                    return;
                }

                $caseFragments = [];
                $bindings      = [];
                foreach ($order as $position => $postId) {
                    $caseFragments[] = 'WHEN ? THEN ?';
                    $bindings[]      = $postId;
                    $bindings[]      = $position + 1;
                }

                $placeholders = implode(',', array_fill(0, count($order), '?'));
                $bindings[] = Post::STATUS_PUBLISHED;
                foreach ($order as $postId) {
                    $bindings[] = $postId;
                }

                $sql = 'UPDATE posts SET sort_order = CASE id '
                    .implode(' ', $caseFragments)
                    .' END WHERE status = ? AND id IN ('.$placeholders.')';

                DB::update($sql, $bindings);
            });

            PostListCache::bump($this->userId($user), true);
        } catch (Throwable $e) {
            Log::error('posts/reorder failed', [
                'user_id'   => $this->userId($user),
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            throw new ApiException(
                500,
                'Falha ao reordenar posts.',
                [
                    'error'   => 'reorder_failed',
                    'message' => 'Falha ao reordenar posts.',
                    'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
                ]
            );
        }

        return ['ok' => true];
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
