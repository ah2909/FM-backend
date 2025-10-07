<?php

namespace App\Services;

use App\DataProviders\CexServiceProvider;
use App\Models\Exchange;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeService
{
    protected $exchange;
    protected $credentials;
    protected $cexService;

    public function __construct(CexServiceProvider $cexService)
    {
        $userId = request()->get('user')->id;
        $keys = Exchange::where('user_id', $userId)->get();
        if ($keys) {
            foreach ($keys as $key) {
                $apiKey = Crypt::decryptString($key['api_key']);
                $secretKey = Crypt::decryptString($key['secret_key']);
                $password = isset($key['password']) ? Crypt::decryptString($key['password']) : null;

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
                            'password' => $password,
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
        $this->cexService = $cexService;
    }

    public function getBalances()
    {
        $assets = [];
        $stablecoins = ['USDT', 'USDC'];
        $results = [];

        $response = $this->cexService->getPortfolioBalance($this->credentials, $this->exchange);

        foreach ($response as $exchangeName => $balance) {
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
                    $response = $this->cexService->fetchTicker($listSymbols);
                    $tickers = $response ?? [];
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

    public function getSymbolTransactions($symbols)
    {
        $allTrades = [];
        $filteredSymbols = array_values(array_filter($symbols, function ($s) {
            return !in_array('okx', $s['exchange']);
        }));
        $trades = Http::pool(function (Pool $pool) use ($filteredSymbols) {
            return array_map(fn ($s) => $pool->post(config('app.cex_service_url') . '/cex/transaction', [
                'symbol' => $s['name'],
                'exchanges' => $s['exchange'],
                'credentials' => $this->credentials,
            ]), $filteredSymbols);
        });

        // Handle OKX trades separately due to limitations
        $okxSymbols = array_values(array_filter($symbols, function ($s) {
            return in_array('okx', $s['exchange']);
        }));
        foreach ($okxSymbols as $symbol) {
            try {
                $response = $this->cexService->getSymbolTransactions($symbol['name'], $symbol['exchange'], $this->credentials);

                if(empty($response)) {
                    $allTrades[$symbol['name']] = [];
                    continue;
                }
                $formattedTrades = array_map(function ($trade) {
                    return [
                        'symbol' => $trade['symbol'],
                        'type' => $trade['side'],
                        'price' => $trade['price'],
                        'quantity' => $trade['amount'],
                        'cost' => $trade['cost'],
                        'transact_date' => date('Y-m-d H:i:s', $trade['timestamp'] / 1000),
                        'exchange' => $trade['exchange'],
                    ];
                }, $response);
                
                // Add the fetched trades to our results array, keyed by symbol
                $allTrades[$symbol['name']] = $formattedTrades;
            } catch (\Exception $e) {
                Log::error("Error fetching trades for {$symbol['name']} on OKX: " . $e->getMessage());
            }  
        }

        foreach ($filteredSymbols as $index => $symbol) {
            try {
                $formattedTrades = array_map(function ($trade) {
                    return [
                        'symbol' => $trade['symbol'],
                        'type' => $trade['side'],
                        'price' => $trade['price'],
                        'quantity' => $trade['amount'],
                        'cost' => $trade['cost'],
                        'transact_date' => date('Y-m-d H:i:s', $trade['timestamp'] / 1000),
                        'exchange' => $trade['exchange'],
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

    public function syncTransactions($symbols, $since, $userId) {
        $response = Http::post(config('app.cex_service_url') . '/cex/sync-transactions', [
            'credentials' => $this->credentials,
            'exchanges' => $this->exchange,
            'symbols' => $symbols,
            'since' => $since,
            'user_id' => $userId,
        ])->throw()->json();
        return $response['data'] ?? [];
    }
}
