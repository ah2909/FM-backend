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
}