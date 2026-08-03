<?php

namespace App\Http\Controllers;

use App\Jobs\ChatWithAgent;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    use ApiResponse, ErrorHandler;

    public function chat(Request $request)
    {
        try {
            $validated = $request->validate([
                'message' => 'required|string|max:2000',
                'conversation_id' => 'nullable|string|max:100',
            ]);

            $user = $request->attributes->get('user');
            $userId = (string) $user->id;
            $token = $request->bearerToken() ?? '';
            $conversationId = $validated['conversation_id'] ?? Str::uuid()->toString();

            // Dispatch async job to communicate with portfolio-analyzer
            ChatWithAgent::dispatch($userId, $conversationId, $validated['message'], $token);

            return $this->successResponse([
                'conversation_id' => $conversationId,
                'status' => 'streaming',
                'user_id' => $userId,
            ], 'Chat request received, agent streaming started', 202);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }
}
