<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Exceptions\GeminiApiException;
use App\Models\Gallery;
use App\Models\Post;
use App\Models\User;
use App\Support\AiTagNormalizer;
use App\Support\ContentRating;
use App\Support\PostListCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uses Gemini to derive English tags and a Civitai-style rating from embedded prompts.
 * Runs SD metadata extraction first when rows still lack stored metadata.
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
     * @return array{
     *   summary: array{
     *     metadata_extraction: array<string, int>|null,
     *     categorization: array{
     *       total_with_prompt: int,
     *       updated: int,
     *       skipped_missing_prompt: int,
     *       failed: int
     *     }
     *   },
     *   errors: array<int, array{gallery_id: int, error: string}>,
     *   post: Post
     * }
     */
    public function categorizePost(Post $post, User $actor): array
    {
        $apiKey = (string) env('GEMINI_API_KEY', '');
        if (trim($apiKey) === '') {
            throw new ApiException(
                503,
                'AI categorization is not configured.',
                ['error' => 'gemini_not_configured']
            );
        }

        $model = (string) env('GEMINI_MODEL', 'gemini-2.0-flash');

        $post->load([
            'images' => static function ($q): void {
                $q->orderBy('position')->orderBy('id');
            },
        ]);

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
            $extracted                   = $this->galleryMetadataExtraction->extractMissingForPost($post, $actor);
            $metadataSummary             = $extracted['summary'];
            $post                        = $extracted['post'];
            $images                      = $post->images;
        }

        $missingPrompt = [];
        $toAnalyze     = [];

        foreach ($images as $g) {
            $prompt = $this->normalizePrompt($g->metadata_prompt ?? null);
            if ($prompt === '') {
                $missingPrompt[] = (int) $g->id;

                continue;
            }
            $toAnalyze[] = [
                'gallery_id' => (int) $g->id,
                'prompt'     => mb_substr($prompt, 0, self::PROMPT_MAX_CHARS),
            ];
        }

        $errors = [];
        foreach ($missingPrompt as $gid) {
            $errors[] = [
                'gallery_id' => $gid,
                'error'      => 'missing_prompt',
            ];
        }

        $catSummary = [
            'total_with_prompt'       => count($toAnalyze),
            'updated'                 => 0,
            'skipped_missing_prompt'  => count($missingPrompt),
            'failed'                  => 0,
        ];

        if ($toAnalyze === []) {
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
            $userPrompt = $this->buildGeminiUserPayload($toAnalyze);
        } catch (Throwable $e) {
            throw new ApiException(
                500,
                'Failed to build AI request payload.',
                ['error' => 'payload_build_failed']
            );
        }

        try {
            $decoded = $this->geminiClient->generateJson($model, $apiKey, $userPrompt);
        } catch (GeminiApiException $e) {
            Log::error('gemini categorization: api failed', [
                'post_id'     => $post->id,
                'http_status' => $e->getHttpStatus(),
            ]);

            $status = $e->getHttpStatus();
            $msg    = $e->getMessage();

            if ($status === 429) {
                throw new ApiException(
                    429,
                    $msg,
                    ['error' => 'gemini_quota_exceeded']
                );
            }

            if ($status === 401 || $status === 403) {
                throw new ApiException(
                    502,
                    $msg,
                    ['error' => 'gemini_auth_failed']
                );
            }

            throw new ApiException(
                502,
                $msg,
                [
                    'error'  => 'gemini_request_failed',
                    'detail' => env('APP_DEBUG') && $status > 0 ? 'HTTP '.$status : null,
                ]
            );
        } catch (Throwable $e) {
            Log::error('gemini categorization: api failed', [
                'post_id'   => $post->id,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            throw new ApiException(
                502,
                'AI categorization request failed.',
                [
                    'error'  => 'gemini_request_failed',
                    'detail' => env('APP_DEBUG') ? $e->getMessage() : null,
                ]
            );
        }

        $parsed = $this->parseModelResponse($decoded, $toAnalyze);
        if ($parsed === null) {
            throw new ApiException(
                502,
                'AI categorization returned invalid JSON.',
                ['error' => 'gemini_invalid_payload']
            );
        }

        [$byGalleryId, $parseErrors] = $parsed;
        foreach ($parseErrors as $pe) {
            $errors[]                   = $pe;
            $catSummary['failed']++;
        }

        $now = Carbon::now();

        try {
            DB::transaction(function () use ($byGalleryId, $now, $post, &$catSummary, &$errors): void {
                foreach ($byGalleryId as $galleryId => $row) {
                    $gallery = Gallery::query()
                        ->where('post_id', $post->id)
                        ->whereKey($galleryId)
                        ->first();

                    if (!$gallery) {
                        $errors[] = [
                            'gallery_id' => $galleryId,
                            'error'      => 'not_found',
                        ];
                        $catSummary['failed']++;

                        continue;
                    }

                    $tags = AiTagNormalizer::normalizeList($row['tags']);

                    $rating = $this->sanitizeRating((string) $row['rating']);

                    if ($tags === [] || $rating === null) {
                        $errors[] = [
                            'gallery_id' => $galleryId,
                            'error'      => 'invalid_model_row',
                        ];
                        $catSummary['failed']++;

                        continue;
                    }

                    $reason = mb_substr((string) $row['rating_reason'], 0, 512);

                    $gallery->update([
                        'ai_tags'             => $tags,
                        'ai_rating'           => $rating,
                        'ai_rating_reason'    => $reason !== '' ? $reason : null,
                        'ai_categorized_at'   => $now,
                    ]);
                    $catSummary['updated']++;
                }
            });
        } catch (Throwable $e) {
            Log::error('gemini categorization: persist failed', [
                'post_id'   => $post->id,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            throw new ApiException(
                500,
                'Failed to save categorization.',
                [
                    'error'   => 'persist_failed',
                    'detail'  => env('APP_DEBUG') ? $e->getMessage() : null,
                ]
            );
        }

        if ($catSummary['updated'] > 0) {
            $freshPost = Post::query()->find((int) $post->id);
            if ($freshPost) {
                $this->aggregatePostAiFieldsFromGallery($freshPost);
            }
        }

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
     * @param  array<int, array{gallery_id: int, prompt: string}>  $items
     */
    private function buildGeminiUserPayload(array $items): string
    {
        $lines = [
            'You label Stable Diffusion generations for a gallery website.',
            'Task:',
            '1) For each gallery_id, read ONLY the positive prompt text.',
            '2) Output concise English search tags (semantic/visual subjects, style, setting).',
            '3) Assign an audience rating similar to Civitai: PG, PG-13, R, X, or XXX.',
            'Tag rules:',
            '- Tags MUST be English.',
            '- Lowercase kebab-case words separated by hyphens (example: cyberpunk-city).',
            '- No duplicates. No vague tags (masterpiece, best quality, detailed, etc.).',
            '- Do NOT include sampler/steps/seed/model hashes/LoRA filenames.',
            '- Do NOT output rating labels as tags.',
            '- Prefer 8–20 tags per image when the prompt is rich; fewer if the prompt is short.',
            'Rating rules:',
            '- Base the rating ONLY on prompt semantics (themes like violence, nudity, sexual content).',
            '- PG: generally safe, non-sexual, mild fantasy/action.',
            '- PG-13: suggestive themes or moderate violence without explicit sexual acts.',
            '- R: strong violence or clearly sexual themes without explicit graphic portrayal.',
            '- X: explicit sexual content described without extreme/taboo framing.',
            '- XXX: extreme/taboo sexual content when explicitly described.',
            '- Provide a short rating_reason (max ~240 chars, English).',
            '',
            'Respond with JSON ONLY matching this shape:',
            '{"images":[{"gallery_id":123,"tags":["tag-one"],"rating":"PG-13","rating_reason":"..."}]}',
            '',
            'Input items as JSON:',
            json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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

        // Common variants without strict uppercase match
        $lower = strtolower($r);
        if ($lower === 'pg-13' || $lower === 'pg_13' || $lower === 'pg13') {
            return ContentRating::PG_13;
        }

        $trimmed = trim($raw);

        return ContentRating::isAllowed($trimmed) ? $trimmed : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<int, array{gallery_id: int, prompt: string}>  $expected
     * @return array{0: array<int, array{tags: string[], rating: string, rating_reason: string}>, 1: array<int, array{gallery_id: int, error: string}>}|null
     */
    private function parseModelResponse(array $decoded, array $expected): ?array
    {
        $images = $decoded['images'] ?? null;
        if (!is_array($images)) {
            return null;
        }

        $expectedIds = [];
        foreach ($expected as $row) {
            $expectedIds[(int) $row['gallery_id']] = true;
        }

        $byId       = [];
        $errors     = [];

        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }

            $gid = isset($img['gallery_id']) ? (int) $img['gallery_id'] : 0;
            if ($gid <= 0 || !isset($expectedIds[$gid])) {
                continue;
            }

            $tags = $img['tags'] ?? [];
            if (!is_array($tags)) {
                $tags = [];
            }

            $rating       = isset($img['rating']) ? trim((string) $img['rating']) : '';
            $ratingReason = isset($img['rating_reason']) ? (string) $img['rating_reason'] : '';

            $byId[$gid] = [
                'tags'           => array_map('strval', $tags),
                'rating'         => $rating,
                'rating_reason'  => $ratingReason,
            ];
        }

        foreach (array_keys($expectedIds) as $gid) {
            if (!isset($byId[$gid])) {
                $errors[] = [
                    'gallery_id' => (int) $gid,
                    'error'      => 'missing_from_model',
                ];
            }
        }

        return [$byId, $errors];
    }

    private function aggregatePostAiFieldsFromGallery(Post $post): void
    {
        $rows = Gallery::query()
            ->where('post_id', $post->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['ai_tags', 'ai_rating']);

        $unionTags = [];
        $ratings   = [];

        foreach ($rows as $row) {
            /** @var Gallery $row */
            $tags = $row->ai_tags;
            if (is_array($tags)) {
                foreach ($tags as $t) {
                    $unionTags[] = (string) $t;
                }
            }
            $r = $row->ai_rating;
            if (is_string($r) && $r !== '') {
                $ratings[] = $r;
            }
        }

        $mergedTags = AiTagNormalizer::unionSortedUnique([$unionTags]);

        $maxRating = ContentRating::max($ratings);

        $post->update([
            'ai_tags'           => $mergedTags !== [] ? $mergedTags : null,
            'content_rating'    => $maxRating,
            'ai_categorized_at' => Carbon::now(),
        ]);

        $touchGlobal = $post->status === Post::STATUS_PUBLISHED;
        PostListCache::bump((int) $post->user_id, $touchGlobal);
    }
}
