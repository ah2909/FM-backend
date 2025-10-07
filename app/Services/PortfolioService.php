<?php
namespace App\Services;

use App\DataProviders\CexServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\DataProviders\CoinGeckoProvider;

class PortfolioService
{
    private $coingecko;
    private $cexService;

    public function __construct(CoinGeckoProvider $coingecko, CexServiceProvider $cexService)
    {
        $this->coingecko = $coingecko;
        $this->cexService = $cexService;
    }

    //Clone from ExchangeService, using in StoreUserBalance command
    //The reason is not construct ExchangeService that need user_id get from JWT
    public function getPriceOfPort($assets)
    {
        try {
            $listSymbols = array_map(function ($a) {
                return strtoupper($a['symbol']) . '/USDT';
            }, $assets->toArray());

            $response = $this->cexService->fetchTicker($listSymbols);
            $tickers = $response ?? [];

            $result = [];
            foreach ($assets as $asset) {
                $price = null;
                $percentChange = null;
                $formattedSymbol = strtoupper($asset->symbol) . '/USDT';
                if (isset($tickers[$formattedSymbol])) {
                    $price = $tickers[$formattedSymbol]['last'];
                    $percentChange = $tickers[$formattedSymbol]['percentage'];
                }
                else {
                    // Fetch price from Coingecko provider if not found in tickers  
                    $price = $this->coingecko->getCurrentPrice(strtolower($asset->symbol));
                    $percentChange = 0;
                }

                $result[$asset->symbol] = [
                    'price' => $price,
                    'percentChange' => $percentChange,
                    'value' => $price !== null ? $price * $asset->amount : null
                ];
            }
            return $result;
        } catch (\Exception $e) {
            Log::error("Fetch price of portfolio failed: {$e->getMessage()}");
            return null;
        }
    }

    public function calculatePortfolioValue($portfolio, $priceData)
    {
        $totalValue = 0;
        $assets = $portfolio->assets->map(function ($asset) use ($priceData, &$totalValue) {
            $asset->price = $priceData[$asset->symbol]['price'];
            $asset->percentChange = $priceData[$asset->symbol]['percentChange'];
            $asset->value = $priceData[$asset->symbol]['value'];
            $totalValue += $asset->value;
            return $asset;
        });

        $portfolio->assets = $assets;
        $portfolio->totalValue = $totalValue;

        return $portfolio;
    }

    public static function calculateAvgPrice($transactions) {
        $queue = [];
        $totalCost = 0;
        $totalQuantity = 0;
        $realizedPnL = 0;
    
        foreach ($transactions as $tx) {
            $tx['type'] = strtolower($tx['type']);
            if(count($queue) === 0 && $tx['type'] === 'sell') {
                continue;
            }
            if ($tx['type'] === 'buy') {
                $cost = $tx['price'] * $tx['quantity'];
                $queue[] = ['price' => $tx['price'], 'quantity' => $tx['quantity'], 'cost' => $cost];
                $totalCost += $cost;
                $totalQuantity += $tx['quantity'];
            } 
            elseif ($tx['type'] === 'sell') {
                $sellQuantity = $tx['quantity'];
                $sellCost = 0;
            
                while ($sellQuantity > 0 && !empty($queue)) {
                    $first = &$queue[0];
                
                    if ($first['quantity'] <= $sellQuantity) {
                        $sellCost += $first['cost'];
                        $sellQuantity -= $first['quantity'];
                        array_shift($queue);
                    } else {
                        $unitCost = $first['price'];
                        $sellCost += $sellQuantity * $unitCost;
                        $first['quantity'] -= $sellQuantity;
                        $first['cost'] -= $sellQuantity * $unitCost;
                        $sellQuantity = 0;
                    }
                }
            
                $realizedPnL += ($tx['cost'] - $sellCost);
                $totalCost -= $sellCost;
                $totalQuantity = $totalQuantity - $tx['quantity'] < 0 ? 0 : $totalQuantity - $tx['quantity'];
            }
        }
        
        $averageBuyPrice = ($totalQuantity > 0) ? ($totalCost / $totalQuantity) : 0;
        // $unrealizedPnL = ($totalAmount > 0) ? (($currentPrice * $totalAmount) - $totalCost) : 0;
    
        return [
            'average_price' => round($averageBuyPrice, 4),
            'realized_pnl' => round($realizedPnL, 4)
        ];
    }

    public static function storeRecentActivity($userId, $type, $assetId, $count = null) {
        DB::table('recent_activity')->insert([
            'user_id' => $userId,
            'type' => $type,
            'asset_id' => $assetId,
            'transaction_count' => $count,
            'created_at' => now(),
        ]);
    }
}