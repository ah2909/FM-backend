<?php

namespace App\Services;

use App\Models\Exchange;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TradingService
{
    protected $credentials;

    // Only support binance trading for now
    public function __construct()
    {
        $userId = request()->get('user')->id;
        $key = Exchange::where('user_id', $userId)->where('cex_id', 1)->first();
        if ($key) {
            $apiKey = Crypt::decryptString($key['api_key']);
            $secretKey = Crypt::decryptString($key['secret_key']);
            $this->credentials = [
                'apiKey' => $apiKey,
                'secret' => $secretKey,
            ];
        }
    }

    public function getFuturesAccountInfo()
    {
        try {
            $response = Http::post(config('app.trading_service_url') . '/futures/account', [
                'credentials' => $this->credentials
            ])->throw()->json();

            return $response['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Failed to get futures account info: ' . $e->getMessage());
            return [];
        }
    }

    public function getFuturesTicker(string $symbol)
    {
        try {
            $response = Http::post(config('app.trading_service_url') . '/futures/ticker', [
                'credentials' => $this->credentials,
                'symbol' => $symbol
            ])->throw()->json();

            return $response['data'] ?? [];
        } catch (\Exception $e) {
            Log::error("Failed to get futures ticker for $symbol: " . $e->getMessage());
            return [];
        }
    }

    public function getFuturesPositions(?string $symbol = null)
    {
        try {
            $url = config('app.trading_service_url') . '/futures/positions';
            if ($symbol) {
                $url .= '?symbol=' . urlencode($symbol);
            }

            $response = Http::post($url, [
                'credentials' => $this->credentials
            ])->throw()->json();

            return $response['data']['positions'] ?? [];
        } catch (\Exception $e) {
            Log::error('Failed to get futures positions: ' . $e->getMessage());
            return [];
        }
    }

    public function analyzeSymbol(string $symbol)
    {
        try {
            $url = config('app.trading_service_url') . '/analyze';

            $response = Http::timeout(60)->post($url, [
                'symbol' => $symbol,
                'credentials' => $this->credentials
            ])->throw()->json();

            return $response ?? [];
        } catch (\Exception $e) {
            Log::error('Failed to analyze symbol: ' . $e->getMessage());
            return [];
        }
    }
}
