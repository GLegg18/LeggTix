<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiErrorResponse
{
    public static function expectsJson(Request $request): bool
    {
        return $request->is('api', 'api/*') || $request->expectsJson();
    }

    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! self::expectsJson($request)
            || $exception instanceof ValidationException
            || $exception instanceof HttpResponseException) {
            // Keep Laravel's field errors and intentionally constructed responses intact.
            return null;
        }

        if ($exception instanceof AuthenticationException) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $message = match ($status) {
                400 => 'The request could not be understood.',
                401 => 'Unauthenticated.',
                403 => 'You are not allowed to perform this action.',
                404 => 'Resource not found.',
                405 => 'This HTTP method is not allowed.',
                413 => 'The request body is too large.',
                415 => 'Use a supported request content type.',
                419 => 'Your session has expired. Refresh the page and try again.',
                429 => 'Too many requests. Please try again later.',
                500 => 'An unexpected error occurred. Please try again later.',
                503 => 'The service is temporarily unavailable. Please try again shortly.',
                default => Response::$statusTexts[$status] ?? 'The request could not be completed.',
            };

            // Exception messages may contain model IDs, paths, or transport credentials.
            return response()->json(['message' => $message], $status, $exception->getHeaders());
        }

        if (app()->environment(['local', 'testing']) && config('app.debug')) {
            return null;
        }

        return response()->json(['message' => 'An unexpected error occurred. Please try again later.'], 500);
    }
}
