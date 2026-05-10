<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Enriquece entradas de LoRA com dados públicos da Civitai (por hash do arquivo).
 */
class LoraMetadataEnricher
{
    private Client $http;

    /** @var array<string, array<string, mixed>> */
    private array $hashCache = [];

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout'         => 8.0,
            'connect_timeout' => 3.0,
            'headers'         => [
                'Accept'     => 'application/json',
                'User-Agent' => 'mydraw-api/lora-metadata',
            ],
        ]);
    }

    /**
     * @param  list<array{name: string, weight: float|null, hash: string|null}>  $loras
     * @return list<array<string, mixed>>
     */
    public function enrich(array $loras): array
    {
        $out = [];
        foreach ($loras as $lora) {
            $hash = $lora['hash'] ?? null;
            if ($hash === null || $hash === '') {
                $out[] = array_merge($lora, [
                    'creator'     => null,
                    'creator_url' => null,
                    'model_url'   => null,
                    'source'      => null,
                ]);

                continue;
            }

            $info = $this->lookupByHash((string) $hash);
            $out[] = array_merge($lora, $info);
        }

        return $out;
    }

    /**
     * @return array{creator: ?string, creator_url: ?string, model_url: ?string, source: ?string}
     */
    private function lookupByHash(string $hash): array
    {
        $hash = strtolower(trim($hash));
        if ($hash === '') {
            return [
                'creator'     => null,
                'creator_url' => null,
                'model_url'   => null,
                'source'      => null,
            ];
        }

        if (isset($this->hashCache[$hash])) {
            return $this->hashCache[$hash];
        }

        $token = env('CIVITAI_API_TOKEN');
        $url   = 'https://civitai.com/api/v1/model-versions/by-hash/'.$hash;

        $options = [];
        if (is_string($token) && $token !== '') {
            $options['headers']['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = $this->http->get($url, $options);
            if ($response->getStatusCode() !== 200) {
                $empty = $this->emptyInfo();
                $this->hashCache[$hash] = $empty;

                return $empty;
            }

            $body = (string) $response->getBody();
            $json = json_decode($body, true);
            if (!is_array($json)) {
                $empty = $this->emptyInfo();
                $this->hashCache[$hash] = $empty;

                return $empty;
            }

            $mapped = $this->mapCivitaiResponse($json);
            $this->hashCache[$hash] = $mapped;

            return $mapped;
        } catch (GuzzleException $e) {
            Log::debug('civitai lora lookup failed', [
                'hash'      => $hash,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            $empty = $this->emptyInfo();
            $this->hashCache[$hash] = $empty;

            return $empty;
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{creator: ?string, creator_url: ?string, model_url: ?string, source: ?string}
     */
    private function mapCivitaiResponse(array $json): array
    {
        $versionId = isset($json['id']) ? (int) $json['id'] : null;
        $modelId   = null;
        if (isset($json['modelId'])) {
            $modelId = (int) $json['modelId'];
        } elseif (isset($json['model']['id'])) {
            $modelId = (int) $json['model']['id'];
        }

        $username = null;
        if (isset($json['model']['creator']['username'])) {
            $username = (string) $json['model']['creator']['username'];
        }

        $creatorUrl = null;
        if (is_string($username) && $username !== '') {
            $creatorUrl = 'https://civitai.com/user/'.$username;
        }

        $modelUrl = null;
        if ($modelId !== null && $modelId > 0) {
            $modelUrl = 'https://civitai.com/models/'.$modelId;
            if ($versionId !== null && $versionId > 0) {
                $modelUrl .= '?modelVersionId='.$versionId;
            }
        }

        return [
            'creator'     => $username,
            'creator_url' => $creatorUrl,
            'model_url'   => $modelUrl,
            'source'      => 'civitai',
        ];
    }

    /**
     * @return array{creator: null, creator_url: null, model_url: null, source: null}
     */
    private function emptyInfo(): array
    {
        return [
            'creator'     => null,
            'creator_url' => null,
            'model_url'   => null,
            'source'      => null,
        ];
    }
}
