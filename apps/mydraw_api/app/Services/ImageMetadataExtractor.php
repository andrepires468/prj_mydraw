<?php

namespace App\Services;

use App\Models\Gallery;

/**
 * Extrai metadados embutidos de imagens geradas (Stable Diffusion / WebUI).
 *
 * Foca em PNG com chunk textual `parameters` (AUTOMATIC1111 / Forge).
 */
class ImageMetadataExtractor
{
    /**
     * @return array<string, mixed>|null estrutura compatível com persistência em {@see Gallery}
     */
    public function extractStableDiffusionFromBinary(string $binary): ?array
    {
        $parameters = $this->readEmbeddingParametersString($binary);
        if ($parameters === null || $parameters === '') {
            return null;
        }

        return $this->parseA1111Parameters($parameters);
    }

    /**
     * Lê o texto embutido contendo parâmetros (PNG tEXt/iTXt/zTXt "parameters").
     */
    public function readEmbeddingParametersString(string $binary): ?string
    {
        if ($this->isPng($binary)) {
            $chunks = $this->readPngTextualChunks($binary);

            return $chunks['parameters'] ?? null;
        }

        return null;
    }

    private function isPng(string $binary): bool
    {
        return strlen($binary) >= 8 && substr($binary, 0, 8) === "\x89PNG\r\n\x1a\n";
    }

