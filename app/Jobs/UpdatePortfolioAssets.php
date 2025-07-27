<?php

namespace App\Jobs;

use App\Models\Portfolio;
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
        $this->exchange = $exchange;
        $this->credentials = $credentials;
        $this->user_id = $user_id;
        $this->cex_name = $cex_name;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $response = Http::post(config('app.cex_service_url') . '/cex/portfolio', [
                'credentials' => $this->credentials,
                'exchanges' => $this->exchange,
            ])->throw()->json();
            $balance = $response['data'][$this->cex_name]['total'];
            if(empty($balance)) return;
            // Filter balance to only include assets in the user's portfolio
            $portfolio = Portfolio::with(['assets'])->where('user_id', $this->user_id)->first();
            $portfolioSymbol = $portfolio->assets->pluck('symbol')->toArray();
            $portfolioSymbol = array_filter($portfolioSymbol, function($item) use ($balance) {
                return isset($balance[strtoupper($item)]);
            });
            
            if(empty($portfolioSymbol)) return;
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
                    $amount = $portfolio->assets->find($listId[$symbol])->pivot->amount;
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
                }
                
            }
            // Store recent activity
            foreach ($trades as $index => $trade) {
                PortfolioService::storeRecentActivity($this->user_id, 'Update asset', $listId[$portfolioSymbol[$index]], count($trade->json()['data'] ?? []));
            }
            DB::commit();
            // Clear Redis cache for the user
            $emit = Http::post(config('app.cex_service_url') . '/cex/update-portfolio', [
                'user_id' => $this->user_id,
                'status' => true,
            ])->throw()->json();
            Redis::del("cex_info_{$this->user_id}");
        }
        catch (\Throwable $th) {
            DB::rollBack();
            $emit = Http::post(config('app.cex_service_url') . '/cex/update-portfolio', [
                'user_id' => $this->user_id,
                'status' => false,
            ])->throw()->json();
            Log::error("Failed to update portfolio assets: " . $th->getMessage());
        }
    }
}
