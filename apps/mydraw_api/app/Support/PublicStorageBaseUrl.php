<?php

namespace App\Support;

/**
 * Base pública do MinIO alcançável pelo navegador.
 *
 * Em `APP_ENV=local`, se `MINIO_PUBLIC_URL` apontar para localhost e a requisição
 * vier com `Origin` de outro host (ex.: IP da LAN), o hostname da base pública
 * passa a ser o do Origin — mantendo esquema e porta configurados no .env —
 * para que imagens funcionem ao acessar o Vite por http://192.168.x.x:5173.
 *
 * Capacitor/Android envia `Origin: http://localhost` ou vazio — use
 * `MINIO_PUBLIC_LAN_URL` (ex.: http://192.168.x.x:9100) nesses casos.
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
        if ($origin !== '') {
            $originParts = parse_url($origin);
            if (is_array($originParts) && isset($originParts['scheme'], $originParts['host'])) {
                $scheme = strtolower((string) $originParts['scheme']);
                if (in_array($scheme, ['capacitor', 'ionic'], true)) {
                    return self::lanBaseOrConfigured($configured);
                }

                $originHost = (string) $originParts['host'];
                if ($originHost !== '' && !in_array($originHost, ['localhost', '127.0.0.1'], true)) {
                    $configParts = parse_url($configured);
                    if (!is_array($configParts)) {
                        return $configured;
                    }

                    $httpScheme = $configParts['scheme'] ?? 'http';
                    $port = isset($configParts['port']) ? ':' . $configParts['port'] : '';

                    return $httpScheme . '://' . $originHost . $port;
                }
            }
        }

        return self::lanBaseOrConfigured($configured);
    }

    private static function lanBaseOrConfigured(string $configured): string
    {
        $lan = trim((string) env('MINIO_PUBLIC_LAN_URL', ''));
        if ($lan !== '') {
            return rtrim($lan, '/');
        }

        return $configured;
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
