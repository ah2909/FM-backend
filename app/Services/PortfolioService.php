<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PortfolioService
{
    //Clone from ExchangeService, using in StoreUserBalance command
    //The reason is not construct ExchangeService that need user_id get from JWT
    public function getPriceOfPort($assets)
    {
        try {
            $listSymbols = array_map(function ($a) {
                return strtoupper($a['symbol']) . '/USDT';
            }, $assets->toArray());

            $response = Http::post(config('app.cex_service_url') . '/cex/ticker', [
                'symbols' => $listSymbols
            ])->throw()->json();
            $tickers = $response['data'] ?? [];

            $result = [];
            foreach ($assets as $asset) {
                $price = null;
                $formattedSymbol = strtoupper($asset->symbol) . '/USDT';
                if (isset($tickers[$formattedSymbol])) {
                    $price = $tickers[$formattedSymbol]['last'];
                }
                else {
                    // Fetch price from coingecko API if not found in tickers
                    $tmp = strtolower($asset->symbol);
                    $coingecko = Http::withHeaders([
                        'accept' => 'application/json',
                        'x-cg-demo-api-key' => config('app.coingecko_api_key'),
                    ])->get(config('app.coingecko_url', 'https://api.coingecko.com/api/v3') . '/simple/price', [
                        'vs_currencies' => 'usd',
                        'symbols' => $tmp,
                    ])->json();
                    if (isset($coingecko[$tmp]['usd'])) {
                        $price = $coingecko[$tmp]['usd'];
                    }
                }

                $result[$asset->symbol] = [
                    'price' => $price,
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
        // if($actualAmount > $totalQuantity) {
        //     $averageBuyPrice = (($actualAmount - $totalQuantity) * $currentPrice + ($totalCost / $totalQuantity) * $totalQuantity) / 100;
        // }
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