<?php

namespace App\Traits;

trait ApiResponse
{
    protected function successResponse($data, $message = null, $code = 200, $meta = null)
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        return response()->json($payload, $code);
    }

    protected function errorResponse($message, $code = 400)
    {
        return response()->json([
            'success' => false,
            'error' => $message
        ], $code);
    }
}