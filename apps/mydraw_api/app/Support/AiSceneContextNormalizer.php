<?php

namespace App\Support;

/**
 * Flattens AI "work / characters / relationship / scene" JSON into search tags (4–12 target).
 */
final class AiSceneContextNormalizer
{
    public const MIN_TAGS = 4;

    public const MAX_TAGS = 12;

    /**
     * @param  array<string, mixed>  $decoded
     * @return string[]
     */
    public static function flattenToTags(array $decoded, int $maxTags = self::MAX_TAGS): array
    {
        $maxTags = max(1, min(self::MAX_TAGS, $maxTags));
        $candidates = [];

        // Prefer structured fields; fall back to a flat tags array if present.
        if (isset($decoded['tags']) && is_array($decoded['tags']) && !self::hasStructuredFields($decoded)) {
            foreach ($decoded['tags'] as $tag) {
                self::pushCandidate($candidates, (string) $tag);
            }

            return AiTagNormalizer::normalizeList(array_slice($candidates, 0, $maxTags));
        }

        self::pushCandidate($candidates, self::stringField($decoded, 'work'));
        self::pushCandidate($candidates, self::stringField($decoded, 'obra'));

        foreach (self::stringListField($decoded, 'characters') as $name) {
            self::pushCandidate($candidates, $name);
        }
        foreach (self::stringListField($decoded, 'personagens') as $name) {
            self::pushCandidate($candidates, $name);
        }

        self::pushCandidate($candidates, self::stringField($decoded, 'relationship'));
        self::pushCandidate($candidates, self::stringField($decoded, 'relation'));
        self::pushCandidate($candidates, self::stringField($decoded, 'relacao'));
        self::pushCandidate($candidates, self::stringField($decoded, 'relação'));

        foreach (self::stringListField($decoded, 'scene') as $term) {
            self::pushCandidate($candidates, $term);
        }
        foreach (self::stringListField($decoded, 'cena') as $term) {
            self::pushCandidate($candidates, $term);
        }

        // Cap before normalize so MAX_TAGS_PER_IMAGE does not swallow context tags.
        $capped = array_slice($candidates, 0, $maxTags);

        return AiTagNormalizer::normalizeList($capped);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private static function hasStructuredFields(array $decoded): bool
    {
        foreach (['work', 'obra', 'characters', 'personagens', 'relationship', 'relation', 'relacao', 'relação', 'scene', 'cena'] as $key) {
            if (array_key_exists($key, $decoded) && $decoded[$key] !== null && $decoded[$key] !== '' && $decoded[$key] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private static function stringField(array $decoded, string $key): string
    {
        if (!isset($decoded[$key]) || is_array($decoded[$key])) {
            return '';
        }

        return trim((string) $decoded[$key]);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return string[]
     */
    private static function stringListField(array $decoded, string $key): array
    {
        if (!isset($decoded[$key])) {
            return [];
        }

        $value = $decoded[$key];
        if (is_string($value)) {
            $parts = preg_split('/\s*,\s*/u', $value) ?: [];

            return array_values(array_filter(array_map('trim', $parts), static fn (string $s) => $s !== ''));
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) || is_numeric($item)) {
                $s = trim((string) $item);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }

        return $out;
    }

    /**
     * @param  string[]  $candidates
     */
    private static function pushCandidate(array &$candidates, string $raw): void
    {
        $s = trim($raw);
        if ($s === '') {
            return;
        }

        // Ignore obvious placeholders.
        $lower = mb_strtolower($s);
        if (in_array($lower, ['unknown', 'n/a', 'none', 'null', 'undefined', 'original', 'oc'], true)) {
            return;
        }

        $candidates[] = $s;
    }
}
