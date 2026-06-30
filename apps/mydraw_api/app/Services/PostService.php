<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Gallery;
use App\Models\Post;
use App\Models\User;
use App\Support\PostListCache;
use App\Support\StorageObjectUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PostService
{
    /**
     * Lista paginada / por offset (payload igual ao legado, vai dentro de Data).
     */
    public function index(Request $request): array
    {
        $status = $this->resolveStatus($request);
        if ($status === null) {
            throw new ApiException(422, 'Invalid status.', ['error' => 'invalid_status']);
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 50));
        $pageNumber = (int) $request->input('page', 1);
        $pageNumber = max(1, $pageNumber);
        $hasOffsetParam = $request->query('offset') !== null && $request->query('offset') !== '';
        $offset = $hasOffsetParam ? max(0, (int) $request->query('offset')) : null;
        $includeCounts = filter_var(
            (string) $request->input('include_counts', '0'),
            FILTER_VALIDATE_BOOLEAN
        );
        $cacheTtl = max(0, (int) env('POSTS_LIST_CACHE_TTL_SECONDS', 15));

        $user = null;
        if ($status === Post::STATUS_DRAFT || $includeCounts) {
            $user = $this->authUser($request);
        }

        if ($status === Post::STATUS_DRAFT && !$user) {
            throw new ApiException(401, 'Unauthorized.', ['error' => 'unauthorized']);
        }
        if ($status === Post::STATUS_DRAFT && !$this->isAdmin($user)) {
            throw new ApiException(403, 'Forbidden.', ['error' => 'forbidden']);
        }

        $sort = $this->resolveSort($request);

        $cacheKey = $this->buildListCacheKey(
            $status,
            $sort,
            $perPage,
            $pageNumber,
            $includeCounts,
            $user ? (int) $user->getKey() : null,
            PostListCache::globalVersion(),
            PostListCache::userVersion($user ? (int) $user->getKey() : null),
            $offset
        );

        if ($cacheTtl > 0) {
            return Cache::remember(
                $cacheKey,
                Carbon::now()->addSeconds($cacheTtl),
                function () use ($status, $sort, $perPage, $includeCounts, $user, $pageNumber, $offset) {
                    return $this->queryListPayload($status, $sort, $perPage, $includeCounts, $user, $pageNumber, $offset);
                }
            );
        }

        return $this->queryListPayload($status, $sort, $perPage, $includeCounts, $user, $pageNumber, $offset);
    }

    /**
     * @return array{post: Post}
     */
    public function show(Request $request, int $id): array
    {
        $post = Post::query()
            ->with([
                'user:Id,Name,IsAdmin,AvatarKey',
                'images' => function ($q) {
                    $q->orderBy('position')->orderBy('id');
                },
            ])
            ->find($id);

        if (!$post) {
            throw new ApiException(404, 'Not found.', ['error' => 'not_found']);
        }

        if (!$this->isAdmin($post->user)) {
            throw new ApiException(404, 'Not found.', ['error' => 'not_found']);
        }

        if ($post->status === Post::STATUS_DRAFT) {
            $user = $this->authUser($request);
            if (!$user || (int) $post->user_id !== (int) $user->getKey()) {
                throw new ApiException(404, 'Not found.', ['error' => 'not_found']);
            }
        }

        return ['post' => $post];
    }

    private function authUser(Request $request): ?User
    {
        $user = $request->input('auth_user');

        return $user instanceof User ? $user : null;
    }

    private function isAdmin(?User $user): bool
    {
        return $user instanceof User && $user->is_admin;
    }

    /**
     * Monta o query builder da listagem (filtros, joins, eager load, ordenação).
     */
    private function buildPostListQuery(string $status, string $sort, ?User $user): Builder
    {
        $query = Post::query()->where('status', $status);

        if ($status === Post::STATUS_PUBLISHED) {
            $query
                ->leftJoin('users', 'users.Id', '=', 'posts.user_id')
                ->where('users.IsAdmin', true)
                ->select('posts.*')
                ->selectRaw('users.Name as author_name')
                ->selectRaw('users.AvatarKey as author_avatar_key');
        }

        if ($status === Post::STATUS_DRAFT) {
            if ($sort === 'oldest') {
                $query->orderBy('posts.created_at', 'asc');
            } else {
                $query->orderByDesc('posts.created_at');
            }
        } elseif ($sort === 'oldest') {
            $query->orderByDesc('posts.sort_order')->orderByDesc('posts.id');
        } else {
            $query->orderBy('posts.sort_order')->orderBy('posts.id');
        }

        $with = [
            'images' => function ($q) {
                $q->orderBy('position')->orderBy('id');
            },
        ];

        if ($status === Post::STATUS_DRAFT && $this->isAdmin($user)) {
            $query->where('user_id', (int) $user->getKey());
            $with['user'] = function ($q): void {
                $q->select('Id', 'Name', 'AvatarKey', 'IsAdmin');
            };
        }

        $query->with($with);

        return $query;
    }

    /**
     * @param  Collection<int, Post>|iterable<Post>  $posts
     */
    private function transformPostsForList(iterable $posts): void
    {
        foreach ($posts as $post) {
            if (!$post instanceof Post) {
                continue;
            }

            $post->images->each(function (Gallery $image): void {
                $thumb320 = $image->thumbUrl(320, 320);
                $image->setAppends([]);
                $image->setAttribute('thumbs', ['320x320' => $thumb320]);
                $image->setAttribute('url', $thumb320);
            });

            $attrs = $post->getAttributes();
            if (array_key_exists('author_avatar_key', $attrs)) {
                $post->setAttribute(
                    'author_avatar_url',
                    StorageObjectUrl::urlForKey($attrs['author_avatar_key'] ?: null),
                );
                $post->offsetUnset('author_avatar_key');
            }
        }
    }

    private function queryListPayload(
        string $status,
        string $sort,
        int $perPage,
        bool $includeCounts,
        ?User $user,
        int $pageNumber,
        ?int $offset
    ): array {
        $query = $this->buildPostListQuery($status, $sort, $user);

        if ($offset !== null) {
            /** @var Collection<int, Post> $chunk */
            $chunk = $query->skip($offset)->take($perPage + 1)->get();
            $hasMore = $chunk->count() > $perPage;
            $pageItems = $chunk->take($perPage);
            $this->transformPostsForList($pageItems);

            $counts = $includeCounts ? $this->resolveCounts($user) : null;

            return $this->buildOffsetListPayload($pageItems->values()->all(), $perPage, $hasMore, $counts);
        }

        $page = $query->simplePaginate($perPage, ['*'], 'page', $pageNumber);
        $this->transformPostsForList($page->items());

        $counts = $includeCounts ? $this->resolveCounts($user) : null;

        return $this->buildListPayload($page, $counts);
    }

    /**
     * @param  array<int, Post>  $data
     */
    private function buildOffsetListPayload(array $data, int $perPage, bool $hasMore, ?array $counts): array
    {
        $payload = [
            'data'           => $data,
            'current_page'   => 1,
            'per_page'       => $perPage,
            'has_more'       => $hasMore,
            'first_page_url' => null,
            'next_page_url'  => null,
            'path'           => null,
            'prev_page_url'  => null,
        ];
        if ($counts !== null) {
            $payload['counts'] = $counts;
        }

        return $payload;
    }

    private function buildListCacheKey(
        string $status,
        string $sort,
        int $perPage,
        int $page,
        bool $includeCounts,
        ?int $userId,
        int $globalVersion,
        int $userVersion,
        ?int $offset
    ): string {
        $userPart = $userId !== null ? (string) $userId : 'guest';
        $cursorPart = $offset !== null ? 'o'.$offset : 'p'.$page;

        return sprintf(
            'posts:list:v8:%s:sort:%s:%s:pp%s:counts%s:u%s:gv%s:uv%s',
            $status,
            $sort,
            $cursorPart,
            $perPage,
            $includeCounts ? '1' : '0',
            $userPart,
            $globalVersion,
            $userVersion
        );
    }

    private function resolveSort(Request $request): string
    {
        $raw = strtolower((string) $request->query('sort', 'newest'));

        return in_array($raw, ['newest', 'oldest'], true) ? $raw : 'newest';
    }

    private function resolveCounts(?User $user): array
    {
        if ($this->isAdmin($user)) {
            $userId = (int) $user->getKey();
            $row = Post::query()
                ->leftJoin('users', 'users.Id', '=', 'posts.user_id')
                ->selectRaw(
                    'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS published_count,
                     SUM(CASE WHEN status = ? AND user_id = ? THEN 1 ELSE 0 END) AS draft_count',
                    [Post::STATUS_PUBLISHED, Post::STATUS_DRAFT, $userId]
                )
                ->where('users.IsAdmin', true)
                ->first();

            return [
                'published' => (int) ($row?->published_count ?? 0),
                'draft'     => (int) ($row?->draft_count ?? 0),
            ];
        }

        return [
            'published' => (int) Post::query()
                ->leftJoin('users', 'users.Id', '=', 'posts.user_id')
                ->where('status', Post::STATUS_PUBLISHED)
                ->where('users.IsAdmin', true)
                ->count(),
            'draft'     => 0,
        ];
    }

    private function buildListPayload(Paginator $page, ?array $counts): array
    {
        $payload = $page->toArray();
        if ($counts !== null) {
            $payload['counts'] = $counts;
        }
        $payload['has_more'] = $page->hasMorePages();

        return $payload;
    }

    private function resolveStatus(Request $request): ?string
    {
        $raw = $request->input('status');
        if ($raw === null || $raw === '') {
            return Post::STATUS_PUBLISHED;
        }

        $allowed = [Post::STATUS_PUBLISHED, Post::STATUS_DRAFT];

        return in_array($raw, $allowed, true) ? $raw : null;
    }
}
