<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Exceptions\GeminiApiException;
use App\Models\Gallery;
use App\Models\Post;
use App\Models\User;
use App\Support\AiTagNormalizer;
use App\Support\AiCategorizationConfig;
use App\Support\ContentRating;
use App\Support\PostListCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Uses Gemini to derive English tags and a Civitai-style rating from embedded prompts.
 * One API call per post, based on the first eligible image prompt only.
 */
class GeminiPostCategorizationService
{
    private const PROMPT_MAX_CHARS = 6000;

    public function __construct(
        private readonly GalleryMetadataExtractionService $galleryMetadataExtraction,
        private readonly GeminiGenerateContentClient $geminiClient,
    ) {
    }

    /**
     * @param  int[]|null  $postIdsScope
     *
     * @return array{
     *   skipped: bool,
     *   reason?: string,
     *   summary: array{
     *     posts_total: int,
     *     posts_with_updates: int,
     *     posts_skipped_no_prompt: int,
     *     posts_failed: int
     *   },
     *   errors: array<int, array{post_id: int, message: string, http_status: int}>
     * }
     */
    public function categorizeMissingForAllPosts(User $actor, ?array $postIdsScope = null): array
    {
        if (!AiCategorizationConfig::isEnabled()) {
            return [
                'skipped' => true,
                'reason'  => 'ai_categorization_disabled',
                'summary' => [
                    'posts_total'             => 0,
                    'posts_with_updates'      => 0,
                    'posts_skipped_no_prompt' => 0,
                    'posts_failed'            => 0,
                ],
                'errors' => [],
            ];
        }

        $apiKey = (string) env('GEMINI_API_KEY', '');
        if (trim($apiKey) === '') {
            return [
                'skipped' => true,
                'reason'  => 'gemini_not_configured',
                'summary' => [
                    'posts_total'             => 0,
                    'posts_with_updates'      => 0,
                    'posts_skipped_no_prompt' => 0,
                    'posts_failed'            => 0,
                ],
                'errors' => [],
            ];
        }

        if ($postIdsScope !== null && $postIdsScope === []) {
            return [
                'skipped' => false,
                'summary' => [
                    'posts_total'             => 0,
                    'posts_with_updates'      => 0,
                    'posts_skipped_no_prompt' => 0,
                    'posts_failed'            => 0,
                ],
                'errors' => [],
            ];
        }

        if ($postIdsScope !== null) {
            $postIds = array_values(array_unique(array_map('intval', $postIdsScope)));
            sort($postIds);
        } else {
            $postIds = Gallery::query()
                ->whereNull('ai_categorized_at')
                ->distinct()
                ->orderBy('post_id')
                ->pluck('post_id')
                ->map(static fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $summary = [
            'posts_total'             => count($postIds),
            'posts_with_updates'      => 0,
            'posts_skipped_no_prompt' => 0,
            'posts_failed'            => 0,
        ];

        $errors = [];

        foreach ($postIds as $postId) {
            $post = Post::query()->find($postId);
            if (!$post) {
                continue;
            }

            try {
                $result  = $this->categorizePost($post, $actor, true);
                $cat     = $result['summary']['categorization'];
                $updated = (int) ($cat['updated'] ?? 0);

                if ($updated > 0) {
                    $summary['posts_with_updates']++;
                } else {
                    $summary['posts_skipped_no_prompt']++;
                }
            } catch (ApiException $e) {
                $summary['posts_failed']++;
                $errors[] = [
                    'post_id'     => $postId,
                    'message'     => $e->getMessage(),
                    'http_status' => $e->getStatusCode(),
                ];
            } catch (Throwable $e) {
                $summary['posts_failed']++;
                $errors[] = [
                    'post_id'     => $postId,
                    'message'     => mb_substr($e->getMessage(), 0, 300),
                    'http_status' => 500,
                ];
            }
        }

        return [
            'skipped' => false,
            'summary' => $summary,
            'errors'  => $errors,
        ];
    }

    /**
     * @return array{
     *   summary: array{
     *     metadata_extraction: array<string, int>|null,
     *     categorization: array{
     *       total_with_prompt: int,
     *       updated: int,
     *       skipped_missing_prompt: int,
     *       failed: int,
     *       source_gallery_id: int|null
     *     }
     *   },
     *   errors: array<int, array{gallery_id: int, error: string}>,
     *   post: Post
     * }
     */
    public function categorizePost(Post $post, User $actor, bool $onlyUncategorizedGalleryRows = false): array
    {
        $post->load([
            'images' => static function ($q): void {
                $q->orderBy('position')->orderBy('id');
            },
        ]);

        /** @var Collection<int, Gallery> $images */
        $images = $post->images;
        if ($images->isEmpty()) {
            throw new ApiException(
                422,
                'Post has no images.',
                ['error' => 'no_images_in_post']
            );
        }

        $metadataSummary = null;
        if ($this->needsEmbeddedMetadataExtraction($post->id)) {
            $extracted       = $this->galleryMetadataExtraction->extractMissingForPost($post, $actor);
            $metadataSummary = $extracted['summary'];
            $post            = $extracted['post'];
            $images          = $post->images;
        }

        if (!AiCategorizationConfig::isEnabled()) {
            return $this->buildSkippedCategorizationResponse(
                $post,
                $metadataSummary,
                'ai_categorization_disabled'
            );
        }

        $apiKey = (string) env('GEMINI_API_KEY', '');
        if (trim($apiKey) === '') {
            return $this->buildSkippedCategorizationResponse(
                $post,
                $metadataSummary,
                'gemini_not_configured'
            );
        }

        $model = (string) env('GEMINI_MODEL', 'gemini-2.0-flash');

        $errors        = [];
        $missingPrompt = [];

        foreach ($images as $g) {
            if (!$onlyUncategorizedGalleryRows || $g->ai_categorized_at === null) {
                if ($this->normalizePrompt($g->metadata_prompt ?? null) === '') {
                    $missingPrompt[] = (int) $g->id;
                }
            }
        }

        $source = $this->selectSourceGallery($images, $onlyUncategorizedGalleryRows);

        $catSummary = [
            'total_with_prompt'      => $source !== null ? 1 : 0,
            'updated'                => 0,
            'skipped_missing_prompt' => count($missingPrompt),
            'failed'                 => 0,
            'source_gallery_id'      => $source !== null ? $source['gallery_id'] : null,
        ];

        foreach ($missingPrompt as $gid) {
            $errors[] = [
                'gallery_id' => $gid,
                'error'      => 'missing_prompt',
            ];
        }

        if ($source === null) {
            return [
                'summary' => [
                    'metadata_extraction' => $metadataSummary,
                    'categorization'      => $catSummary,
                ],
                'errors'  => $errors,
                'post'    => $post->fresh()->load([
                    'images' => static function ($q): void {
                        $q->orderBy('position')->orderBy('id');
                    },
                ]),
            ];
        }

        try {
            $userPrompt = $this->buildGeminiUserPayload($source['prompt']);
        } catch (Throwable $e) {
            throw new ApiException(
                500,
                'Failed to build AI request payload.'.(
                    trim($e->getMessage()) !== ''
                    ? ' '.mb_substr($e->getMessage(), 0, 240)
                    : ''
                ),
                ['error' => 'payload_build_failed']
            );
        }

        try {
            $decoded = $this->geminiClient->generateJson($model, $apiKey, $userPrompt);
        } catch (GeminiApiException $e) {
            $status = $e->getHttpStatus();
            $msg    = $e->getMessage();

            if ($status === 429) {
                throw new ApiException(429, $msg, ['error' => 'gemini_quota_exceeded']);
            }

            if ($status === 401 || $status === 403) {
                throw new ApiException(502, $msg, ['error' => 'gemini_auth_failed']);
            }

            if ($status === 504) {
                throw new ApiException(
                    504,
                    $msg,
                    ['error' => 'gemini_timeout']
                );
            }

            throw new ApiException(
                502,
                $msg,
                [
                    'error'  => 'gemini_request_failed',
                    'detail' => $status > 0 ? 'HTTP '.$status : $msg,
                ]
            );
        } catch (Throwable $e) {
            $detail = trim($e->getMessage());
            if ($detail === '') {
                $detail = 'Unknown error during Gemini request.';
            }
            if (mb_strlen($detail) > 480) {
                $detail = mb_substr($detail, 0, 477).'…';
            }

            throw new ApiException(
                502,
                $detail,
                [
                    'error'           => 'gemini_request_failed',
                    'detail'          => $detail,
                    'exception_class' => env('APP_DEBUG') ? get_class($e) : null,
                ]
            );
        }

        $parsedRow = $this->parseSinglePostModelResponse($decoded);
        if ($parsedRow === null) {
            throw new ApiException(
                502,
                'AI categorization returned invalid JSON.',
                ['error' => 'gemini_invalid_payload']
            );
        }

        $tags = AiTagNormalizer::normalizeList($parsedRow['tags']);
        $rating = $this->sanitizeRating((string) $parsedRow['rating']);

        if ($tags === [] || $rating === null) {
            throw new ApiException(
                502,
                'AI categorization returned invalid tags or rating.',
                ['error' => 'gemini_invalid_payload']
            );
        }

        $reason = mb_substr((string) $parsedRow['rating_reason'], 0, 512);
        $reason = $reason !== '' ? $reason : null;

        $targetIds = $this->targetGalleryIds($images, $onlyUncategorizedGalleryRows);
        $now         = Carbon::now();

        try {
            DB::transaction(function () use (
                $post,
                $targetIds,
                $tags,
                $rating,
                $reason,
                $now,
                &$catSummary,
            ): void {
                $galleryPayload = [
                    'ai_tags'           => $tags,
                    'ai_rating'         => $rating,
                    'ai_rating_reason'  => $reason,
                    'ai_categorized_at' => $now,
                ];

                foreach ($targetIds as $galleryId) {
                    $updated = Gallery::query()
                        ->where('post_id', $post->id)
                        ->whereKey($galleryId)
                        ->update($galleryPayload);

                    if ($updated > 0) {
                        $catSummary['updated']++;
                    }
                }

                $post->update([
                    'ai_tags'           => $tags,
                    'content_rating'    => $rating,
                    'ai_categorized_at' => $now,
                ]);
            });
        } catch (Throwable $e) {
            $msg = trim($e->getMessage()) !== ''
                ? mb_substr($e->getMessage(), 0, 280)
                : '';

            throw new ApiException(
                500,
                'Failed to save categorization.'.($msg !== '' ? ' '.$msg : ''),
                [
                    'error'  => 'persist_failed',
                    'detail' => $msg !== '' ? $msg : null,
                ]
            );
        }

        $touchGlobal = $post->status === Post::STATUS_PUBLISHED;
        PostListCache::bump((int) $post->user_id, $touchGlobal);

        $finalPost = Post::query()
            ->with([
                'images' => static function ($q): void {
                    $q->orderBy('position')->orderBy('id');
                },
            ])
            ->find((int) $post->id);

        return [
            'summary' => [
                'metadata_extraction' => $metadataSummary,
                'categorization'      => $catSummary,
            ],
            'errors'  => $errors,
            'post'    => $finalPost ?? $post,
        ];
    }

    /**
     * @return array{
     *   summary: array{
     *     metadata_extraction: array<string, int>|null,
     *     categorization: array{
     *       total_with_prompt: int,
     *       updated: int,
     *       skipped_missing_prompt: int,
     *       failed: int,
     *       source_gallery_id: int|null,
     *       skipped: bool,
     *       reason: string
     *     }
     *   },
     *   errors: array<int, array{gallery_id: int, error: string}>,
     *   post: Post|null
     * }
     */
    private function buildSkippedCategorizationResponse(
        Post $post,
        ?array $metadataSummary,
        string $reason
    ): array {
        return [
            'summary' => [
                'metadata_extraction' => $metadataSummary,
                'categorization'      => [
                    'total_with_prompt'      => 0,
                    'updated'                => 0,
                    'skipped_missing_prompt' => 0,
                    'failed'                 => 0,
                    'source_gallery_id'      => null,
                    'skipped'                => true,
                    'reason'                 => $reason,
                ],
            ],
            'errors' => [],
            'post'   => $post->fresh()->load([
                'images' => static function ($q): void {
                    $q->orderBy('position')->orderBy('id');
                },
            ]),
        ];
    }

    private function needsEmbeddedMetadataExtraction(int $postId): bool
    {
        return Gallery::query()
            ->where('post_id', $postId)
            ->whereNull('metadata_type')
            ->exists();
    }

    private function normalizePrompt(?string $prompt): string
    {
        if ($prompt === null) {
            return '';
        }

        return trim($prompt);
    }

    /**
     * First pending image with prompt; else first image in post order with prompt.
     *
     * @param  Collection<int, Gallery>  $images
     * @return array{gallery_id: int, prompt: string}|null
     */
    private function selectSourceGallery(Collection $images, bool $onlyUncategorized): ?array
    {
        foreach ($images as $g) {
            if ($onlyUncategorized && $g->ai_categorized_at !== null) {
                continue;
            }
            $prompt = $this->normalizePrompt($g->metadata_prompt ?? null);
            if ($prompt !== '') {
                return [
                    'gallery_id' => (int) $g->id,
                    'prompt'     => mb_substr($prompt, 0, self::PROMPT_MAX_CHARS),
                ];
            }
        }

        foreach ($images as $g) {
            $prompt = $this->normalizePrompt($g->metadata_prompt ?? null);
            if ($prompt !== '') {
                return [
                    'gallery_id' => (int) $g->id,
                    'prompt'     => mb_substr($prompt, 0, self::PROMPT_MAX_CHARS),
                ];
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Gallery>  $images
     * @return int[]
     */
    private function targetGalleryIds(Collection $images, bool $onlyUncategorized): array
    {
        $ids = [];
        foreach ($images as $g) {
            if ($onlyUncategorized && $g->ai_categorized_at !== null) {
                continue;
            }
            $ids[] = (int) $g->id;
        }

        return $ids;
    }

    private function buildGeminiUserPayload(string $prompt): string
    {
        $lines = [
            'You label a Stable Diffusion generation for a gallery website.',
            'Read ONLY the positive prompt text below.',
            'Output concise English search tags (semantic/visual subjects, style, setting).',
            'Assign one audience rating similar to Civitai: PG, PG-13, R, X, or XXX.',
            'Tag rules:',
            '- Tags MUST be English.',
            '- Use clear short phrases; punctuation and spacing are allowed.',
            '- No duplicates. Avoid vague tags (masterpiece, best quality, detailed, etc.).',
            '- Do NOT include sampler/steps/seed/model hashes/LoRA filenames.',
            '- Do NOT output rating labels as tags.',
            '- Prefer 8–20 tags when the prompt is rich; fewer if short.',
            'Rating rules:',
            '- Base the rating ONLY on prompt semantics (violence, nudity, sexual content).',
            '- PG: generally safe, non-sexual, mild fantasy/action.',
            '- PG-13: suggestive themes or moderate violence without explicit sexual acts.',
            '- R: strong violence or clearly sexual themes without explicit graphic portrayal.',
            '- X: explicit sexual content without extreme/taboo framing.',
            '- XXX: extreme/taboo sexual content when explicitly described.',
            '- Provide a short rating_reason (max ~240 chars, English).',
            '',
            'Respond with JSON ONLY matching this exact shape (no markdown, no extra keys):',
            '{"tags":["tag-one"],"rating":"PG-13","rating_reason":"..."}',
            '',
            'Prompt:',
            json_encode(['prompt' => $prompt], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        return implode("\n", $lines);
    }

    private function sanitizeRating(string $raw): ?string
    {
        $r = strtoupper(trim($raw));
        $aliases = [
            'PG_13' => ContentRating::PG_13,
            'PG13'  => ContentRating::PG_13,
            'PG-13' => ContentRating::PG_13,
            'PG'    => ContentRating::PG,
            'R'     => ContentRating::R,
            'X'     => ContentRating::X,
            'XXX'   => ContentRating::XXX,
        ];

        if (isset($aliases[$r])) {
            return $aliases[$r];
        }

        $lower = strtolower($r);
        if ($lower === 'pg-13' || $lower === 'pg_13' || $lower === 'pg13') {
            return ContentRating::PG_13;
        }

        $trimmed = trim($raw);

        return ContentRating::isAllowed($trimmed) ? $trimmed : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{tags: string[], rating: string, rating_reason: string}|null
     */
    private function parseSinglePostModelResponse(array $decoded): ?array
    {
        if (isset($decoded['tags']) || isset($decoded['rating'])) {
            return $this->normalizeModelRow($decoded);
        }

        $images = $decoded['images'] ?? null;
        if (is_array($images) && $images !== []) {
            $first = $images[0];
            if (is_array($first)) {
                return $this->normalizeModelRow($first);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{tags: string[], rating: string, rating_reason: string}|null
     */
    private function normalizeModelRow(array $row): ?array
    {
        $tags = $row['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }

        $rating       = isset($row['rating']) ? trim((string) $row['rating']) : '';
        $ratingReason = isset($row['rating_reason']) ? (string) $row['rating_reason'] : '';

        if ($rating === '') {
            return null;
        }

        return [
            'tags'          => array_map('strval', $tags),
            'rating'        => $rating,
            'rating_reason' => $ratingReason,
        ];
    }
}
