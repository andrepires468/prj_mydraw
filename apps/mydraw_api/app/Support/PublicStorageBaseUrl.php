<?php

namespace App\Support;

/**
 * Base pública do MinIO alcançável pelo navegador.
 *
 * Em `APP_ENV=local`, se `MINIO_PUBLIC_URL` apontar para localhost e a requisição
 * vier com `Origin` de outro host (ex.: IP da LAN), o hostname da base pública
 * passa a ser o do Origin — mantendo esquema e porta configurados no .env —
 * para que imagens funcionem ao acessar o Vite por http://192.168.x.x:5173.
 */
final class PublicStorageBaseUrl
{
    public static function base(): string
    {
        $configured = rtrim((string) env('MINIO_PUBLIC_URL', env('AWS_ENDPOINT', '')), '/');
        if ($configured === '') {
            return '';
        }

        if (!self::shouldRewriteForLan($configured)) {
            return $configured;
        }

        $origin = (string) (request()?->header('Origin') ?? '');
        if ($origin === '') {
            return $configured;
        }

        $originParts = parse_url($origin);
        $configParts = parse_url($configured);
        if (!is_array($originParts) || !isset($originParts['host']) || !is_array($configParts)) {
            return $configured;
        }

        $originHost = $originParts['host'];
        if ($originHost === '' || $originHost === 'localhost' || $originHost === '127.0.0.1') {
            return $configured;
        }

        $scheme = $configParts['scheme'] ?? 'http';
        $port = isset($configParts['port']) ? ':' . $configParts['port'] : '';

        return $scheme . '://' . $originHost . $port;
    }

    private static function shouldRewriteForLan(string $configured): bool
    {
        if ((string) env('APP_ENV', 'production') !== 'local') {
            return false;
        }

        $parts = parse_url($configured);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }

        return in_array($parts['host'], ['localhost', '127.0.0.1'], true);
    }
}
