<?php

namespace App\Jobs;

use App\DataProviders\CexServiceProvider;
use App\Models\Portfolio;
use App\Models\PortfolioAssetWallet;
use App\Models\Transaction;
use App\Services\AssetService;
use App\Services\ExchangeService;
use App\Services\PortfolioService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AddTokenToPort implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    protected $data;
    protected $userId;

    /**
     * Hold the lock no longer than the job can plausibly run, so a worker killed
     * mid-job can't wedge the portfolio permanently.
     */
    public $uniqueFor = 600;

    /**
     * Create a new job instance.
     */
    public function __construct($data, $userId)
    {
        $this->data = $data;
        $this->userId = $userId;
    }

    /**
     * One in-flight add per portfolio per token set — a double-submit from the UI
     * would otherwise attach the same assets twice.
     */
    public function uniqueId(): string
    {
        $symbols = array_map(fn ($token) => strtoupper($token['symbol']), $this->data['token']);
        sort($symbols);

        return $this->userId . ':' . $this->data['portfolio_id'] . ':' . md5(implode(',', $symbols));
    }

    /**
     * Execute the job.
     */
    public function handle(AssetService $assetService, CexServiceProvider $cexService, ExchangeService $exchangeService): void
    {
        $exchangeService = $exchangeService->forUser($this->userId);

        try {
            $portfolio = Portfolio::findOrFail($this->data['portfolio_id']);
                
            foreach ($this->data['token'] as $token) {
                $tmp = $assetService->checkAssetExists($token['symbol']);
                if (!$tmp) {
                    throw new \Exception("Token {$token['symbol']} not found.");
                }
                else {
                    $listTokenID[] = $tmp->id;
                    $listTokenAmount[] = $token['amount'];
                    $listSymbols[] = [
                        'name' => $token['symbol'] . '/USDT',
                        'exchange' => $token['exchange'],
                    ];
                }
            }
            $transactions = $exchangeService->getSymbolTransactions($listSymbols);
            $formattedTransactions = [];

            foreach ($listSymbols as $index => $symbol) {
                $assetID = $listTokenID[$index];
                $symbolTransactions = $transactions[$symbol['name']];
            
                $listAvgPrice[] = PortfolioService::calculateAvgPrice($symbolTransactions);

                foreach ($symbolTransactions as $transaction) {
                    $exchange_id = config('exchanges.' . strtolower($transaction['exchange']) . '_id');
                    $formattedTransactions[] = [
                        'exchange_id' => $exchange_id,
                        'portfolio_id' => $portfolio->id,
                        'asset_id' => $assetID,
                        'quantity' => $transaction['quantity'],
                        'price' => $transaction['price'],
                        'type' => $transaction['type'],
                        'transact_date' => $transaction['transact_date']
                    ];
                }
                
            }
            DB::transaction(function () use ($formattedTransactions, $listTokenID, $listTokenAmount, $listAvgPrice, $portfolio) {
                Transaction::upsert($formattedTransactions, [], ['type', 'price', 'quantity', 'transact_date']);
                $assetsToAttach = array_combine($listTokenID, array_map(fn($amount, $avgPrice) =>
                ['amount' => $amount, 'avg_price' => $avgPrice['average_price']], $listTokenAmount, $listAvgPrice));
                $portfolio->assets()->attach($assetsToAttach);

                // Persist per-wallet breakdown passed through from /exchange/info
                $pivotIds = DB::table('portfolio_asset')
                    ->where('portfolio_id', $portfolio->id)
                    ->whereIn('asset_id', $listTokenID)
                    ->pluck('id', 'asset_id');
                $walletRows = [];
                foreach (array_values($this->data['token']) as $index => $token) {
                    $assetId = $listTokenID[$index];
                    foreach ($token['wallets'] ?? [] as $w) {
                        if (!isset($w['exchange'], $w['wallet_type'], $w['amount'], $pivotIds[$assetId])) continue;
                        $walletRows[] = [
                            'portfolio_asset_id' => $pivotIds[$assetId],
                            'exchange' => $w['exchange'],
                            'wallet_type' => $w['wallet_type'],
                            'amount' => $w['amount'],
                        ];
                    }
                }
                if (!empty($walletRows)) {
                    PortfolioAssetWallet::upsert(
                        $walletRows,
                        ['portfolio_asset_id', 'exchange', 'wallet_type'],
                        ['amount']
                    );
                }
            });
            foreach($listTokenID as $assetId) {
                PortfolioService::storeRecentActivity($this->userId, 'Add asset', $assetId);
            }
            $count = count($this->data['token']);
            $cexService->emitEvent(
                'add-token-to-port', 
                [
                    'success' => true, 
                    'message' => "$count tokens added to portfolio successfully",
                    'data' => $portfolio->assets
                ], 
                $this->userId
            );
        }
        catch (\Throwable $th) {
            $cexService->emitEvent(
                'add-token-to-port', 
                ['success' => false, 'error' => $th->getMessage()], 
                $this->userId
            );
            Log::error("Failed to add token to portfolio: " . $th->getMessage());
        }
    }
}
