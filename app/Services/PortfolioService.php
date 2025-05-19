<?php
namespace App\Services;

class PortfolioService
{
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

    public function calculateAvgPrice($transactions) {
        $queue = [];
        $totalCost = 0;
        $totalQuantity = 0;
        $realizedPnL = 0;
    
        foreach ($transactions as $tx) {
            if(count($queue) === 0 && $tx['type'] === 'sell') {
                continue;
            }
            if ($tx['type'] === 'buy') {
                $queue[] = ['price' => $tx['price'], 'quantity' => $tx['quantity'], 'cost' => $tx['cost']];
                $totalCost += $tx['cost'];
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
                        $unitCost = $first['cost'] / $first['quantity'];
                        $sellCost += $sellQuantity * $unitCost;
                        $first['quantity'] -= $sellQuantity;
                        $first['cost'] -= $sellQuantity * $unitCost;
                        $sellQuantity = 0;
                    }
                }
            
                $realizedPnL += ($tx['cost'] - $sellCost);
                $totalCost -= $sellCost;
                $totalQuantity -= $tx['quantity'];
            }
        }
    
        $averageBuyPrice = ($totalQuantity > 0) ? ($totalCost / $totalQuantity) : 0;
        // $unrealizedPnL = ($totalAmount > 0) ? (($currentPrice * $totalAmount) - $totalCost) : 0;
    
        return [
            'average_price' => round($averageBuyPrice, 4),
            'realized_pnl' => round($realizedPnL, 4)
        ];
    }
}