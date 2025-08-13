<?php

namespace App\Jobs;

use App\Models\Portfolio;
use App\Models\Transaction;
use App\Services\PortfolioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AddTokenToPort implements ShouldQueue
{
    use Queueable;

    protected $data;
    protected $userId;
    protected $assetService;
    protected $exchangeService;
    /**
     * Create a new job instance.
     */
    public function __construct($data, $userId, $assetService, $exchangeService)
    {
        $this->assetService = $assetService;
        $this->exchangeService = $exchangeService;
        $this->data = $data;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $portfolio = Portfolio::findOrFail($this->data['portfolio_id']);
                
            foreach ($this->data['token'] as $token) {
                $tmp = $this->assetService->checkAssetExists($token['symbol']);
                if (!$tmp) {
                    throw new \Exception("Token {$token['symbol']} not found.");
                }
                else {
                    $listTokenID[] = $tmp[0]['id'];
                    $listTokenAmount[] = $token['amount'];
                    $listSymbols[] = [
                        'name' => $token['symbol'] . '/USDT',
                        'exchange' => $token['exchange'],
                    ];
                }
            }
            $transactions = $this->exchangeService->getSymbolTransactions($listSymbols);
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
            });
            foreach($listTokenID as $assetId) {
                PortfolioService::storeRecentActivity($this->userId, 'Add asset', $assetId);
            }
            $count = count($this->data['token']);
            $emit = Http::post(config('app.cex_service_url') . '/emit-event', [
                'event' => 'add-token-to-port',
                'data' => ['success' => true, 'message' => "{$count} tokens added to portfolio successfully"],
                'userId' => $this->userId,
            ])->throw()->json();
        }
        catch (\Throwable $th) {
            $emit = Http::post(config('app.cex_service_url') . '/emit-event', [
                'event' => 'add-token-to-port',
                'data' => ['success' => false, 'error' => $th->getMessage()],
                'userId' => $this->userId,
            ])->throw()->json();
            Log::error("Failed to add token to portfolio: " . $th->getMessage());
        }
    }
}
