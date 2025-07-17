<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Throwable;

trait ErrorHandler
{
    protected function logError(Throwable $e, array $context = [])
    {
        Log::error($e->getMessage(), array_merge([
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], $context));
    }

    protected function handleException(Throwable $e, array $context = [])
    {
        $this->logError($e, $context);
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return $this->errorResponse($e->validator->errors()->first(), 422);
        }

        if ($e instanceof \Illuminate\Database\QueryException) {
            return $this->errorResponse('Database error: ' . $e->getMessage(), 500);
        }

        if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $this->errorResponse('Resource not found', 404);
        }

        if ($e instanceof \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException) {
            return $this->errorResponse('Unauthorized', 401);
        }
        return $this->errorResponse(config('app.debug') ? $e->getMessage() : 'An error occurred while processing your request');
    }
}