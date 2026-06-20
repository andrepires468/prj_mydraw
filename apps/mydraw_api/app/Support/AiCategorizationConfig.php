<?php

namespace App\Support;

/**
 * Feature flag for Gemini-based post/image tagging and content rating.
 */
final class AiCategorizationConfig
{
    public static function isEnabled(): bool
    {
        $raw = env('AI_CATEGORIZATION_ENABLED', true);

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            return (int) $raw !== 0;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? true;
    }
}
