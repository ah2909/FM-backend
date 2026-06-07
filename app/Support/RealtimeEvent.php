<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

class RealtimeEvent
{
    private const CHANNEL = 'app:events';

    // Publishes a realtime event the websocket service emits to the user's room.
    public static function publish(string $event, mixed $data, int $userId): void
    {
        Redis::publish(self::CHANNEL, json_encode([
            'event'  => $event,
            'userId' => $userId,
            'data'   => $data,
        ]));
    }
}
