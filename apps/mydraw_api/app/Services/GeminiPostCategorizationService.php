<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Exceptions\GeminiApiException;
use App\Exceptions\OllamaApiException;
use App\Models\Gallery;
use App\Models\Post;
use App\Models\User;
use App\Support\AiCategorizationConfig;
use App\Support\AiSceneContextNormalizer;
use App\Support\AiTagNormalizer;
use App\Support\ContentRating;
use App\Support\OllamaConfig;
use App\Support\PostListCache;
use App\Support\SdPromptTermConcatenator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Uses Gemini or local Ollama for post categorization:
 * - Tags: one concatenated prompt for the post (visual tags + scene context); gallery.ai_tags untouched.
 * - Ratings: one AI call per distinct image prompt; each gallery gets its own ai_rating;
 *   post.content_rating is the most restrictive among images.
 */
class GeminiPostCategorizationService
{
    private const PROMPT_MAX_CHARS = 6000;

    /** Sleep between Gemini calls to reduce 429 pressure (seconds). */
    private const DEFAULT_REQUEST_SLEEP_SECONDS = 4;

    /** PHP wall-clock budget: base + per image (seconds), capped. */
    private const EXEC_TIME_BASE_SECONDS = 180;

    private const EXEC_TIME_PER_IMAGE_SECONDS = 90;

    private const EXEC_TIME_MAX_SECONDS = 1800;

