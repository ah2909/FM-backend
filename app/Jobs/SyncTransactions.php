<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\ExchangeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SyncTransactions implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    protected $exchangeService;
    protected $jobId;
    protected $portfolio;

    public function __construct(ExchangeService $exchangeService, $jobId, $portfolio)
    {
        $this->exchangeService = $exchangeService;
        $this->jobId = $jobId;
        $this->portfolio = $portfolio;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $symbols = $this->portfolio->assets()
                    ->pluck('symbol')
                    ->map(fn ($asset) => strtoupper($asset) . '/USDT')
                    ->toArray();
        $listId = $this->portfolio->assets()->pluck('assets.id','symbol')->toArray();
        $transactions = $this->exchangeService->syncTransactions($symbols, $this->portfolio->last_updated ?? null);
        if (empty($transactions)) {
           Cache::put("sync_transactions_$this->jobId", [], 3600);
        }

        // Process and save transactions
        foreach ($transactions as $transaction) {
            $asset = strtolower(explode('/', $transaction['symbol'])[0]);
            Transaction::create([
                'portfolio_id' => $this->portfolio->id,
                'asset_id' => $listId[$asset],
                'exchange_id' => config('exchanges.' . strtolower($transaction['exchange']) . '_id'),
                'quantity' => $transaction['amount'],
                'price' => $transaction['price'],
                'type' => $transaction['side'],
                'transact_date' => date('Y-m-d H:i:s', $transaction['timestamp'] / 1000),
            ]);
        }
        $this->portfolio->last_updated = date('Y-m-d H:i:s', $transactions[0]['timestamp'] / 1000);
        $this->portfolio->save();
        Cache::put("sync_transactions_$this->jobId", $transactions, 3600);
    }
}
