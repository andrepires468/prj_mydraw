<?php

namespace App\Services;

use App\Exceptions\GeminiApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * Minimal Gemini REST client (JSON mode).
 *
 * @see https://ai.google.dev/api/rest/v1beta/models.generateContent
 */
final class GeminiGenerateContentClient
{
    /** Tempo máximo de espera por resposta do Gemini (segundos). Padrão: 4 minutos. */
    private const DEFAULT_REQUEST_TIMEOUT_SECONDS = 240;

    private Client $http;

    private int $requestTimeoutSeconds;

    public function __construct(?Client $http = null)
    {
        $this->requestTimeoutSeconds = max(
            30,
            (int) env('GEMINI_REQUEST_TIMEOUT_SECONDS', self::DEFAULT_REQUEST_TIMEOUT_SECONDS)
        );

        $this->http = $http ?? new Client([
            'timeout'         => $this->requestTimeoutSeconds,
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

        $max429Attempts = max(1, min(10, (int) env('GEMINI_429_MAX_ATTEMPTS', 5)));
        $response         = null;
        $attempt          = 0;

        while (true) {
            $attempt++;
            try {
                $response = $this->http->post($url, [
                    'query' => ['key' => $apiKey],
                    'json'  => $payload,
                ]);
                break;
            } catch (RequestException $e) {
                if ($this->isTimeoutException($e)) {
                    throw $this->timeoutGeminiException($e);
                }

                $wrapped = $this->wrapRequestException($e);
                if ($wrapped->getHttpStatus() === 429 && $attempt < $max429Attempts) {
                    $delay = $this->retryDelaySecondsFor429($e, $wrapped->getMessage());
                    sleep($delay);

                    continue;
                }
                throw $wrapped;
            } catch (GuzzleException $e) {
                if ($this->isTimeoutException($e)) {
                    throw $this->timeoutGeminiException($e);
                }

                throw new GeminiApiException(
                    'Gemini API request failed.',
                    0,
                    $e
                );
            }
        }

        $body = (string) $response->getBody();
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new \RuntimeException('Invalid Gemini response envelope.', 0, $e);
        }

        $text = $this->extractText($decoded);
        if ($text === null || $text === '') {
            $hint = $this->describeWhyNoText($decoded);

            throw new \RuntimeException($hint);
        }

        $text = $this->stripMarkdownFence($text);

        /** @var array<string, mixed> */
        return $this->parseModelJsonFromText($text);
    }

    /**
     * Aceita JSON puro, blocos ```json … ``` ou texto com prólogo/epílogo ao redor do objeto.
     *
     * @return array<string, mixed>
     */
    private function parseModelJsonFromText(string $rawText): array
    {
        $text = trim($rawText);
        if ($text !== '' && strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }

        $parsed = $this->tryJsonDecodeArray($text);
        if ($parsed !== null) {
            return $parsed;
        }

        if (preg_match_all('/```(?:json)?\s*([\s\S]*?)```/i', $text, $blocks)) {
            foreach ($blocks[1] as $block) {
                $block = trim($block);
                $parsed = $this->tryJsonDecodeArray($block);
                if ($parsed !== null) {
                    return $parsed;
                }
                $slice = $this->extractFirstBalancedJsonObject($block);
                if ($slice !== null) {
                    $parsed = $this->tryJsonDecodeArray($slice);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                }
            }
        }

        $slice = $this->extractFirstBalancedJsonObject($text);
        if ($slice !== null) {
            $parsed = $this->tryJsonDecodeArray($slice);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new \RuntimeException(
            'Gemini returned non-JSON content (no parseable JSON object found in model output).'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryJsonDecodeArray(string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extrai o primeiro objeto JSON `{ ... }` com chaves balanceadas (ignora strings).
     */
    private function extractFirstBalancedJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $len      = strlen($text);
        $depth    = 0;
        $inString = false;
        $escape   = false;

        for ($i = $start; $i < $len; $i++) {
            $c = $text[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($c === '"') {
                $inString = true;

                continue;
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
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

    /**
     * Explica resposta sem texto útil (bloqueio, finishReason, etc.).
     *
     * @param  array<string, mixed>  $decoded
     */
    private function describeWhyNoText(array $decoded): string
    {
        $pf = $decoded['promptFeedback'] ?? null;
        if (is_array($pf)) {
            $br = $pf['blockReason'] ?? null;
            if (
                is_string($br)
                && $br !== ''
                && $br !== 'BLOCK_REASON_UNSPECIFIED'
            ) {
                return 'Gemini blocked the request (promptFeedback.blockReason: '.$br.').';
            }
        }

        $cands = $decoded['candidates'] ?? null;
        if (!is_array($cands) || $cands === []) {
            return 'Gemini returned no candidates — often blocked or filtered; check promptFeedback in the API response.';
        }

        $first = $cands[0] ?? null;
        if (!is_array($first)) {
            return 'Gemini returned an invalid candidate shape.';
        }

        $fr = $first['finishReason'] ?? null;
        if (
            is_string($fr)
            && $fr !== ''
            && $fr !== 'STOP'
            && $fr !== 'MAX_TOKENS'
            && $fr !== 'FINISH_REASON_UNSPECIFIED'
        ) {
            return 'Gemini stopped without normal text (finishReason: '.$fr.').';
        }

        return 'Gemini returned no text in candidate parts (finishReason: '
            .(is_string($fr) ? $fr : 'unknown').').';
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

    /**
     * Espera antes de repetir após HTTP 429 (cabeçalho Retry-After ou texto "retry in Xs" da Google).
     */
    private function retryDelaySecondsFor429(RequestException $e, string $errorMessage): int
    {
        $response = $e->getResponse();
        if ($response !== null && $response->hasHeader('Retry-After')) {
            $raw = trim($response->getHeaderLine('Retry-After'));
            if ($raw !== '' && ctype_digit($raw)) {
                $v = (int) $raw;
                if ($v > 0) {
                    return min(120, max(1, $v));
                }
            }
        }

        if (preg_match('/retry in\s*([\d.]+)\s*s/i', $errorMessage, $m)) {
            return (int) min(120, max(1, (int) ceil((float) $m[1])));
        }

        return 10;
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

    private function timeoutGeminiException(Throwable $e): GeminiApiException
    {
        $minutes = (int) ceil($this->requestTimeoutSeconds / 60);

        return new GeminiApiException(
            sprintf(
                'Gemini API did not respond within %d minute(s) (request timed out). Try again or reduce posts per run.',
                max(1, $minutes)
            ),
            504,
            $e
        );
    }

    private function isTimeoutException(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return true;
        }

        if ($e instanceof RequestException) {
            $errno = $e->getHandlerContext()['errno'] ?? null;
            if ($errno === 28) {
                return true;
            }
        }

        return false;
    }
}
