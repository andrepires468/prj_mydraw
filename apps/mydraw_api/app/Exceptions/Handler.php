<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
        ApiException::class,
    ];

    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * @param  Request  $request
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof ApiException) {
            return ApiResponse::error(
                $exception->getMessage() ?: 'Request failed.',
                $exception->getStatusCode(),
                $exception->getData()
            );
        }

        if ($exception instanceof ValidationException) {
            return ApiResponse::error(
                $exception->getMessage() ?: 'Validation failed.',
                $exception->status,
                ['errors' => $exception->errors()]
            );
        }

        if ($exception instanceof ModelNotFoundException) {
            return ApiResponse::error('Resource not found.', 404);
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $msg = $exception->getMessage();

            return ApiResponse::error(
                $msg !== '' ? $msg : $this->defaultMessageForStatus($status),
                $status
            );
        }

        if ($exception instanceof AuthorizationException) {
            return ApiResponse::error($exception->getMessage() ?: 'Forbidden.', 403);
        }

        if (env('APP_DEBUG')) {
            return parent::render($request, $exception);
        }

        return ApiResponse::error('Internal server error.', 500);
    }

    private function defaultMessageForStatus(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'Unauthorized.',
            403 => 'Forbidden.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            409 => 'Conflict.',
            422 => 'Unprocessable entity.',
            429 => 'Too many requests.',
            500 => 'Internal server error.',
            502 => 'Bad gateway.',
            503 => 'Service unavailable.',
            default => 'Error.',
        };
    }
}
