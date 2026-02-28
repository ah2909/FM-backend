<?php

namespace App\Jobs;

use App\DataProviders\CexServiceProvider;
use App\Models\Portfolio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class AnalyzePortfolio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $userId;
    protected int $portfolioId;
    protected string $jobId;

    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId, int $portfolioId, string $jobId)
    {
        $this->userId = $userId;
        $this->portfolioId = $portfolioId;
        $this->jobId = $jobId;
    }

    /**
     * Execute the job.
     */
    public function handle(CexServiceProvider $cexService): void
    {
        try {
            $portfolio = Portfolio::with([
                'assets',
                'transactions',
            ])->where('user_id', $this->userId)
                ->first();

            if (!$portfolio) {
                throw new \Exception("Portfolio not found for user.");
            }

            // Map to Python PortfolioAssetIn schema
            $portfolioPayload = $portfolio->assets->map(function ($asset) {
                return [
                    'symbol'        => strtoupper($asset->symbol),
                    'amount'        => (float) $asset->pivot->amount,
                    'avg_price'     => (float) $asset->pivot->avg_price,
                    'current_value' => 0.0,
                ];
            })->values()->toArray();

            // Map to Python TransactionIn schema
            $transactionPayload = $portfolio->transactions->map(function ($tx) {
                return [
                    'symbol'   => strtoupper($tx->asset->symbol),
                    'type'     => strtolower($tx->type),
                    'quantity' => (float) $tx->quantity,
                    'price'    => (float) $tx->price,
                    'date'     => $tx->transact_date
                        ? $tx->transact_date->toDateString()
                        : now()->toDateString(),
                ];
            })->values()->toArray();

            $response = Http::timeout(90)
                ->post(config('app.portfolio_analyzer_url') . '/api/analyze', [
                    'user_id'      => (string) $this->userId,
                    'portfolio'    => $portfolioPayload,
                    'transactions' => $transactionPayload,
                ])
                ->throw()
                ->json();

            $result = $response ?? [];
            $cacheKey = "portfolio_analysis_{$this->userId}_{$this->portfolioId}";

            if (!empty($result['data'])) {
                Redis::set($cacheKey, json_encode($result['data']), 'EX', 14400);
            }

            $cexService->emitEvent(
                'portfolio-analysis',
                ['success' => true, 'data' => $result['data'], 'job_id' => $this->jobId],
                $this->userId
            );
        } catch (\Exception $e) {
            Log::error('Portfolio analyzer job failed: ' . $e->getMessage(), [
                'user_id'      => $this->userId,
                'portfolio_id' => $this->portfolioId,
            ]);

            $cexService->emitEvent(
                'portfolio-analysis',
                ['success' => false, 'error' => $e->getMessage(), 'job_id' => $this->jobId],
                $this->userId
            );
        }
    }
}
