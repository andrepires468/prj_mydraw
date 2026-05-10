<?php

namespace App\Support;

/**
 * Normalizes AI-derived tags for storage and search (English, lowercase kebab-case).
 */
final class AiTagNormalizer
{
    /** @var array<string, true> */
    private const VAGUE_DENYLIST = [
        'image'        => true,
        'picture'      => true,
        'photo'        => true,
        'art'          => true,
        'artwork'      => true,
        'drawing'      => true,
        'quality'      => true,
        'high-quality' => true,
        'hq'           => true,
        'detailed'     => true,
        'beautiful'    => true,
        'best'         => true,
        'masterpiece'  => true,
        'score_9'      => true,
        'score_8_up'   => true,
        'tag'          => true,
        'tags'         => true,
    ];

    private const MAX_TAG_LEN = 48;

    private const MAX_TAGS_PER_IMAGE = 24;

    /**
     * @param  string[]  $tags
     * @return string[]
     */
    public static function normalizeList(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $n = self::normalizeOne((string) $tag);
            if ($n === null) {
                continue;
            }
            $out[$n] = true;
            if (count($out) >= self::MAX_TAGS_PER_IMAGE) {
                break;
            }
        }

        return array_keys($out);
    }

    public static function normalizeOne(string $raw): ?string
    {
        $t = strtolower(trim($raw));
        $t = preg_replace('/\s+/u', '-', $t) ?? '';
        $t = preg_replace('/[^a-z0-9\-]/u', '', $t) ?? '';
        $t = trim($t, '-');
        if ($t === '' || strlen($t) > self::MAX_TAG_LEN) {
            return null;
        }
        if (isset(self::VAGUE_DENYLIST[$t])) {
            return null;
        }
        // Drop rating-like tokens mistaken as tags
        foreach (ContentRating::ALLOWED as $r) {
            if ($t === strtolower(str_replace('-', '', $r)) || $t === strtolower($r)) {
                return null;
            }
        }

        return $t;
    }

    /**
     * @param  array<int, string[]>  $perImageTags
     * @return string[]
     */
    public static function unionSortedUnique(array $perImageTags, int $maxTotal = 300): array
    {
        $set = [];
        foreach ($perImageTags as $tags) {
            foreach ($tags as $tag) {
                $n = self::normalizeOne((string) $tag);
                if ($n !== null) {
                    $set[$n] = true;
                }
                if (count($set) >= $maxTotal) {
                    break 2;
                }
            }
        }
        $keys = array_keys($set);
        sort($keys);

        return $keys;
    }
}
