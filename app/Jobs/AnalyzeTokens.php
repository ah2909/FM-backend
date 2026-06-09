<?php

namespace App\Jobs;

use App\DataProviders\CexServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class AnalyzeTokens implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $userId;
    protected string $symbol;
    protected string $jobId;

    public $timeout = 300;

    // Per-symbol outlook cache TTL (1 day) — outlooks are user-independent facts.
    private const CACHE_TTL = 86400;

    public function __construct(int $userId, string $symbol, string $jobId)
    {
        $this->userId = $userId;
        $this->symbol = strtoupper($symbol);
        $this->jobId = $jobId;
    }

    public function handle(CexServiceProvider $cexService): void
    {
        try {
            $cacheKey = "token_research:{$this->symbol}";

            // Backend-level cache: skip the analyzer call when this symbol is warm.
            if ($cached = Redis::get($cacheKey)) {
                $data = json_decode($cached, true);
            } else {
                $response = Http::timeout($this->timeout - 1)
                    ->post(config('app.portfolio_analyzer_url') . '/api/research', [
                        'user_id' => (string) $this->userId,
                        'symbol'  => $this->symbol,
                    ])
                    ->throw()
                    ->json();

                $data = $response['data'] ?? null;
                if (!empty($data)) {
                    Redis::set($cacheKey, json_encode($data), 'EX', self::CACHE_TTL);
                }
            }

            $cexService->emitEvent(
                'token-research',
                ['success' => true, 'data' => $data, 'job_id' => $this->jobId],
                $this->userId
            );
        } catch (\Exception $e) {
            Log::error('Token research job failed: ' . $e->getMessage(), [
                'user_id' => $this->userId,
                'symbol'  => $this->symbol,
            ]);

            $cexService->emitEvent(
                'token-research',
                ['success' => false, 'error' => $e->getMessage(), 'job_id' => $this->jobId],
                $this->userId
            );
        }
    }
}
