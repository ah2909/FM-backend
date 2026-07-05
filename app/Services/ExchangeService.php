<?php

namespace App\Services;

use App\DataProviders\CexServiceProvider;
use App\Models\Exchange;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ExchangeService
{
    protected $exchange = [];
    protected $credentials = [];
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

    public function getBalances(bool $forceRefresh = false)
    {
        $userId = request()->get('user')->id;
        $stablecoins = ['USDT', 'USDC'];

        // Raw scan cached briefly: /exchange/info and queued jobs often fire within the same window
        if (!$forceRefresh && Redis::exists("cex_raw_{$userId}")) {
            $response = json_decode(Redis::get("cex_raw_{$userId}"), true);
        } else {
            $response = $this->cexService->getPortfolioBalance($this->credentials, $this->exchange);
            Redis::set("cex_raw_{$userId}", json_encode($response), 'EX', 120);
        }

        $assets = [];
        $statuses = [];
        $reconciliation = [];

        foreach ($response as $exchangeName => $result) {
            if (!is_array($result)) continue;

            if (isset($result['status_error'])) {
                $statuses[$exchangeName] = ['exchange' => 'error'];
                Log::error("$exchangeName wallet scan failed: {$result['status_error']}");
                continue;
            }

            // Legacy Node shape (pre multi-wallet rollout): flat total map is the spot wallet
            $wallets = $result['wallets'] ?? ['spot' => ['balances' => $result['total'] ?? [], 'status' => 'ok']];

            foreach ($wallets as $walletType => $wallet) {
                $status = $wallet['status'] ?? 'error';
                $statuses[$exchangeName][$walletType] = $status;
                // Failed wallets are excluded, never counted as zero
                if ($status !== 'ok') continue;

                foreach ($wallet['balances'] ?? [] as $currency => $amount) {
                    if (!is_numeric($amount) || $amount == 0) continue;
                    $currency = strtoupper($currency);
                    $assets[$currency]['wallets'][] = [
                        'exchange' => $exchangeName,
                        'wallet_type' => $walletType,
                        'amount' => $amount,
                    ];
                }
            }

            if (!empty($result['reconciliation'])) {
                $reconciliation[$exchangeName] = $result['reconciliation'];
            }
        }

        // One deduped ticker call across all exchanges and wallets
        $listSymbols = [];
        foreach (array_keys($assets) as $currency) {
            if (!in_array($currency, $stablecoins)) {
                $listSymbols[] = "$currency/USDT";
            }
        }
        $tickers = [];
        if (!empty($listSymbols)) {
            try {
                $tickers = $this->cexService->fetchTicker($listSymbols) ?? [];
            } catch (\Exception $e) {
                Log::error("Ticker fetch failed: {$e->getMessage()}");
            }
        }

        foreach ($assets as $currency => $entry) {
            $amount = array_sum(array_column($entry['wallets'], 'amount'));
            if ($amount <= 0) {
                // Net negative/zero (borrowed): nothing importable, keep out of the list
                unset($assets[$currency]);
                continue;
            }
            $price = in_array($currency, $stablecoins) ? 1 : ($tickers["$currency/USDT"]['last'] ?? null);
            $assets[$currency] = [
                'symbol' => $currency,
                'price' => $price,
                'amount' => $amount,
                'value' => $price !== null ? $price * $amount : 0,
                'exchanges' => array_values(array_unique(array_column($entry['wallets'], 'exchange'))),
                'wallets' => $entry['wallets'],
            ];
        }

        return [
            'assets' => $assets,
            'meta' => [
                'statuses' => $statuses,
                'reconciliation' => $reconciliation,
            ],
        ];
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

    public function syncDepositsWithdrawals(array $currencies, $since, $userId): array
    {
        try {
            $response = Http::post(config('app.cex_service_url') . '/cex/sync-deposits-withdrawals', [
                'credentials' => $this->credentials,
                'exchanges'   => $this->exchange,
                'currencies'  => $currencies,
                'since'       => $since,
                'user_id'     => $userId,
            ])->throw()->json();
            return $response['data'] ?? [];
        } catch (\Exception $e) {
            Log::error("Failed to fetch deposits/withdrawals: " . $e->getMessage());
            return [];
        }
    }
}
