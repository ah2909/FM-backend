<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\Http;

class AssetService
{
    public function checkAssetExists($asset)
    {
        // Check if the asset exists in the database
        $symbol = strtolower($asset);
        $asset = Asset::where('symbol', $symbol)->get();
        if ($asset->isEmpty()) {
            $coingecko = Http::withHeaders([
                'accept' => 'application/json',
                'x-cg-demo-api-key' => config('app.coingecko_api_key'),
            ])->get(config('app.coingecko_url', 'https://api.coingecko.com/api/v3') . '/coins/markets', [
                'vs_currency' => 'usd',
                'symbols' => $symbol,
            ])->json();
            if (isset($coingecko[0]) && isset($coingecko[0]['id'])) {
                $data = Asset::create([
                    'symbol' => $symbol,
                    'name' => $coingecko[0]['name'],
                    'img_url' => $coingecko[0]['image'],
                ]);
                $asset[] = $data;
            } else {
                return null; // Asset not found
            }
        }
        return $asset;
    }
}