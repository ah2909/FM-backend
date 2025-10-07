<?php

namespace App\DataProviders;

use Illuminate\Support\Facades\Http;

class CoinGeckoProvider
{
    private $baseUrl;
    private $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('app.coingecko_url', 'https://api.coingecko.com/api/v3');
        $this->apiKey = config('app.coingecko_api_key', '');
    }

    public function getCurrentPrice(string $symbol): ?float
    {
       $response = Http::withHeaders([
            'accept' => 'application/json',
            'x-cg-demo-api-key' => $this->apiKey,
        ])->get( "{$this->baseUrl}/simple/price", [
            'vs_currencies' => 'usd',
            'symbols' => $symbol,
        ])->json();

        return $response[strtolower($symbol)]['usd'] ?? null;
    }

    public function getAssetInfo(string $symbol): ?array
    {
        $response = Http::withHeaders([
            'accept' => 'application/json',
            'x-cg-demo-api-key' => $this->apiKey,
        ])->get( "{$this->baseUrl}/coins/markets", [
            'vs_currency' => 'usd',
            'symbols' => strtolower($symbol),
        ])->json();

        return $response[0] ?? null;
    }

}