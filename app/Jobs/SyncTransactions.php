<?php

namespace App\Jobs;

use App\DataProviders\CexServiceProvider;
use App\Models\Transaction;
use App\Services\ExchangeService;
use App\Services\PortfolioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

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
    public function handle(CexServiceProvider $cexService): void
    {
        $currencies = $this->portfolio->assets()
                    ->pluck('symbol')
                    ->map(fn ($asset) => strtoupper($asset))
                    ->toArray();
        $listId = $this->portfolio->assets()->pluck('assets.id','symbol')->toArray();
        $lastUpdated = $this->portfolio->last_updated ?? $this->portfolio->created_at;

        $symbols = array_map(fn ($asset) => strtoupper($asset) . '/USDT', $currencies);

        // Fetch both data sources independently — one failing doesn't block the other
        $transactions = [];
        $movements = [];
        $errors = [];

        try {
            $transactions = $this->exchangeService->syncTransactions($symbols, $lastUpdated, $this->userId);
        } catch (\Throwable $e) {
            Log::error("SyncTransactions: failed to fetch trades: " . $e->getMessage());
            $errors[] = 'Failed to fetch trades: ' . $e->getMessage();
        }

        try {
            $movements = $this->exchangeService->syncDepositsWithdrawals($currencies, $lastUpdated, $this->userId);
        } catch (\Throwable $e) {
            Log::error("SyncTransactions: failed to fetch deposits/withdrawals: " . $e->getMessage());
            $errors[] = 'Failed to fetch deposits/withdrawals: ' . $e->getMessage();
        }

        // Both failed — emit error event and bail
        if (!empty($errors) && empty($transactions) && empty($movements)) {
            $cexService->emitEvent('sync-transactions', [
                'success' => false,
                'errors' => $errors,
            ], $this->userId);
            return;
        }

        if (empty($transactions) && empty($movements)) {
            $this->portfolio->last_updated = date('Y-m-d');
            $this->portfolio->save();
            $eventData = [
                'success' => true,
                'data' => [],
                'errors' => $errors,
            ];
            if (empty($errors)) {
                Redis::set("sync_transactions_{$this->jobId}", json_encode($eventData), 'EX', 3600);
            }
            $cexService->emitEvent('sync-transactions', $eventData, $this->userId);
            return;
        }

        // Collect processed results for the final event
        $processedData = [];

        // --- Process BUY/SELL transactions ---
        if (!empty($transactions)) {
            $activities = collect($transactions)->groupBy('symbol')->map(function ($group) {
                return [
                    'symbol' => strtolower(explode('/', $group->first()['symbol'])[0]),
                    'count' => $group->count(),
                ];
            })->values()->toArray();
            foreach ($activities as $activity) {
                PortfolioService::storeRecentActivity($this->userId, 'Sync asset transactions', $listId[$activity['symbol']], $activity['count']);
            }

            foreach ($transactions as $transaction) {
                $asset = strtolower(explode('/', $transaction['symbol'])[0]);
                DB::beginTransaction();
                try {
                    $transaction_id = Transaction::query()->insertGetId([
                        'portfolio_id' => $this->portfolio->id,
                        'asset_id' => $listId[$asset],
                        'exchange_id' => config('exchanges.' . strtolower($transaction['exchange']) . '_id'),
                        'quantity' => $transaction['amount'],
                        'price' => $transaction['price'],
                        'type' => strtoupper($transaction['side']),
                        'transact_date' => date('Y-m-d H:i:s', $transaction['timestamp'] / 1000),
                    ]);

                    $transaction_history = Transaction::where('portfolio_id', $this->portfolio->id)
                        ->where('asset_id', $listId[$asset])
                        ->orderBy('transact_date')
                        ->get();
                    $avg_price = PortfolioService::calculateAvgPrice($transaction_history);
                    $updated_amount = $transaction['side'] === 'buy' ? (float)$transaction['amount'] : (float)-$transaction['amount'];
                    DB::update('UPDATE portfolio_asset
                            SET amount = amount + :amount,
                                avg_price = :avg_price
                            WHERE portfolio_id = :portfolio_id
                            AND asset_id = :asset_id', [
                            'amount' => $updated_amount,
                            'avg_price' => $avg_price['average_price'],
                            'portfolio_id' => $this->portfolio->id,
                            'asset_id' => $listId[$asset]
                        ]
                    );
                    DB::commit();

                    $processedData[] = [
                        'id' => $transaction_id,
                        'symbol' => $asset,
                        'type' => strtoupper($transaction['side']),
                        'quantity' => $transaction['amount'],
                        'price' => $transaction['price'],
                        'exchange' => $transaction['exchange'],
                        'transact_date' => $transaction['timestamp'],
                    ];
                }
                catch (\Exception $e) {
                    DB::rollBack();
                    Log::error("Failed to save transaction for asset $asset: " . $e->getMessage());
                    continue;
                }
            }
        }

        // --- Process Deposit/Withdrawal movements ---
        foreach ($movements as $movement) {
            $currency = strtolower($movement['currency']);

            if (!isset($listId[$currency])) {
                continue;
            }

            $assetId      = $listId[$currency];
            $movementType = strtoupper($movement['type']);
            $amount       = (float)$movement['amount'];
            $amountDelta  = $movementType === 'DEPOSIT' ? $amount : -$amount;

            DB::beginTransaction();
            try {
                $transaction_id = Transaction::query()->insertGetId([
                    'portfolio_id'  => $this->portfolio->id,
                    'asset_id'      => $assetId,
                    'exchange_id'   => config('exchanges.' . strtolower($movement['exchange']) . '_id'),
                    'quantity'      => $amount,
                    'price'         => 0,
                    'type'          => $movementType,
                    'transact_date' => date('Y-m-d H:i:s', $movement['timestamp'] / 1000),
                ]);

                DB::update('UPDATE portfolio_asset
                        SET amount = amount + :amount
                        WHERE portfolio_id = :portfolio_id
                        AND asset_id = :asset_id', [
                    'amount'       => $amountDelta,
                    'portfolio_id' => $this->portfolio->id,
                    'asset_id'     => $assetId,
                ]);

                DB::commit();

                $activityType = $movementType === 'DEPOSIT' ? 'Deposit' : 'Withdrawn';
                PortfolioService::storeRecentActivity($this->userId, $activityType, $assetId, null, $amount);

                $processedData[] = [
                    'id' => $transaction_id,
                    'symbol' => $currency,
                    'type' => $movementType,
                    'quantity' => $amount,
                    'price' => 0,
                    'exchange' => $movement['exchange'],
                    'transact_date' => $movement['timestamp'],
                ];
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Failed to save $movementType for " . strtoupper($currency) . ": " . $e->getMessage());
                continue;
            }
        }

        // Update portfolio.last_updated to the most recent timestamp across both sets
        $allTimestamps = array_merge(
            array_column($transactions, 'timestamp'),
            array_column($movements, 'timestamp')
        );
        if (!empty($allTimestamps)) {
            $this->portfolio->last_updated = date('Y-m-d H:i:s', max($allTimestamps) / 1000);
            $this->portfolio->save();
        }

        $eventData = [
            'success' => true,
            'data' => $processedData,
            'errors' => $errors,
        ];

        // Cache result for 1 hour when both fetches succeeded (no errors)
        if (empty($errors)) {
            Redis::set("sync_transactions_{$this->jobId}", json_encode($eventData), 'EX', 3600);
        }

        // Emit combined event to client
        $cexService->emitEvent('sync-transactions', $eventData, $this->userId);
    }
}
