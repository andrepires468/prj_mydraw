<?php

namespace App\Exceptions;

use Throwable;

/**
 * Gemini REST failure with HTTP status in {@see Exception::getCode()} (0 if unknown).
 */
final class GeminiApiException extends \RuntimeException
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
