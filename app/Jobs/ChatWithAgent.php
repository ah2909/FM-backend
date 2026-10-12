<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatWithAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 65;

    protected string $userId;
    protected string $conversationId;
    protected string $message;
    protected string $token;

    public function __construct(string $userId, string $conversationId, string $message, string $token)
    {
        $this->userId = $userId;
        $this->conversationId = $conversationId;
        $this->message = $message;
        $this->token = $token;
    }

    public function handle(): void
    {
        $analyzerUrl = config('services.portfolio_analyzer.url', 'http://portfolio-analyzer:7070');
        $endpoint = "{$analyzerUrl}/api/chat";

        try {
            $response = Http::timeout(60)->post($endpoint, [
                'user_id' => (string) $this->userId,
                'conversation_id' => $this->conversationId,
                'message' => $this->message,
                'token' => $this->token,
            ]);

            if ($response->failed()) {
                Log::error("ChatWithAgent job failed calling analyzer: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("ChatWithAgent job exception: " . $e->getMessage());
        }
    }
}