    /** Per-request Gemini HTTP timeout when categorizing (seconds). */
    private const DEFAULT_IMAGE_REQUEST_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly GalleryMetadataExtractionService $galleryMetadataExtraction,
        private readonly GeminiGenerateContentClient $geminiClient,
        private readonly OllamaGenerateJsonClient $ollamaClient,
    ) {
    }

    /**
     * Raise PHP max_execution_time for categorization work.
     */
    public function raiseExecutionTimeBudget(int $imageCount): void
    {
        $seconds = min(
            self::EXEC_TIME_MAX_SECONDS,
            self::EXEC_TIME_BASE_SECONDS + max(1, $imageCount) * self::EXEC_TIME_PER_IMAGE_SECONDS
        );
        @ini_set('max_execution_time', (string) $seconds);
        @set_time_limit($seconds);
    }

    /**
     * @param  int[]|null  $postIdsScope
     * @param  bool  $processTags  When true, run AI tag pipeline and overwrite post ai_tags.
     * @param  bool  $processRatings  When true, run AI rating pipeline and overwrite post content_rating.
     *
     * @return array{
     *   skipped: bool,
     *   reason?: string,
     *   summary: array{
     *     posts_total: int,
     *     posts_with_updates: int,
     *     posts_skipped_no_prompt: int,
     *     posts_failed: int,
     *     tags_duration_ms: int|null,
     *     ratings_duration_ms: int|null,
     *     process_tags: bool,
     *     process_ratings: bool
     *   },
     *   errors: array<int, array{post_id: int, message: string, http_status: int}>
     * }
     */
    public function categorizeMissingForAllPosts(
        User $actor,
        ?array $postIdsScope = null,
        bool $processTags = true,
        bool $processRatings = true,
    ): array {
        $emptySummary = [
            'posts_total'             => 0,
            'posts_with_updates'      => 0,
            'posts_skipped_no_prompt' => 0,
            'posts_failed'            => 0,
            'tags_duration_ms'        => null,
            'ratings_duration_ms'     => null,
            'process_tags'            => $processTags,
            'process_ratings'         => $processRatings,
        ];

        if (!AiCategorizationConfig::isEnabled()) {
            return [
                'skipped' => true,
                'reason'  => 'ai_categorization_disabled',
                'summary' => $emptySummary,
                'errors'  => [],
            ];
        }

        $apiKey = (string) env('GEMINI_API_KEY', '');
        if (!OllamaConfig::prefer() && trim($apiKey) === '') {
            return [
                'skipped' => true,
                'reason'  => 'gemini_not_configured',
                'summary' => $emptySummary,
                'errors'  => [],
            ];
        }

        if (!$processTags && !$processRatings) {
            return [
                'skipped' => true,
                'reason'  => 'nothing_selected',
                'summary' => $emptySummary,
                'errors'  => [],
            ];
        }

        if ($postIdsScope !== null && $postIdsScope === []) {
            return [
                'skipped' => false,
                'summary' => $emptySummary,
                'errors'  => [],
            ];
        }

        if ($postIdsScope !== null) {
            $postIds = array_values(array_unique(array_map('intval', $postIdsScope)));
            sort($postIds);
        } else {
            $postIds = Post::query()
                ->whereNull('ai_categorized_at')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $summary = [
            'posts_total'             => count($postIds),
            'posts_with_updates'      => 0,
            'posts_skipped_no_prompt' => 0,
            'posts_failed'            => 0,
            'tags_duration_ms'        => $processTags ? 0 : null,
            'ratings_duration_ms'     => $processRatings ? 0 : null,
            'process_tags'            => $processTags,
            'process_ratings'         => $processRatings,
        ];

        $errors = [];
        $sleepSeconds = OllamaConfig::prefer()
            ? OllamaConfig::requestSleepSeconds()
            : $this->requestSleepSeconds();
        $llmCalls = 0;

        foreach ($postIds as $postId) {
            $post = Post::query()->find($postId);
            if (!$post) {
                continue;
            }

            try {
                if ($llmCalls > 0 && $sleepSeconds > 0) {
                    sleep($sleepSeconds);
                }

                $result  = $this->categorizePost(
                    $post,
                    $actor,
                    $processTags,
                    $processRatings,
                );
                $cat     = $result['summary']['categorization'];
                $updated = !empty($cat['post_updated']);

                if ((int) ($cat['gemini_calls'] ?? 0) > 0) {
                    $llmCalls++;
                }

                if ($processTags && isset($cat['tags_duration_ms']) && is_numeric($cat['tags_duration_ms'])) {
                    $summary['tags_duration_ms'] = (int) $summary['tags_duration_ms'] + (int) $cat['tags_duration_ms'];
                }
                if ($processRatings && isset($cat['ratings_duration_ms']) && is_numeric($cat['ratings_duration_ms'])) {
                    $summary['ratings_duration_ms'] = (int) $summary['ratings_duration_ms'] + (int) $cat['ratings_duration_ms'];
                }

                if ($updated) {
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
     *     categorization: array<string, mixed>
     *   },
     *   errors: array<int, array{error: string, message?: string}>,
     *   post: Post
     * }
     */
    public function categorizePost(
        Post $post,
        User $actor,
        bool $processTags = true,
        bool $processRatings = true,
    ): array {
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

        $this->raiseExecutionTimeBudget($images->count());

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

        $useOllama = OllamaConfig::prefer();
        $apiKey    = (string) env('GEMINI_API_KEY', '');
        if (!$useOllama && trim($apiKey) === '') {
            return $this->buildSkippedCategorizationResponse(
                $post,
                $metadataSummary,
                'gemini_not_configured'
            );
        }

        if (!$processTags && !$processRatings) {
            return $this->buildSkippedCategorizationResponse(
                $post,
                $metadataSummary,
                'nothing_selected'
            );
        }

        $model = $useOllama
            ? OllamaConfig::model()
            : (string) env('GEMINI_MODEL', 'gemini-2.0-flash');
        $requestTimeout = $useOllama
            ? OllamaConfig::requestTimeoutSeconds()
            : $this->imageRequestTimeoutSeconds();
        $sleepSeconds = $useOllama
            ? OllamaConfig::requestSleepSeconds()
            : $this->requestSleepSeconds();

        $existingTags = is_array($post->ai_tags) ? $post->ai_tags : [];
        $existingRating = is_string($post->content_rating) ? $post->content_rating : null;

        $applyTags = $processTags;
        $applyRating = $processRatings;

        $catSummary = [
            'total_with_prompt'           => 0,
            'updated'                     => 0,
            'skipped_missing_prompt'      => 0,
            'failed'                      => 0,
            'gemini_calls'                => 0,
            'prompts_reused'              => 0,
            'prompt_terms'                => 0,
            'concatenated_prompt_chars'   => 0,
            'prompt_tags'                 => 0,
            'context_tags'                => 0,
            'tags_duration_ms'            => $applyTags ? 0 : null,
            'ratings_duration_ms'         => $applyRating ? 0 : null,
            'post_content_rating'         => $existingRating,
            'post_updated'                => false,
            'process_tags'                => $applyTags,
            'process_ratings'             => $applyRating,
            'reprocess_tags'              => $applyTags,
            'reprocess_ratings'           => $applyRating,
            'provider'                    => $useOllama ? 'ollama' : 'gemini',
            'model'                       => $model,
        ];

        $rawPrompts = [];
        foreach ($images as $g) {
            $prompt = trim((string) ($g->metadata_prompt ?? ''));
            if ($prompt !== '') {
                $rawPrompts[] = $prompt;
                $catSummary['total_with_prompt']++;
            }
        }

        if ($rawPrompts === []) {
            $catSummary['skipped_missing_prompt'] = $images->count();

            return [
                'summary' => [
                    'metadata_extraction' => $metadataSummary,
                    'categorization'      => $catSummary,
                ],
                'errors' => [
                    [
                        'error'   => 'missing_prompt',
                        'message' => 'No image in this post has a metadata prompt.',
                    ],
                ],
                'post' => $post,
            ];
        }

        $concatenated = SdPromptTermConcatenator::concatenate($rawPrompts, self::PROMPT_MAX_CHARS);
        $catSummary['concatenated_prompt_chars'] = mb_strlen($concatenated);
        $catSummary['prompt_terms'] = $concatenated === ''
            ? 0
            : count(array_filter(array_map('trim', explode(',', $concatenated))));

        if ($applyTags && $concatenated === '') {
            $catSummary['skipped_missing_prompt'] = count($rawPrompts);

            return [
                'summary' => [
                    'metadata_extraction' => $metadataSummary,
                    'categorization'      => $catSummary,
                ],
                'errors' => [
                    [
                        'error'   => 'empty_concatenated_prompt',
                        'message' => 'Prompt terms were empty after stripping SD controls.',
                    ],
                ],
                'post' => $post,
            ];
        }

        $errors = [];
        $promptTags = [];
        $contextTags = [];
        $callsDone = 0;
        /** @var array<int, array{rating: string, rating_reason: string|null}> $perImageRatings */
        $perImageRatings = [];

        try {
            if ($applyTags) {
                $tagsStarted = hrtime(true);

                $decoded = $this->generateJson(
                    $useOllama,
                    $model,
                    $apiKey,
                    $this->buildTagsOnlyUserPayload($concatenated),
                    $requestTimeout
                );
                $callsDone++;

                $parsedTags = $this->parseTagsOnlyResponse($decoded);
                if ($parsedTags === null || $parsedTags === []) {
                    throw new ApiException(
                        502,
                        'AI categorization returned invalid tags.',
                        ['error' => 'ai_invalid_payload']
                    );
                }
                $promptTags = AiTagNormalizer::normalizeList($parsedTags);
                $catSummary['prompt_tags'] = count($promptTags);

                if ($promptTags === []) {
                    throw new ApiException(
                        502,
                        'AI categorization returned invalid tags.',
                        ['error' => 'ai_invalid_payload']
                    );
                }

                if ($sleepSeconds > 0) {
                    sleep($sleepSeconds);
                }

                try {
                    $contextDecoded = $this->generateJson(
                        $useOllama,
                        $model,
                        $apiKey,
                        $this->buildSceneContextUserPayload($concatenated),
                        $requestTimeout
                    );
                    $callsDone++;
                    $contextTags = AiSceneContextNormalizer::flattenToTags($contextDecoded);
                    $catSummary['context_tags'] = count($contextTags);

                    if ($contextTags === []) {
                        $errors[] = [
                            'error'   => 'context_tags_empty',
                            'message' => 'Scene-context AI call returned no usable tags.',
                        ];
                    }
                } catch (GeminiApiException|OllamaApiException $e) {
                    $status = $e->getHttpStatus();
                    if (in_array($status, [401, 403, 429, 504], true)) {
                        throw $e;
                    }
                    $errors[] = [
                        'error'   => $e instanceof OllamaApiException ? 'ollama_context_failed' : 'gemini_context_failed',
                        'message' => mb_substr($e->getMessage(), 0, 240),
                    ];
                } catch (Throwable $e) {
                    $errors[] = [
                        'error'   => 'context_tags_failed',
                        'message' => mb_substr($e->getMessage(), 0, 240),
                    ];
                }

                $catSummary['tags_duration_ms'] = (int) round((hrtime(true) - $tagsStarted) / 1e6);
            }

            if ($applyRating) {
                if ($callsDone > 0 && $sleepSeconds > 0) {
                    sleep($sleepSeconds);
                }

                $ratingsStarted = hrtime(true);
                /** @var array<string, array{rating: string, rating_reason: string|null}> $ratingCache */
                $ratingCache = [];
                $ratingCallsThisPass = 0;

                foreach ($images as $g) {
                    $galleryId = (int) $g->id;
                    $rawPrompt = trim((string) ($g->metadata_prompt ?? ''));
                    if ($rawPrompt === '') {
                        $catSummary['skipped_missing_prompt']++;
                        $errors[] = [
                            'gallery_id' => $galleryId,
                            'error'      => 'missing_prompt',
                        ];
                        continue;
                    }

                    // Clean controls per image, but do NOT merge with other images.
                    $cleaned = SdPromptTermConcatenator::concatenate([$rawPrompt], self::PROMPT_MAX_CHARS);
                    if ($cleaned === '') {
                        $catSummary['skipped_missing_prompt']++;
                        $errors[] = [
                            'gallery_id' => $galleryId,
                            'error'      => 'empty_cleaned_prompt',
                        ];
                        continue;
                    }

                    $cacheKey = hash('sha256', $cleaned);

                    try {
                        if (isset($ratingCache[$cacheKey])) {
                            $parsed = $ratingCache[$cacheKey];
                            $catSummary['prompts_reused']++;
                        } else {
                            if ($ratingCallsThisPass > 0 && $sleepSeconds > 0) {
                                sleep($sleepSeconds);
                            }

                            $ratingDecoded = $this->generateJson(
                                $useOllama,
                                $model,
                                $apiKey,
                                $this->buildRatingOnlyUserPayload($cleaned),
                                $requestTimeout
                            );
                            $callsDone++;
                            $ratingCallsThisPass++;

                            $parsedRating = $this->parseRatingOnlyResponse($ratingDecoded);
                            if ($parsedRating === null) {
                                throw new ApiException(
                                    502,
                                    'AI categorization returned invalid rating.',
                                    ['error' => 'ai_invalid_payload']
                                );
                            }

                            $oneRating = $this->sanitizeRating((string) $parsedRating['rating']);
                            if ($oneRating === null) {
                                throw new ApiException(
                                    502,
                                    'AI categorization returned invalid rating.',
                                    ['error' => 'ai_invalid_payload']
                                );
                            }

                            $oneReason = mb_substr((string) ($parsedRating['rating_reason'] ?? ''), 0, 512);
                            $oneReason = $oneReason !== '' ? $oneReason : null;

                            $parsed = [
                                'rating'        => $oneRating,
                                'rating_reason' => $oneReason,
                            ];
                            $ratingCache[$cacheKey] = $parsed;
                        }

                        $galleryRow = Gallery::query()
                            ->where('post_id', $post->id)
                            ->whereKey($galleryId)
                            ->first();

                        if ($galleryRow) {
                            $galleryRow->ai_rating = $parsed['rating'];
                            $galleryRow->ai_rating_reason = $parsed['rating_reason'];
                            $galleryRow->ai_categorized_at = Carbon::now();
                            $galleryRow->save();
                            $catSummary['updated']++;
                            $perImageRatings[$galleryId] = $parsed;
                        }
                    } catch (ApiException $e) {
                        $catSummary['failed']++;
                        $data = $e->getData();
                        $errorCode = is_array($data) ? (string) ($data['error'] ?? 'api_error') : 'api_error';
                        $errors[] = [
                            'gallery_id' => $galleryId,
                            'error'      => $errorCode,
                            'message'    => mb_substr($e->getMessage(), 0, 240),
                        ];
                        $code = $e->getStatusCode();
                        if (in_array($code, [401, 403, 429, 504], true)) {
                            throw $e;
                        }
                    } catch (GeminiApiException $e) {
                        $catSummary['failed']++;
                        $status = $e->getHttpStatus();
                        $msg    = $e->getMessage();
                        if ($status === 429) {
                            throw new ApiException(429, $msg, ['error' => 'gemini_quota_exceeded']);
                        }
                        if ($status === 401 || $status === 403) {
                            throw new ApiException($status, $msg, ['error' => 'gemini_auth_failed']);
                        }
                        if ($status === 504) {
                            throw new ApiException(504, $msg, ['error' => 'gemini_timeout']);
                        }
                        $errors[] = [
                            'gallery_id' => $galleryId,
                            'error'      => 'gemini_api_error',
                            'message'    => mb_substr($msg, 0, 240),
                        ];
                    } catch (OllamaApiException $e) {
                        $catSummary['failed']++;
                        $status = $e->getHttpStatus();
                        $msg    = $e->getMessage();
                        if ($status === 504) {
                            throw new ApiException(504, $msg, ['error' => 'ollama_timeout']);
                        }
                        $errors[] = [
                            'gallery_id' => $galleryId,
                            'error'      => 'ollama_api_error',
                            'message'    => mb_substr($msg, 0, 240),
                        ];
                    } catch (Throwable $e) {
                        $catSummary['failed']++;
                        $errors[] = [
                            'gallery_id' => $galleryId,
                            'error'      => 'unexpected_error',
                            'message'    => mb_substr($e->getMessage(), 0, 240),
                        ];
                    }
                }

                $catSummary['ratings_duration_ms'] = (int) round((hrtime(true) - $ratingsStarted) / 1e6);
            }

            $catSummary['gemini_calls'] = $callsDone;

            $finalTags = $applyTags
                ? AiTagNormalizer::unionSortedUnique([$promptTags, $contextTags])
                : AiTagNormalizer::normalizeList(array_map('strval', $existingTags));

            $finalRating = null;
            if ($applyRating) {
                $ratingValues = [];
                foreach ($perImageRatings as $row) {
                    $ratingValues[] = $row['rating'];
                }
                $finalRating = ContentRating::max($ratingValues);
                if ($finalRating === null) {
                    throw new ApiException(
                        502,
                        'AI categorization produced no valid per-image ratings.',
                        ['error' => 'gemini_invalid_payload']
                    );
                }
            } elseif ($existingRating !== null && ContentRating::isAllowed($existingRating)) {
                $finalRating = $existingRating;
            }

            if ($applyTags && $finalTags === []) {
                throw new ApiException(
                    502,
                    'AI categorization produced incomplete tags.',
                    ['error' => 'gemini_invalid_payload']
                );
            }

            if ($applyTags || ($applyRating && $finalRating !== null)) {
                try {
                    DB::transaction(function () use (
                        $post,
                        $finalTags,
                        $finalRating,
                        $applyTags,
                        $applyRating
                    ): void {
                        $payload = [
                            'ai_categorized_at' => Carbon::now(),
                        ];
                        if ($applyTags) {
                            $payload['ai_tags'] = $finalTags;
                        }
                        if ($applyRating && $finalRating !== null) {
                            $payload['content_rating'] = $finalRating;
                        }
                        $post->update($payload);
                    });
                } catch (Throwable $e) {
                    $msg = trim($e->getMessage()) !== ''
                        ? mb_substr($e->getMessage(), 0, 280)
                        : '';

                    throw new ApiException(
                        500,
                        'Failed to save post categorization.'.($msg !== '' ? ' '.$msg : ''),
                        [
                            'error'  => 'persist_failed',
                            'detail' => $msg !== '' ? $msg : null,
                        ]
                    );
                }

                $catSummary['post_updated'] = true;
                $catSummary['post_content_rating'] = $finalRating ?? $existingRating;
                if ($applyTags && !$applyRating) {
                    $catSummary['updated'] = max(1, (int) $catSummary['updated']);
                }

                $touchGlobal = $post->status === Post::STATUS_PUBLISHED;
                PostListCache::bump((int) $post->user_id, $touchGlobal);
            }
        } catch (ApiException $e) {
            $catSummary['failed'] = max(1, (int) $catSummary['failed']);
            $data = $e->getData();
            $errorCode = is_array($data) ? (string) ($data['error'] ?? 'api_error') : 'api_error';
            $errors[] = [
                'error'   => $errorCode,
                'message' => mb_substr($e->getMessage(), 0, 240),
            ];

            $code = $e->getStatusCode();
            if (in_array($code, [401, 403, 429, 504], true)) {
                throw $e;
            }
        } catch (GeminiApiException $e) {
            $catSummary['failed'] = max(1, (int) $catSummary['failed']);
            $status = $e->getHttpStatus();
            $msg    = $e->getMessage();

            if ($status === 429) {
                throw new ApiException(429, $msg, ['error' => 'gemini_quota_exceeded']);
            }
            if ($status === 401 || $status === 403) {
                throw new ApiException($status, $msg, ['error' => 'gemini_auth_failed']);
            }
            if ($status === 504) {
                throw new ApiException(504, $msg, ['error' => 'gemini_timeout']);
            }

            $errors[] = [
                'error'   => 'gemini_api_error',
                'message' => mb_substr($msg, 0, 240),
            ];
        } catch (OllamaApiException $e) {
            $catSummary['failed'] = max(1, (int) $catSummary['failed']);
            $status = $e->getHttpStatus();
            $msg    = $e->getMessage();

            if ($status === 504) {
                throw new ApiException(504, $msg, ['error' => 'ollama_timeout']);
            }

            $errors[] = [
                'error'   => 'ollama_api_error',
                'message' => mb_substr($msg, 0, 240),
            ];
        } catch (Throwable $e) {
            $catSummary['failed'] = max(1, (int) $catSummary['failed']);
            $errors[] = [
                'error'   => 'unexpected_error',
                'message' => mb_substr($e->getMessage(), 0, 240),
            ];
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

    /**
     * @return array{
     *   summary: array{
     *     metadata_extraction: array<string, int>|null,
     *     categorization: array{
     *       total_with_prompt: int,
     *       updated: int,
     *       skipped_missing_prompt: int,
     *       failed: int,
     *       gemini_calls: int,
     *       prompts_reused: int,
     *       prompt_terms: int,
     *       concatenated_prompt_chars: int,
     *       post_content_rating: string|null,
     *       post_updated: bool,
     *       skipped: bool,
     *       reason: string
     *     }
     *   },
     *   errors: array<int, array{error: string}>,
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
                    'total_with_prompt'         => 0,
                    'updated'                   => 0,
                    'skipped_missing_prompt'    => 0,
                    'failed'                    => 0,
                    'gemini_calls'              => 0,
                    'prompts_reused'            => 0,
                    'prompt_terms'              => 0,
                    'concatenated_prompt_chars' => 0,
                    'prompt_tags'               => 0,
                    'context_tags'              => 0,
                    'tags_duration_ms'          => null,
                    'ratings_duration_ms'       => null,
                    'post_content_rating'       => null,
                    'post_updated'              => false,
                    'process_tags'              => false,
                    'process_ratings'           => false,
                    'reprocess_tags'            => false,
                    'reprocess_ratings'         => false,
                    'provider'                  => OllamaConfig::prefer() ? 'ollama' : 'gemini',
                    'model'                     => OllamaConfig::prefer()
                        ? OllamaConfig::model()
                        : (string) env('GEMINI_MODEL', 'gemini-2.0-flash'),
                    'skipped'                   => true,
                    'reason'                    => $reason,
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

    private function imageRequestTimeoutSeconds(): int
    {
        return max(
            30,
            min(
                240,
                (int) env(
                    'GEMINI_CATEGORIZE_IMAGE_TIMEOUT_SECONDS',
                    self::DEFAULT_IMAGE_REQUEST_TIMEOUT_SECONDS
                )
            )
        );
    }

    private function requestSleepSeconds(): int
    {
        return max(
            0,
            min(
                120,
                (int) env('GEMINI_REQUEST_SLEEP_SECONDS', self::DEFAULT_REQUEST_SLEEP_SECONDS)
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function generateJson(
        bool $useOllama,
        string $model,
        string $apiKey,
        string $userText,
        int $requestTimeout
    ): array {
        if ($useOllama) {
            return $this->ollamaClient->generateJson($model, $userText, $requestTimeout);
        }

        return $this->geminiClient->generateJson($model, $apiKey, $userText, $requestTimeout);
    }

    private function buildTagsOnlyUserPayload(string $prompt): string
    {
        $clipped = mb_substr($prompt, 0, self::PROMPT_MAX_CHARS);
        $lines = [
            'You label a Stable Diffusion generation for a gallery website.',
            'Read ONLY the positive prompt text below (already cleaned: comma-separated visual terms).',
            'Output concise English search tags (semantic/visual subjects, style, setting).',
            'Tag rules:',
            '- Tags MUST be English.',
            '- Use clear short phrases; punctuation and spacing are allowed.',
            '- No duplicates. Avoid vague tags (masterpiece, best quality, detailed, etc.).',
            '- Do NOT include sampler/steps/seed/model hashes/LoRA filenames.',
            '- Do NOT output rating labels as tags.',
            '- Prefer 8–20 tags when the prompt is rich; fewer if short.',
            '',
            'Respond with JSON ONLY matching this exact shape (no markdown, no extra keys):',
            '{"tags":["tag-one","tag-two"]}',
            '',
            'Prompt:',
            json_encode(['prompt' => $clipped], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        return implode("\n", $lines);
    }

    private function buildRatingOnlyUserPayload(string $prompt): string
    {
        $clipped = mb_substr($prompt, 0, self::PROMPT_MAX_CHARS);
        $lines = [
            'You rate a Stable Diffusion generation for a gallery website.',
            'Read ONLY the positive prompt text below (already cleaned: comma-separated visual terms).',
            'Assign one audience rating similar to Civitai: PG, PG-13, R, X, or XXX.',
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
            '{"rating":"PG-13","rating_reason":"..."}',
            '',
            'Prompt:',
            json_encode(['prompt' => $clipped], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        return implode("\n", $lines);
    }

    /**
     * Second AI pass: franchise/work, characters, relationship, and scene actions → tags.
     */
    private function buildSceneContextUserPayload(string $prompt): string
    {
        $clipped = mb_substr($prompt, 0, self::PROMPT_MAX_CHARS);
        $lines = [
            'You identify franchise/work, characters, their relationship, and scene actions from a Stable Diffusion prompt.',
            'Read ONLY the positive prompt text below (already cleaned: comma-separated visual terms).',
            'Infer what is clearly supported by the prompt. Prefer well-known English names when recognizable.',
            'If something is unclear, omit it (do not invent). Use empty string or empty arrays when unknown.',
            '',
            'Return JSON ONLY with this exact shape (no markdown, no extra keys):',
            '{"work":"The Flintstones","characters":["Fred Flintstone","Wilma Flintstone"],"relationship":"husband and wife","scene":["sex","kiss","riding"]}',
            '',
            'Field rules:',
            '- work: franchise, series, movie, game, or IP name (English). Empty string if original/unknown.',
            '- characters: named characters involved (English). Empty array if none are clear.',
            '- relationship: short English phrase for how characters relate (e.g. "father and daughter", "rivals"). Empty string if unclear.',
            '- scene: 1–8 short English action/theme terms describing what happens (e.g. sex, kiss, fight, hug).',
            '- Altogether these fields should yield about 4–12 searchable tags when flattened.',
            '- Do NOT include rating labels, quality words, or LoRA/model filenames.',
            '',
            'Prompt:',
            json_encode(['prompt' => $clipped], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return string[]|null
     */
    private function parseTagsOnlyResponse(array $decoded): ?array
    {
        if (isset($decoded['tags']) && is_array($decoded['tags'])) {
            return array_map('strval', $decoded['tags']);
        }

        $row = $this->parseSinglePostModelResponse($decoded);
        if ($row === null) {
            return null;
        }

        return $row['tags'];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{rating: string, rating_reason: string}|null
     */
    private function parseRatingOnlyResponse(array $decoded): ?array
    {
        if (isset($decoded['rating'])) {
            return [
                'rating'        => trim((string) $decoded['rating']),
                'rating_reason' => isset($decoded['rating_reason']) ? (string) $decoded['rating_reason'] : '',
            ];
        }

        $row = $this->parseSinglePostModelResponse($decoded);
        if ($row === null) {
            return null;
        }

        return [
            'rating'        => $row['rating'],
            'rating_reason' => $row['rating_reason'],
        ];
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
