<?php

namespace App\Services;

use App\Models\Asset;
use App\DataProviders\CoinGeckoProvider;

class AssetService
{
    private $coingecko;

    public function __construct(CoinGeckoProvider $coingecko)
    {
        $this->coingecko = $coingecko;
    }

    public function checkAssetExists($asset)
    {
        // Check if the asset exists in the database
        $symbol = strtolower($asset);
        $asset = Asset::where('symbol', $symbol)->first();
        if (!$asset) {
            $symbolInfo = $this->coingecko->getAssetInfo($symbol);
            if (isset($symbolInfo) && isset($symbolInfo['id'])) {
                $data = Asset::create([
                    'symbol' => $symbol,
                    'name' => $symbolInfo['name'],
                    'img_url' => $symbolInfo['image'],
                ]);
                $asset[] = $data;
            } else {
                return null; // Asset not found
            }
        }
        return $asset;
    }
}