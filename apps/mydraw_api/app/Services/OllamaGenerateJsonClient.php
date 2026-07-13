<?php

namespace App\Services;

use App\Exceptions\OllamaApiException;
use App\Support\OllamaConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * Ollama local client (Windows host via host.docker.internal).
 *
 * @see https://github.com/ollama/ollama/blob/main/docs/api.md
 */
final class OllamaGenerateJsonClient
{
    private Client $http;

    private int $requestTimeoutSeconds;

    public function __construct(?Client $http = null)
    {
        $this->requestTimeoutSeconds = OllamaConfig::requestTimeoutSeconds();

        $this->http = $http ?? new Client([
            'timeout'         => $this->requestTimeoutSeconds,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * @param  int|null  $requestTimeoutSeconds
     * @return array<string, mixed>
     */
    public function generateJson(
        string $model,
        string $userText,
        ?int $requestTimeoutSeconds = null
    ): array {
        $model = trim($model);
        if ($model === '') {
            throw new \InvalidArgumentException('OLLAMA_MODEL is empty.');
        }

        $baseUrl = OllamaConfig::baseUrl();
        $url     = $baseUrl.'/api/chat';

        $payload = [
            'model'    => $model,
            'stream'   => false,
            'format'   => 'json',
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => $userText,
                ],
            ],
            'options' => [
                'temperature' => 0.15,
            ],
        ];

        $timeout = $requestTimeoutSeconds !== null
            ? max(30, min(600, $requestTimeoutSeconds))
            : $this->requestTimeoutSeconds;

        try {
            $response = $this->http->post($url, [
                'json'    => $payload,
                'timeout' => $timeout,
            ]);
        } catch (RequestException $e) {
            if ($this->isTimeoutException($e)) {
                throw new OllamaApiException(
                    'Ollama request timed out. Is the model loaded and OLLAMA_BASE_URL reachable from Docker?',
                    504,
                    $e
                );
            }

            throw $this->wrapRequestException($e);
        } catch (GuzzleException $e) {
            if ($this->isTimeoutException($e)) {
                throw new OllamaApiException(
                    'Ollama request timed out. Is the model loaded and OLLAMA_BASE_URL reachable from Docker?',
                    504,
                    $e
                );
            }

            throw new OllamaApiException(
                'Ollama request failed: '.mb_substr($e->getMessage(), 0, 240),
                0,
                $e
            );
        }

        $body = (string) $response->getBody();
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new OllamaApiException('Invalid Ollama response envelope.', 502, $e);
        }

        $content = $decoded['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new OllamaApiException(
                'Ollama returned empty message content.',
                502
            );
        }

        return $this->parseModelJsonFromText($content);
    }

    /**
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
            }
        }

        $slice = $this->extractFirstBalancedJsonObject($text);
        if ($slice !== null) {
            $parsed = $this->tryJsonDecodeArray($slice);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new OllamaApiException(
            'Ollama returned non-JSON content (no parseable JSON object found).',
            502
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

    private function extractFirstBalancedJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth  = 0;
        $inStr  = false;
        $escape = false;
        $len    = strlen($text);

        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inStr) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inStr = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function wrapRequestException(RequestException $e): OllamaApiException
    {
        $response = $e->getResponse();
        $status   = $response !== null ? $response->getStatusCode() : 0;
        $body     = $response !== null ? (string) $response->getBody() : '';

        $detail = trim($e->getMessage());
        if ($body !== '') {
            try {
                /** @var array<string, mixed> $j */
                $j = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (isset($j['error']) && is_string($j['error']) && $j['error'] !== '') {
                    $detail = $j['error'];
                }
            } catch (Throwable) {
                if (mb_strlen($body) < 300) {
                    $detail = $body;
                }
            }
        }

        if ($status === 0 && str_contains(strtolower($detail), 'connection')) {
            $detail = 'Cannot reach Ollama at '.OllamaConfig::baseUrl()
                .'. Confirm Ollama is running on Windows and Docker can use host.docker.internal.';
            $status = 502;
        }

        if ($status === 404) {
            $detail = 'Ollama model not found. Pull it with: ollama pull '.OllamaConfig::model();
        }

        return new OllamaApiException(
            mb_substr($detail !== '' ? $detail : 'Ollama request failed.', 0, 480),
            $status > 0 ? $status : 502,
            $e
        );
    }

    private function isTimeoutException(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'curl error 28');
    }
}
