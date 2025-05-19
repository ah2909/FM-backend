<?php

namespace App\Services;

use App\Models\Exchange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class ExchangeService
{
    protected $exchange;
    protected $keys;

    public function __construct()
    {
        ini_set('memory_limit', '512M');
        $userId = request()->get('user')->id;
        $keys = Exchange::where('user_id', $userId)->get();
        if ($keys) {
            foreach ($keys as $key) {
                $apiKey = Crypt::decryptString($key['api_key']);
                $secretKey = Crypt::decryptString($key['secret_key']);

                switch ($key->cex_id) {
                    case config('exchanges.binance_id'):
                        $this->exchange['binance'] = new \ccxt\binance([
                            'apiKey' => $apiKey,
                            'secret' => $secretKey,
                            'enableRateLimit' => false,
                        ]);
                        $this->keys['binance'] = $key;
                        $this->loadMarketsWithCache('binance');
                        break;

                    case config('exchanges.okx_id'):
                        $this->exchange['okx'] = new \ccxt\okx([
                            'apiKey' => $apiKey,
                            'secret' => $secretKey,
                            'password' => 'crypto-portfolioV1',
                            'enableRateLimit' => false,
                        ]);
                        $this->keys['okx'] = $key;
                        $this->loadMarketsWithCache('okx');
                        break;

                    case config('exchanges.bybit_id'):
                        $this->exchange['bybit'] = new \ccxt\bybit([
                            'apiKey' => $apiKey,
                            'secret' => $secretKey,
                            'enableRateLimit' => false,
                        ]);
                        $this->keys['bybit'] = $key;
                        $this->loadMarketsWithCache('bybit');
                        break;
                }
            }
        }
    }

    /**
     * Load markets with cache support
     * @param string $exchangeName
     * @return \React\Promise\PromiseInterface
     */
    protected function loadMarketsWithCache(string $exchangeName)
    {
        $cacheKey = "markets_{$exchangeName}_{$this->keys[$exchangeName]->user_id}";
        $cachedMarkets = Cache::get($cacheKey);

        if ($cachedMarkets) {
            $this->exchange[$exchangeName]->markets = json_decode($cachedMarkets, true);
            return true;
        }

        $markets = $this->exchange[$exchangeName]->load_markets();
        Cache::put($cacheKey, json_encode($markets), now()->addHours(1));

        return true;
    }

    public function getBalances()
    {
        $assets = [];
        $stablecoins = ['USDT', 'USDC'];
        $results = [];

        foreach ($this->exchange as $exchangeName => $exchangeInstance) {
            try {
                $balance = $exchangeInstance->fetch_balance(['omitZeroBalances' => true]);

                // Prepare symbols list for tickers
                $listSymbols = [];
                foreach ($balance['total'] as $currency => $amount) {
                    if ($amount <= 0) continue;
                    if (isset($exchangeInstance->markets[$currency . '/USDT'])) {
                        $listSymbols[] = strtoupper($currency) . '/USDT';
                    }
                }

                // Fetch tickers only for non-empty balances
                $tickers = [];
                if (!empty($listSymbols)) {
                    $tickers = $exchangeInstance->fetch_tickers($listSymbols);
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

            if (isset($this->exchange['binance'])) {
                $tickers = $this->exchange['binance']->fetch_tickers($listSymbols);
            } else if (isset($this->exchange['okx'])) {
                $tickers = $this->exchange['okx']->fetch_tickers($listSymbols);
            }
            else {
                throw new \Exception("No exchange instance available for fetching tickers.");
            }

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

    public function getPurchaseInfo($symbol, $exchangeName)
    {
        try {
            $purchases = [
                'total_amount' => 0,
                'total_cost' => 0,
                'purchase_dates' => [],
            ];
            $trades = $this->exchange[$exchangeName]->fetch_my_trades($symbol);
            // If no symbols are provided, fetch all trades

            foreach ($trades as $trade) {
                if ($trade['side'] === 'buy') { // Only consider buy trades
                    $price = $trade['price'];
                    $amount = $trade['amount'];
                    // $timestamp = $trade['timestamp']; // Purchase timestamp
                    // $date = date('Y-m-d H:i:s', $timestamp / 1000); // Convert to readable date

                    // Accumulate total amount and cost
                    $purchases['total_amount'] += $amount;
                    $purchases['total_cost'] += $amount * $price;
                    // $purchases['purchase_dates'][] = $date;
                }
            }

            // Calculate average purchase price for each asset
            $result = [];
            if ($purchases['total_amount'] === 0) return null;
            $averagePrice = $purchases['total_cost'] / $purchases['total_amount'];
            $result = [
                'average_purchase_price' => $averagePrice,
                'total_amount' => $purchases['total_amount'],
                'total_cost' => $purchases['total_cost'],
            ];


            return $result;
        } catch (\Exception $e) {
            Log::error("Binance transaction info fetch failed: {$e->getMessage()}");
            return null;
        }
    }

    public function getSymbolTransactions($symbols)
    {
        $allTrades = [];
        foreach ($symbols as $symbol) {
            try {
                // Format symbol if necessary (e.g., 'BTC/USDT') - CCXT usually handles this
                // $formattedSymbol = strtoupper(str_replace('-', '/', $symbol));
                foreach ($symbol['exchange'] as $exchange) {
                    $trades = $this->exchange[$exchange]->fetch_my_trades($symbol['name'], null); // (symbol, since, limit, params)
                    $formattedTrades = array_map(function ($trade) {
                        return [
                            'symbol' => $trade['symbol'],
                            'type' => $trade['side'],
                            'price' => $trade['price'],
                            'quantity' => $trade['amount'],
                            'cost' => $trade['cost'],
                            'transact_date' => date('Y-m-d H:i:s', $trade['timestamp'] / 1000),
                        ];
                    }, $trades);
                }
                
                // Add the fetched trades to our results array, keyed by symbol
                $allTrades[$symbol['name']] = $formattedTrades;
            } catch (\Exception $e) {
                Log::error("General Error fetching trades for {$symbol}: " . $e->getMessage());
            }
        }

        return $allTrades;
    }
}
