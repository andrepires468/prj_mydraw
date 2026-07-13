<?php

namespace App\Exceptions;

use Throwable;

/**
 * Ollama HTTP/API failure. {@see Exception::getCode()} holds HTTP status (0 if unknown).
 */
final class OllamaApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $httpStatus = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->getCode();
    }
}
