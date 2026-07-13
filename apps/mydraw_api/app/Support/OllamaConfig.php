<?php

namespace App\Support;

/**
 * Ollama (Windows host) settings for local AI categorization.
 */
final class OllamaConfig
{
    public static function prefer(): bool
    {
        return self::envBool('OLLAMA_PREFER', false);
    }

    public static function baseUrl(): string
    {
        $raw = trim((string) env('OLLAMA_BASE_URL', 'http://host.docker.internal:11434'));

        return rtrim($raw !== '' ? $raw : 'http://host.docker.internal:11434', '/');
    }

    public static function model(): string
    {
        $raw = trim((string) env('OLLAMA_MODEL', 'qwen2.5:7b'));

        return $raw !== '' ? $raw : 'qwen2.5:7b';
    }

    public static function requestTimeoutSeconds(): int
    {
        return max(
            30,
            min(600, (int) env('OLLAMA_REQUEST_TIMEOUT_SECONDS', 240))
        );
    }

    /**
     * Sleep between Ollama calls (local GPU; default 0 — no cloud rate limit).
     */
    public static function requestSleepSeconds(): int
    {
        return max(0, min(120, (int) env('OLLAMA_REQUEST_SLEEP_SECONDS', 0)));
    }

    private static function envBool(string $key, bool $default): bool
    {
        $raw = env($key, $default);

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            return (int) $raw !== 0;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }
}
