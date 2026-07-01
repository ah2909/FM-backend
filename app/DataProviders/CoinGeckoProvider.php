<?php

namespace App\DataProviders;

use Illuminate\Support\Facades\Http;

class CoinGeckoProvider
{
    private $baseUrl;
    private $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('app.coingecko_url');
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

    public function getMarketChart(string $id, int $days): array
    {
        $response = Http::timeout(8)->withHeaders([
            'accept' => 'application/json',
            'x-cg-demo-api-key' => $this->apiKey,
        ])->get("{$this->baseUrl}/coins/{$id}/market_chart", [
            'vs_currency' => 'usd',
            'days' => $days,
        ])->json();

        $closes = [];
        foreach ($response['prices'] ?? [] as [$ts, $price]) {
            $closes[date('Y-m-d', (int) ($ts / 1000))] = $price;
        }
        return $closes;
    }

}