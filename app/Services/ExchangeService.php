<?php

namespace App\Services;

use App\Models\Exchange;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeService
{
    protected $exchange;
    protected $credentials;

    public function __construct()
    {
        $userId = request()->get('user')->id;
        $keys = Exchange::where('user_id', $userId)->get();
        if ($keys) {
            foreach ($keys as $key) {
                $apiKey = Crypt::decryptString($key['api_key']);
                $secretKey = Crypt::decryptString($key['secret_key']);

                switch ($key->cex_id) {
                    case config('exchanges.binance_id'):
                        $this->exchange[] = 'binance';
                        $this->credentials['binance'] = [
                            'api_key' => $apiKey,
                            'api_secret' => $secretKey,
                        ];
                        break;

                    case config('exchanges.okx_id'):
                        $this->exchange[] = 'okx';
                        $this->credentials['okx'] = [
                            'api_key' => $apiKey,
                            'api_secret' => $secretKey,
                            'password' => 'crypto-portfolioV1',
                        ];
                        break;

                    case config('exchanges.bybit_id'):
                        $this->exchange[] = 'bybit';
                        $this->credentials['bybit'] = [
                            'api_key' => $apiKey,
                            'api_secret' => $secretKey,                           
                        ];
                        break;
                }
            }
        }
    }

    public function getBalances()
    {
        $assets = [];
        $stablecoins = ['USDT', 'USDC'];
        $results = [];

        $response = Http::post(env('CEX_SERVICE_URL') . '/cex/portfolio', [
            'credentials' => $this->credentials,
            'exchanges' => $this->exchange,
        ])->throw()->json();

        foreach ($response['data'] as $exchangeName => $balance) {
            try {
                // Prepare symbols list for tickers
                $listSymbols = [];
                foreach ($balance['total'] as $currency => $amount) {
                    if ($amount <= 0) continue;
                    $listSymbols[] = strtoupper($currency) . '/USDT'; 
                }

                // Fetch tickers only for non-empty balances
                $tickers = [];
                if (!empty($listSymbols)) {
                    $response = Http::post(env('CEX_SERVICE_URL') . '/cex/ticker', [
                        'symbols' => $listSymbols
                    ])->throw()->json();
                    $tickers = $response['data'] ?? [];
                }

                $results[$exchangeName] = [
                    'balance' => $balance,
                    'tickers' => $tickers
                ];
            } catch (\Exception $e) {
                Log::error("$exchangeName balance fetch failed: {$e->getMessage()}");
                continue;
            }
        }

        // Process results
        foreach ($results as $exchangeName => $result) {
            try {
                $balance = $result['balance'];
                $tickers = $result['tickers'] ?? [];

                foreach ($balance['total'] as $currency => $amount) {
                    $currency = strtoupper($currency);
                    if ($amount <= 0) continue;

                    // Handle stablecoins (price = 1)
                    if (in_array($currency, $stablecoins)) {
                        $price = 1;
                    } else {
                        // Find price using preferred quotes
                        $price = null;
                        foreach ($stablecoins as $quote) {
                            $quote = strtoupper($quote);
                            $symbol = "$currency/$quote";
                            if (isset($tickers[$symbol])) {
                                $price = $tickers[$symbol]['last'];
                                break;
                            }
                        }
                    }

                    if (isset($assets[$currency])) {
                        $assets[$currency]['amount'] += $amount;
                        $assets[$currency]['value'] += $price !== null ? $price * $amount : 0;
                        $assets[$currency]['exchanges'][] = $exchangeName;
                    } else {
                        $assets[$currency] = [
                            'symbol' => $currency,
                            'price' => $price,
                            'amount' => $amount,
                            'value' => $price !== null ? $price * $amount : 0,
                            'exchanges' => [$exchangeName]
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::error("$exchangeName result processing failed: {$e->getMessage()}");
            }
        }

        return $assets;
    }

    //Default exchange is binance
    public function getPriceOfPort($assets)
    {
        try {
            $listSymbols = array_map(function ($a) {
                return strtoupper($a['symbol']) . '/USDT';
            }, $assets->toArray());

            $response = Http::post(env('CEX_SERVICE_URL') . '/cex/ticker', [
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

    public function getSymbolTransactions($symbols)
    {
        $allTrades = [];
        $trades = Http::pool(function (Pool $pool) use ($symbols) {
            return array_map(fn ($s) => $pool->post(env('CEX_SERVICE_URL') . '/cex/transaction', [
                'symbol' => $s['name'],
                'exchanges' => $s['exchange'] ?? 'binance', // Default to binance if not specified
                'credentials' => $this->credentials,
            ]), $symbols);
        });

        foreach ($symbols as $index => $symbol) {
            try {
                $formattedTrades = array_map(function ($trade) {
                    return [
                        'symbol' => $trade['symbol'],
                        'type' => $trade['side'],
                        'price' => $trade['price'],
                        'quantity' => $trade['amount'],
                        'cost' => $trade['cost'],
                        'transact_date' => date('Y-m-d H:i:s', $trade['timestamp'] / 1000),
                        'exchange' => $trade['exchange'] ?? 'binance', // Default to binance if not specified
                    ];
                }, $trades[$index]->json()['data'] ?? []);
                
                // Add the fetched trades to our results array, keyed by symbol
                $allTrades[$symbol['name']] = $formattedTrades;
            } catch (\Exception $e) {
                Log::error("General Error fetching trades for {$symbol}: " . $e->getMessage());
            }
        }

        return $allTrades;
    }

    public function syncTransactions($symbols, $since) {
        $response = Http::post(env('CEX_SERVICE_URL') . '/cex/sync-transactions', [
            'credentials' => $this->credentials,
            'exchanges' => $this->exchange,
            'symbols' => $symbols,
            'since' => $since,
        ])->throw()->json();
        return $response['data'] ?? [];
    }
}
