<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\ExchangeService;
use App\Services\PortfolioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncTransactions implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    protected $exchangeService;
    protected $jobId;
    protected $portfolio;
    protected $userId;

    public function __construct(ExchangeService $exchangeService, $jobId, $portfolio, $userId)
    {
        $this->exchangeService = $exchangeService;
        $this->jobId = $jobId;
        $this->portfolio = $portfolio;
        $this->userId = $userId;
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
        $lastUpdated = $this->portfolio->last_updated;
        if(!$lastUpdated) {
            $lastTransaction = Transaction::where('portfolio_id', $this->portfolio->id)
            ->orderBy('transact_date', 'desc')
            ->first();
            $lastUpdated = $lastTransaction ? $lastTransaction->transact_date : null;
        }
        
        $transactions = $this->exchangeService->syncTransactions($symbols, $lastUpdated, $this->userId);
        if (empty($transactions)) {
           Cache::put("sync_transactions_$this->jobId", [], 3600);
           return;
        }

        // Store recent activity
        // Filter transactions to group like asset => count(transactions)
        $activities = collect($transactions)->groupBy('symbol')->map(function ($group) {
            return [
                'symbol' => strtolower(explode('/', $group->first()['symbol'])[0]),
                'count' => $group->count(),
            ];
        })->values()->toArray();
        foreach ($activities as $activity) {
            PortfolioService::storeRecentActivity($this->userId, 'Sync asset transactions', $listId[$activity['symbol']], $activity['count']);
        }

        // Process and save transactions
        foreach ($transactions as $transaction) {
            $asset = strtolower(explode('/', $transaction['symbol'])[0]);
            // Update asset amount in portfolio_assets
            DB::beginTransaction();
            try {
                Transaction::create([
                    'portfolio_id' => $this->portfolio->id,
                    'asset_id' => $listId[$asset],
                    'exchange_id' => config('exchanges.' . strtolower($transaction['exchange']) . '_id'),
                    'quantity' => $transaction['amount'],
                    'price' => $transaction['price'],
                    'type' => $transaction['side'],
                    'transact_date' => date('Y-m-d H:i:s', $transaction['timestamp'] / 1000),
                ]);
                $transaction_history = Transaction::where('portfolio_id', $this->portfolio->id)
                    ->where('asset_id', $listId[$asset])
                    ->orderBy('transact_date')
                    ->get();
                $avg_price = PortfolioService::calculateAvgPrice($transaction_history);
                $updated_amount = $transaction['side'] === 'buy' ? (int)$transaction['amount'] : -(int)$transaction['amount'];
                DB::update('UPDATE portfolio_asset 
                        SET amount = amount + ?, avg_price = ? 
                        WHERE portfolio_id = ? AND asset_id = ?', [
                    $updated_amount,
                    $avg_price['average_price'],
                    $this->portfolio->id,
                    $listId[$asset]
                ]);
                DB::commit();
            }
            catch (\Exception $e) {
                DB::rollBack();
                // Log the error or handle it as needed
                Log::error("Failed to save transaction for asset $asset: " . $e->getMessage());
                continue; // Skip this transaction and continue with the next one
            }
        }
        $this->portfolio->last_updated = date('Y-m-d H:i:s', $transactions[0]['timestamp'] / 1000);
        $this->portfolio->save();
        Cache::put("sync_transactions_$this->jobId", $transactions, 3600);
    }
}
