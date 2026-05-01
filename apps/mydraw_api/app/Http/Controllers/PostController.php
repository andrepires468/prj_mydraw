<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\Post;
use App\Models\User;
use App\Support\PostListCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PostController extends Controller
{
    /**
     * Retorna o usuário autenticado injetado pelo middleware `auth.jwt` /
     * `auth.jwt.optional`, se houver.
     */
    private function authUser(Request $request): ?User
    {
        $user = $request->input('auth_user');

        return $user instanceof User ? $user : null;
    }

    /**
     * GET /api/v1/posts?status=published|draft — listagem paginada.
     *
     *  - `status=published` (padrão): público, todos os posts publicados.
     *  - `status=draft`: requer autenticação JWT; retorna apenas os drafts
     *    do usuário autenticado (ninguém pode ver drafts de outros).
     *  - `sort=newest|oldest` (padrão `newest`): ordenação por `created_at`.
     *  - `offset` (opcional): quando informado, ignora `page` e retorna
     *    `per_page` itens a partir desse deslocamento (mesma ordenação).
     *    A resposta inclui `has_more`. Sem `offset`, vale `page` + `per_page`
     *    e `has_more` reflete `simplePaginate`.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $this->resolveStatus($request);
        if ($status === null) {
            return response()->json(['error' => 'invalid_status'], 422);
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
            return response()->json(['error' => 'unauthorized'], 401);
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
            $payload = Cache::remember(
                $cacheKey,
                Carbon::now()->addSeconds($cacheTtl),
                function () use ($status, $sort, $perPage, $includeCounts, $user, $pageNumber, $offset) {
                    return $this->queryListPayload($status, $sort, $perPage, $includeCounts, $user, $pageNumber, $offset);
                }
            );
        } else {
            $payload = $this->queryListPayload($status, $sort, $perPage, $includeCounts, $user, $pageNumber, $offset);
        }

        return response()->json($payload);
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
                ->select('posts.*')
                ->selectRaw('users.Name as author_name');
        }

        if ($sort === 'oldest') {
            $query->orderBy('posts.created_at', 'asc');
        } else {
            $query->orderByDesc('posts.created_at');
        }

        $query->with([
            'images' => function ($q) {
                $q->orderBy('position')->orderBy('id');
            },
        ]);

        if ($status === Post::STATUS_DRAFT && $user) {
            $query->where('user_id', (int) $user->getKey());
        }

        return $query;
    }

    /**
     * Ajusta imagens da listagem (só thumb 320 no payload).
     *
     * @param  Collection<int, Post>|iterable<Post>  $posts
     */
    private function transformPostsForList(iterable $posts): void
    {
        foreach ($posts as $post) {
            if (!$post instanceof Post) {
                continue;
            }

            $post->images->each(function (Gallery $image): void {
                // Em listagem precisamos só da thumb principal do card (320x320).
                // Evita assinar URL original + thumbs extras para cada item.
                $thumb320 = $image->thumbUrl(320, 320);
                $image->setAppends([]);
                $image->setAttribute('thumbs', ['320x320' => $thumb320]);
                $image->setAttribute('url', $thumb320);
            });
        }
    }

    /**
     * Executa a consulta paginada e monta o payload serializado.
     *
     * Quando `offset` é informado, ignora `page` e retorna `per_page` itens a
     * partir desse deslocamento (útil para “10 + 12 + 12…” na galeria).
     */
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
            'data'          => $data,
            'current_page'  => 1,
            'per_page'      => $perPage,
            'has_more'      => $hasMore,
            'first_page_url'=> null,
            'next_page_url' => null,
            'path'          => null,
            'prev_page_url' => null,
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
            'posts:list:v5:%s:sort:%s:%s:pp%s:counts%s:u%s:gv%s:uv%s',
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

    /**
     * Ordenação da listagem: `newest` (padrão) ou `oldest`.
     */
    private function resolveSort(Request $request): string
    {
        $raw = strtolower((string) $request->query('sort', 'newest'));

        return in_array($raw, ['newest', 'oldest'], true) ? $raw : 'newest';
    }

    /**
     * Monta as contagens exibidas no toggle da galeria.
     */
    private function resolveCounts(?User $user): array
    {
        $draft = 0;
        if ($user) {
            $userId = (int) $user->getKey();
            $row = Post::query()
                ->selectRaw(
                    'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS published_count,
                     SUM(CASE WHEN status = ? AND user_id = ? THEN 1 ELSE 0 END) AS draft_count',
                    [Post::STATUS_PUBLISHED, Post::STATUS_DRAFT, $userId]
                )
                ->first();

            return [
                'published' => (int) ($row?->published_count ?? 0),
                'draft'     => (int) ($row?->draft_count ?? 0),
            ];
        }

        return [
            'published' => (int) Post::query()
                ->where('status', Post::STATUS_PUBLISHED)
                ->count(),
            'draft'     => 0,
        ];
    }

    /**
     * Injeta `counts` no payload paginado padrão.
     */
    private function buildListPayload(Paginator $page, ?array $counts): array
    {
        $payload = $page->toArray();
        if ($counts !== null) {
            $payload['counts'] = $counts;
        }
        $payload['has_more'] = $page->hasMorePages();

        return $payload;
    }

    /**
     * GET /api/v1/posts/{id}
     *
     * Posts publicados são visíveis a todos; drafts só ao próprio autor
     * autenticado. Caso contrário, 404 para não vazar existência.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $post = Post::query()
            ->with([
                'user:Id,Name',
                'images' => function ($q) {
                    $q->orderBy('position')->orderBy('id');
                },
            ])
            ->find($id);

        if (!$post) {
            return response()->json(['error' => 'not_found'], 404);
        }

        if ($post->status === Post::STATUS_DRAFT) {
            $user = $this->authUser($request);
            if (!$user || (int) $post->user_id !== (int) $user->getKey()) {
                return response()->json(['error' => 'not_found'], 404);
            }
        }

        return response()->json(['post' => $post]);
    }

    /**
     * Normaliza o parâmetro `status` do query string; retorna null quando
     * o valor é inválido. Default é `published`.
     */
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
