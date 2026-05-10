<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Envelope JSON padronizado para todas as respostas da API.
 *
 * @phpstan-type ApiEnvelope array{Status: bool, Code: int, Message: string, Data: mixed}
 */
final class ApiResponse
{
    /**
     * @param  mixed  $data  Payload em Data (objeto, array, escalar ou null).
     */
    public static function success(mixed $data = [], string $message = '', int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'Status'  => true,
            'Code'    => $httpStatus,
            'Message' => $message,
            'Data'    => $data,
        ], $httpStatus);
    }

    /**
     * @param  mixed  $data  Detalhes adicionais (ex.: errors de validação).
     */
    public static function error(string $message, int $httpStatus = 400, mixed $data = []): JsonResponse
    {
        return response()->json([
            'Status'  => false,
            'Code'    => $httpStatus,
            'Message' => $message,
            'Data'    => $data,
        ], $httpStatus);
    }
}
