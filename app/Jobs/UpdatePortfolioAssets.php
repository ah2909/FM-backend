<?php

namespace App\Jobs;

use App\DataProviders\CexServiceProvider;
use App\Models\Portfolio;
use App\Models\PortfolioAssetWallet;
use App\Models\Transaction;
use App\Services\PortfolioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class UpdatePortfolioAssets implements ShouldQueue
{
    use Queueable;

    protected $exchange;
    protected $credentials;
    protected $user_id;
    protected $cex_name;
    /**
     * Create a new job instance.
     */
    public function __construct($exchange, $credentials, $user_id, $cex_name)
    {
        $this->exchange = [$exchange];
        $this->credentials = $credentials;
        $this->user_id = $user_id;
        $this->cex_name = $cex_name;
    }

    /**
     * Execute the job.
     */
    public function handle(CexServiceProvider $cexService): void
    {
        $portfolio = Portfolio::with(['assets'])->where('user_id', $this->user_id)->first();
        $portfolioSymbol = $portfolio->assets->pluck('symbol')->toArray();
        if(empty($portfolioSymbol)) {
            Log::info("No assets in portfolio for user {$this->user_id}, skipping update.");
            $cexService->emitEvent('update_portfolio', ['success' => true, 'updated' => false], $this->user_id);
            return;
        }

        try {
            $response = $cexService->getPortfolioBalance($this->credentials, $this->exchange);
            $result = $response[$this->cex_name] ?? null;
            // Hard failure (bad creds, service down) must retry — an empty map here would zero balances
            if (!is_array($result) || isset($result['status_error'])) {
                throw new \Exception("CEX wallet scan failed for {$this->cex_name}: " . ($result['status_error'] ?? 'no response'));
            }

            $balance = $result['totals'] ?? $result['total'] ?? [];
            if(empty($balance)) {
                $cexService->emitEvent('update_portfolio', ['success' => true, 'updated' => false], $this->user_id);
                return;
            }

            // Per-symbol wallet breakdown from successfully scanned wallets only
            $walletsBySymbol = [];
            foreach ($result['wallets'] ?? [] as $walletType => $wallet) {
                if (($wallet['status'] ?? '') !== 'ok') continue;
                foreach ($wallet['balances'] ?? [] as $currency => $amount) {
                    $walletsBySymbol[strtoupper($currency)][] = [
                        'wallet_type' => $walletType,
                        'amount' => $amount,
                    ];
                }
            }
            // Filter balance to only include assets in the user's portfolio
            $portfolioSymbol = array_filter($portfolioSymbol, function($item) use ($balance) {
                return isset($balance[strtoupper($item)]);
            });
            
            if(empty($portfolioSymbol)) {
                Log::info("No matching assets in portfolio for user {$this->user_id} after filtering, skipping update.");
                $cexService->emitEvent('update_portfolio', ['success' => true, 'updated' => false], $this->user_id);
                return;
            }
                
            // Update amount of assets in portfolio_assets and sync transactions
            $listId = $portfolio->assets->pluck('id','symbol')->toArray();
            // Fetch transactions for the asset in the connected exchange
            $trades = Http::pool(function (Pool $pool) use ($portfolioSymbol) {
                return array_map(fn ($s) => $pool->post(config('app.cex_service_url') . '/cex/transaction', [
                    'symbol' => strtoupper($s) . '/USDT',
                    'exchanges' => $this->exchange,
                    'credentials' => $this->credentials,
                ]), $portfolioSymbol);
            });
            DB::beginTransaction();
            foreach ($portfolioSymbol as $index => $symbol) {
                $formattedTrades = array_map(function ($trade) use ($listId, $portfolio, $symbol) {
                    return [
                        'portfolio_id' => $portfolio->id,
                        'asset_id' => $listId[$symbol],
                        'exchange_id' => config('exchanges.' . strtolower($this->cex_name) . '_id'),
                        'type' => $trade['side'],
                        'price' => $trade['price'],
                        'quantity' => $trade['amount'],
                        'transact_date' => date('Y-m-d', $trade['timestamp'] / 1000),
                    ];
                }, $trades[$index]->json()['data'] ?? []);
                
                Transaction::insert($formattedTrades);

                if (isset($listId[$symbol])) {
                    // Add amount to the existsymbol in portfolio_assets
                    $pivot = $portfolio->assets->find($listId[$symbol])->pivot;
                    $amount = $pivot->amount;
                    $transaction_history = Transaction::where('portfolio_id', $portfolio->id)
                        ->where('asset_id', $listId[$symbol])
                        ->orderBy('transact_date')
                        ->get();
                    // Re-calculate avg_price
                    $avg_price = PortfolioService::calculateAvgPrice($transaction_history);
                    $portfolio->assets()->updateExistingPivot($listId[$symbol], [
                        'amount' => $amount + $balance[strtoupper($symbol)],
                        'avg_price' => $avg_price['average_price'] ?? 0
                    ]);

                    // Errored wallets keep their previous rows: stale-but-honest beats zeroed
                    $walletRows = [];
                    foreach ($walletsBySymbol[strtoupper($symbol)] ?? [] as $w) {
                        $walletRows[] = [
                            'portfolio_asset_id' => $pivot->id,
                            'exchange' => $this->cex_name,
                            'wallet_type' => $w['wallet_type'],
                            'amount' => $w['amount'],
                        ];
                    }
                    if (!empty($walletRows)) {
                        PortfolioAssetWallet::upsert(
                            $walletRows,
                            ['portfolio_asset_id', 'exchange', 'wallet_type'],
                            ['amount']
                        );
                    }
                }

            }
            // Store recent activity
            foreach ($trades as $index => $trade) {
                PortfolioService::storeRecentActivity($this->user_id, 'Update asset', $listId[$portfolioSymbol[$index]], count($trade->json()['data'] ?? []));
            }
            DB::commit();
            
            $cexService->emitEvent('update_portfolio', ['success' => true, 'updated' => true], $this->user_id);
            // Clear Redis cache for the user
            Redis::del("cex_info_v2_{$this->user_id}");
            Redis::del("cex_raw_{$this->user_id}");
            Redis::del("portfolio_user_v2_{$this->user_id}");
        }
        catch (\Throwable $th) {
            DB::rollBack();
            $cexService->emitEvent('update_portfolio', ['success' => false], $this->user_id);
            Log::error("Failed to update portfolio assets: " . $th->getMessage());
        }
    }
}