    /**
     * @return array<string, string> keyword => texto UTF-8
     */
    private function readPngTextualChunks(string $binary): array
    {
        $out = [];
        $len = strlen($binary);
        $i   = 8;

        while ($i + 8 <= $len) {
            $length = unpack('N', substr($binary, $i, 4))[1];
            $type   = substr($binary, $i + 4, 4);
            $i += 8;
            if ($i + $length + 4 > $len) {
                break;
            }
            $data = substr($binary, $i, $length);
            $i += $length + 4;

            if ($type === 'tEXt') {
                $null = strpos($data, "\0");
                if ($null !== false) {
                    $keyword = substr($data, 0, $null);
                    $text    = substr($data, $null + 1);
                    $out[$keyword] = $text;
                }
            } elseif ($type === 'zTXt') {
                $parsed = $this->parseZtxtChunk($data);
                if ($parsed !== null) {
                    [$keyword, $text] = $parsed;
                    $out[$keyword] = $text;
                }
            } elseif ($type === 'iTXt') {
                $parsed = $this->parseItxtChunk($data);
                if ($parsed !== null) {
                    [$keyword, $text] = $parsed;
                    $out[$keyword] = $text;
                }
            }

            if ($type === 'IEND') {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string}|null [keyword, utf8 text]
     */
    private function parseZtxtChunk(string $data): ?array
    {
        $null = strpos($data, "\0");
        if ($null === false) {
            return null;
        }
        $keyword = substr($data, 0, $null);
        $method  = ord($data[$null + 1] ?? "\xff");
        if ($method !== 0) {
            return null;
        }
        $compressed = substr($data, $null + 2);
        if ($compressed === '' || $compressed === false) {
            return null;
        }
        $text = @zlib_decode($compressed);
        if ($text === false || $text === '') {
            return null;
        }

        return [$keyword, $text];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseItxtChunk(string $data): ?array
    {
        $null = strpos($data, "\0");
        if ($null === false) {
            return null;
        }
        $keyword = substr($data, 0, $null);
        $rest    = substr($data, $null + 1);
        if ($rest === '' || strlen($rest) < 2) {
            return null;
        }
        $compressedFlag = ord($rest[0]);
        $compMethod       = ord($rest[1]);
        $p                = 2;
        $langEnd          = strpos($rest, "\0", $p);
        if ($langEnd === false) {
            return null;
        }
        $p = $langEnd + 1;
        $transEnd = strpos($rest, "\0", $p);
        if ($transEnd === false) {
            return null;
        }
        $p          = $transEnd + 1;
        $textPayload = substr($rest, $p);
        if ($textPayload === '' || $textPayload === false) {
            return null;
        }

        if ($compressedFlag === 0) {
            return [$keyword, $textPayload];
        }

        if ($compressedFlag === 1 && $compMethod === 0) {
            $decoded = @zlib_decode($textPayload);
            if ($decoded === false || $decoded === '') {
                return null;
            }

            return [$keyword, $decoded];
        }

        return null;
    }

    /**
     * Parseia string completa estilo AUTOMATIC1111.
     *
     * @return array<string, mixed>|null
     */
    public function parseA1111Parameters(string $full): ?array
    {
        $full = str_replace(["\r\n", "\r"], "\n", $full);
        $full = trim($full);

        $positive      = '';
        $negative      = '';
        $paramsLine    = '';

        if (preg_match('/\bNegative prompt:\s*/i', $full, $m, PREG_OFFSET_CAPTURE)) {
            $positive = trim(substr($full, 0, $m[0][1]));
            $rest     = trim(substr($full, $m[0][1] + strlen($m[0][0])));
            if (preg_match('/\bSteps:\s*/i', $rest, $m2, PREG_OFFSET_CAPTURE)) {
                $negative   = trim(substr($rest, 0, $m2[0][1]));
                $paramsLine = trim(substr($rest, $m2[0][1]));
            } else {
                $negative = $rest;
            }
        } elseif (preg_match('/\bSteps:\s*/i', $full, $m2, PREG_OFFSET_CAPTURE)) {
            $positive   = trim(substr($full, 0, $m2[0][1]));
            $paramsLine = trim(substr($full, $m2[0][1]));
        } else {
            return null;
        }

        $kv = $this->parseParameterKeyValues($paramsLine);

        $lorasFromPrompt = $this->extractLoraTags($positive."\n".$negative);
        $hashPairs       = $this->parseLoraHashesField($paramsLine);
        $loras           = $this->mergeLorasFromPromptAndHashes($lorasFromPrompt, $hashPairs);

        return [
            'metadata_type'             => Gallery::METADATA_TYPE_STABLE_DIFFUSION,
            'metadata_prompt'           => $positive !== '' ? $positive : null,
            'metadata_negative_prompt'  => $negative !== '' ? $negative : null,
            'metadata_steps'            => $kv['steps'] ?? null,
            'metadata_sampler'          => $kv['sampler'] ?? null,
            'metadata_schedule_type'    => $kv['schedule_type'] ?? null,
            'metadata_cfg_scale'        => $kv['cfg_scale'] ?? null,
            'metadata_seed'             => $kv['seed'] ?? null,
            'metadata_width'            => $kv['width'] ?? null,
            'metadata_height'           => $kv['height'] ?? null,
            'metadata_model'            => $kv['model'] ?? null,
            'metadata_model_hash'       => $kv['model_hash'] ?? null,
            'metadata_clip_skip'        => $kv['clip_skip'] ?? null,
            'metadata_version'          => $kv['version'] ?? null,
            'metadata_loras'            => $loras,
            'metadata_raw'              => [
                'parameters_full' => $full,
                'params_line'     => $paramsLine,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseParameterKeyValues(string $paramsLine): array
    {
        $out = [
            'steps'          => null,
            'sampler'        => null,
            'schedule_type'  => null,
            'cfg_scale'      => null,
            'seed'           => null,
            'width'          => null,
            'height'         => null,
            'model_hash'     => null,
            'model'          => null,
            'clip_skip'      => null,
            'version'        => null,
        ];

        if ($paramsLine === '') {
            return $out;
        }

        if (preg_match('/\bSteps:\s*(\d+)/i', $paramsLine, $m)) {
            $out['steps'] = (int) $m[1];
        }
        if (preg_match('/\bSampler:\s*([^,]+)/i', $paramsLine, $m)) {
            $out['sampler'] = trim($m[1]);
        }
        if (preg_match('/\bSchedule type:\s*([^,]+)/i', $paramsLine, $m)) {
            $out['schedule_type'] = trim($m[1]);
        }
        if (preg_match('/\bCFG scale:\s*([0-9.]+)/i', $paramsLine, $m)) {
            $out['cfg_scale'] = (float) $m[1];
        }
        if (preg_match('/\bSeed:\s*(\d+)/i', $paramsLine, $m)) {
            $out['seed'] = (int) $m[1];
        }
        if (preg_match('/\bSize:\s*(\d+)\s*x\s*(\d+)/i', $paramsLine, $m)) {
            $out['width']  = (int) $m[1];
            $out['height'] = (int) $m[2];
        }
        if (preg_match('/\bModel hash:\s*([a-f0-9]+)/i', $paramsLine, $m)) {
            $out['model_hash'] = strtolower($m[1]);
        }
        if (preg_match('/\bModel:\s*(.+?)(?=,\s*(?:Clip skip|Lora hashes|Version|Hashes|VAE|Template)\s*:|$)/is', $paramsLine, $m)) {
            $out['model'] = trim($m[1]);
        }
        if (preg_match('/\bClip skip:\s*(\d+)/i', $paramsLine, $m)) {
            $out['clip_skip'] = (int) $m[1];
        }
        if (preg_match('/\bVersion:\s*(.+)$/is', $paramsLine, $m)) {
            $out['version'] = trim($m[1]);
        }

        return $out;
    }

    /**
     * @return list<array{name: string, weight: float|null}>
     */
    private function extractLoraTags(string $text): array
    {
        $list = [];
        if (!preg_match_all('/<lora:([^:>]+):([0-9.]+)>/i', $text, $matches, PREG_SET_ORDER)) {
            return $list;
        }
        foreach ($matches as $m) {
            $list[] = [
                'name'   => trim($m[1]),
                'weight' => (float) $m[2],
            ];
        }

        return $list;
    }

    /**
     * @return list<array{name: string, hash: string}>
     */
    private function parseLoraHashesField(string $paramsLine): array
    {
        $pairs = [];
        if (!preg_match('/\bLora hashes:\s*"((?:\\\\.|[^"\\\\])*)"/is', $paramsLine, $m)) {
            return $pairs;
        }
        $inner = stripcslashes($m[1]);
        $parts = preg_split('/\s*,\s*/', $inner);
        if ($parts === false) {
            return $pairs;
        }
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^([^:]+):\s*([a-f0-9]{8,64})\s*$/i', $part, $pm)) {
                $pairs[] = [
                    'name' => trim($pm[1]),
                    'hash' => strtolower($pm[2]),
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param  list<array{name: string, weight: float|null}>  $tags
     * @param  list<array{name: string, hash: string}>        $hashPairs
     * @return list<array{name: string, weight: float|null, hash: string|null}>
     */
    private function mergeLorasFromPromptAndHashes(array $tags, array $hashPairs): array
    {
        $result          = [];
        $usedHashIndexes = [];

        foreach ($tags as $tag) {
            $key  = strtolower($tag['name']);
            $hash = null;
            foreach ($hashPairs as $hi => $pair) {
                if (strtolower($pair['name']) === $key) {
                    $hash              = $pair['hash'];
                    $usedHashIndexes[$hi] = true;
                    break;
                }
            }
            $result[] = [
                'name'   => $tag['name'],
                'weight' => $tag['weight'],
                'hash'   => $hash,
            ];
        }

        foreach ($hashPairs as $hi => $pair) {
            if (!empty($usedHashIndexes[$hi])) {
                continue;
            }
            $result[] = [
                'name'   => $pair['name'],
                'weight' => null,
                'hash'   => $pair['hash'],
            ];
        }

        return $result;
    }
}
