<?php

namespace App\Support;

/**
 * Builds a compact comma-separated prompt for AI tagging by extracting Stable Diffusion
 * terms and dropping control syntax / duplicates across one or more image prompts.
 */
final class SdPromptTermConcatenator
{
    private const MAX_CHARS = 6000;

    /**
     * @param  string[]  $prompts  Raw positive prompts (e.g. gallery.metadata_prompt).
     */
    public static function concatenate(array $prompts, int $maxChars = self::MAX_CHARS): string
    {
        $ordered = [];
        $seen    = [];

        foreach ($prompts as $prompt) {
            if (!is_string($prompt) || trim($prompt) === '') {
                continue;
            }

            foreach (self::extractTerms($prompt) as $term) {
                $key = self::dedupeKey($term);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $ordered[]  = $term;
            }
        }

        $joined = implode(', ', $ordered);
        if ($joined === '') {
            return '';
        }

        $maxChars = max(64, $maxChars);
        if (mb_strlen($joined) <= $maxChars) {
            return $joined;
        }

        return rtrim(mb_substr($joined, 0, $maxChars), " \t,");
    }

    /**
     * @return string[] Clean display terms in encounter order (deduped within this prompt).
     */
    public static function extractTerms(string $prompt): array
    {
        $normalized = self::normalizeSeparators($prompt);
        $parts      = preg_split('/\s*,\s*/u', $normalized) ?: [];

        $ordered = [];
        $seen    = [];

        foreach ($parts as $part) {
            $term = self::cleanToken((string) $part);
            if ($term === '') {
                continue;
            }

            $key = self::dedupeKey($term);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ordered[]  = $term;
        }

        return $ordered;
    }

    private static function normalizeSeparators(string $prompt): string
    {
        // Treat BREAK as a hard separator (same as comma), not as a tag.
        $s = preg_replace('/\bBREAK\b/iu', ',', $prompt) ?? $prompt;
        // Newlines / pipes often separate chunks in SD prompts.
        $s = str_replace(["\r\n", "\r", "\n", '|'], ',', $s);

        return $s;
    }

    private static function cleanToken(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }

        // Drop embedding / LoRA / hypernetwork angle-bracket controls entirely.
        $s = preg_replace('/<[^>]*>/u', '', $s) ?? $s;
        $s = trim($s);
        if ($s === '' || self::isControlOnly($s)) {
            return '';
        }

        // Unwrap nested () / [] and strip :weight suffixes (e.g. (pink lips:1.1)).
        $guard = 0;
        while ($guard++ < 16) {
            $prev = $s;

            if (preg_match('/^\(+(.+)\)+$/u', $s, $m) === 1) {
                $s = trim((string) $m[1]);
                $s = self::stripTrailingWeight($s);
                continue;
            }

            if (preg_match('/^\[+(.+)\]+$/u', $s, $m) === 1) {
                $s = trim((string) $m[1]);
                $s = self::stripTrailingWeight($s);
                continue;
            }

            // Partial wrappers left after mixed junk: strip remaining control punctuation.
            $s = preg_replace('/[\[\]\(\)<>{}]/u', '', $s) ?? $s;
            $s = self::stripTrailingWeight(trim($s));

            if ($s === $prev) {
                break;
            }
        }

        $s = trim($s);
        if ($s === '' || self::isControlOnly($s)) {
            return '';
        }

        // Prefer spaced form over underscores for storage/display.
        $s = str_replace('_', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);

        if ($s === '' || self::isControlOnly($s)) {
            return '';
        }

        // Ignore pure numbers / weights leftover as tokens.
        if (preg_match('/^[\d.:]+$/u', $s) === 1) {
            return '';
        }

        return $s;
    }

    private static function stripTrailingWeight(string $s): string
    {
        // "pink lips:1.1", "foo:0.5", "bar:.8"
        $out = preg_replace('/\s*:\s*-?[\d.]+$/u', '', $s);

        return trim((string) ($out ?? $s));
    }

    private static function isControlOnly(string $s): bool
    {
        $u = mb_strtoupper(trim($s));

        return $u === ''
            || $u === 'BREAK'
            || $u === 'AND'
            || $u === 'OR'
            || $u === 'NOT';
    }

    private static function dedupeKey(string $term): string
    {
        $key = mb_strtolower(str_replace('_', ' ', $term));
        $key = preg_replace('/\s+/u', ' ', $key) ?? $key;

        return trim($key);
    }
}
