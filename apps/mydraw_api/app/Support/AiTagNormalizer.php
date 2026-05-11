<?php

namespace App\Support;

/**
 * Prepares AI-derived tag lists for storage: trims whitespace, dedupes, caps count/length.
 * Tag strings are kept exactly as returned (no case folding, no character stripping).
 */
final class AiTagNormalizer
{
    private const MAX_TAGS_PER_IMAGE = 24;

    private const MAX_TAG_CHARS = 2048;

    private const MAX_UNION_TAGS = 300;

    /**
     * @param  string[]  $tags
     * @return string[]
     */
    public static function normalizeList(array $tags): array
    {
        $out   = [];
        $seen  = [];

        foreach ($tags as $tag) {
            $s = self::trimOnly((string) $tag);
            if ($s === '') {
                continue;
            }
            if (mb_strlen($s) > self::MAX_TAG_CHARS) {
                $s = mb_substr($s, 0, self::MAX_TAG_CHARS);
            }
            if (isset($seen[$s])) {
                continue;
            }
            $seen[$s] = true;
            $out[] = $s;
            if (count($out) >= self::MAX_TAGS_PER_IMAGE) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string[]>  $perImageTags
     * @return string[]
     */
    public static function unionSortedUnique(array $perImageTags, int $maxTotal = self::MAX_UNION_TAGS): array
    {
        $seen = [];
        $out  = [];

        foreach ($perImageTags as $tags) {
            foreach ($tags as $tag) {
                $s = self::trimOnly((string) $tag);
                if ($s === '') {
                    continue;
                }
                if (mb_strlen($s) > self::MAX_TAG_CHARS) {
                    $s = mb_substr($s, 0, self::MAX_TAG_CHARS);
                }
                if (isset($seen[$s])) {
                    continue;
                }
                $seen[$s] = true;
                $out[] = $s;
                if (count($out) >= $maxTotal) {
                    break 2;
                }
            }
        }

        return $out;
    }

    private static function trimOnly(string $raw): string
    {
        return trim($raw);
    }
}
