<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Erro de negócio ou HTTP mapeado para o envelope {@see \App\Support\ApiResponse}.
 */
class ApiException extends HttpException
{
    /**
     * @param  mixed  $data  Conteúdo opcional do campo Data (ex.: código de erro estruturado).
     */
    public function __construct(
        int $statusCode = 400,
        string $message = '',
        protected mixed $data = [],
        ?Throwable $previous = null,
        array $headers = [],
        int $code = 0,
    ) {
        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
