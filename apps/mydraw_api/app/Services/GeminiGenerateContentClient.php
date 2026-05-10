<?php

namespace App\Services;

use App\Exceptions\GeminiApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Minimal Gemini REST client (JSON mode).
 *
 * @see https://ai.google.dev/api/rest/v1beta/models.generateContent
 */
final class GeminiGenerateContentClient
{
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout'         => 120,
            'connect_timeout' => 15,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function generateJson(string $model, string $apiKey, string $userText): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('GEMINI_API_KEY is empty.');
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model)
        );

        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $userText]],
                ],
            ],
            'generationConfig' => [
                'temperature'        => 0.15,
                'responseMimeType'   => 'application/json',
                'maxOutputTokens'    => 8192,
            ],
        ];

        try {
            $response = $this->http->post($url, [
                'query' => ['key' => $apiKey],
                'json'  => $payload,
            ]);
        } catch (RequestException $e) {
            throw $this->wrapRequestException($e);
        } catch (GuzzleException $e) {
            Log::warning('gemini: request failed', [
                'message' => $this->sanitizeLogMessage($e->getMessage()),
            ]);

            throw new GeminiApiException(
                'Gemini API request failed.',
                0,
                $e
            );
        }

        $body = (string) $response->getBody();
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('gemini: invalid json envelope', ['body' => $body]);

            throw new \RuntimeException('Invalid Gemini response envelope.', 0, $e);
        }

        $text = $this->extractText($decoded);
        if ($text === null || $text === '') {
            Log::warning('gemini: empty text', ['body' => $body]);

            throw new \RuntimeException('Gemini returned no text.');
        }

        $text = $this->stripMarkdownFence($text);

        try {
            /** @var array<string, mixed> $json */
            $json = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('gemini: model json parse failed', ['text' => $text]);

            throw new \RuntimeException('Gemini returned non-JSON content.', 0, $e);
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function extractText(array $decoded): ?string
    {
        $candidates = $decoded['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            return null;
        }

        $first = $candidates[0] ?? null;
        if (!is_array($first)) {
            return null;
        }

        $content = $first['content'] ?? null;
        if (!is_array($content)) {
            return null;
        }

        $parts = $content['parts'] ?? null;
        if (!is_array($parts) || $parts === []) {
            return null;
        }

        $texts = [];
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text'])) {
                $texts[] = (string) $part['text'];
            }
        }

        return implode('', $texts);
    }

    private function stripMarkdownFence(string $text): string
    {
        $t = trim($text);
        if ($t === '' || $t[0] !== '`') {
            return $t;
        }

        $t = preg_replace('/^```(?:json)?\s*/i', '', $t) ?? $t;
        $t = preg_replace('/\s*```$/', '', $t) ?? $t;

        return trim($t);
    }

    private function wrapRequestException(RequestException $e): GeminiApiException
    {
        $response = $e->getResponse();
        $status   = $response !== null ? $response->getStatusCode() : 0;
        $body     = $response !== null ? (string) $response->getBody() : '';

        $googleMsg = null;
        if ($body !== '') {
            try {
                /** @var array<string, mixed> $j */
                $j = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $err = $j['error'] ?? null;
                if (is_array($err) && isset($err['message'])) {
                    $googleMsg = $this->truncateMessage((string) $err['message'], 500);
                }
            } catch (Throwable) {
                // ignore parse errors
            }
        }

        $safe = $this->buildSafeErrorMessage($status, $googleMsg);

        Log::warning('gemini: request failed', [
            'http_status'   => $status,
            'google_error'  => $googleMsg,
            'log_hint'      => $this->sanitizeLogMessage($e->getMessage()),
        ]);

        return new GeminiApiException($safe, $status, $e);
    }

    private function buildSafeErrorMessage(int $status, ?string $googleMsg): string
    {
        if ($status === 429) {
            return 'Gemini API rate limit or quota exceeded (429). Check usage limits and billing in Google AI Studio, or try again later.'
                .($googleMsg ? ' '.$googleMsg : '');
        }

        if ($status === 401 || $status === 403) {
            return 'Gemini API rejected the API key or permissions ('.$status.'). Verify GEMINI_API_KEY.'
                .($googleMsg ? ' '.$googleMsg : '');
        }

        if ($status === 404) {
            return 'Gemini model not found (404). Check GEMINI_MODEL matches an available model id.'
                .($googleMsg ? ' '.$googleMsg : '');
        }

        if ($status >= 400 && $googleMsg) {
            return 'Gemini API error ('.$status.'): '.$googleMsg;
        }

        if ($status >= 400) {
            return 'Gemini API error ('.$status.').';
        }

        return 'Gemini API request failed.';
    }

    private function truncateMessage(string $msg, int $max): string
    {
        if (mb_strlen($msg) <= $max) {
            return $msg;
        }

        return mb_substr($msg, 0, $max).'…';
    }

    /**
     * Remove API key query params from Guzzle error strings before logging.
     */
    private function sanitizeLogMessage(string $message): string
    {
        $out = preg_replace('/([?&])key=[^&\s]+/i', '$1key=[REDACTED]', $message) ?? $message;

        return preg_replace('/\bAIza[0-9A-Za-z_-]{20,}\b/', '[REDACTED]', $out) ?? $out;
    }
}
