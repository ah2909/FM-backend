<?php

namespace App\Services;

use App\Models\Exchange;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class BinanceService
{
    protected $exchange;

    public function __construct()
    {
        ini_set('memory_limit', '256M');
        $key = Exchange::where('user_id', Auth::id())->first();
        $apiKey = Crypt::decryptString($key['api_key']);
        $secretKey = Crypt::decryptString($key['secret_key']);

        $this->exchange = new \ccxt\binance([
            'apiKey' => $apiKey,
            'secret' => $secretKey,
        ]);
        $this->exchange->load_markets();
    }

    // Get single ticker price with caching
    public function getPrice(string $symbol, int $cacheSeconds = 5)
    {
        return Cache::remember("binance_price_{$symbol}", $cacheSeconds, function () use ($symbol) {
            try {
                return $this->exchange->fetch_ticker($symbol)['last'];
            } catch (\Exception $e) {
                Log::error("Binance price fetch failed: {$e->getMessage()}");
                return null;
            }
        });
    }

    // Get order book
    public function getOrderBook(string $symbol, int $limit = 10)
    {
        try {
            return $this->exchange->fetch_order_book($symbol, $limit);
        } catch (\Exception $e) {
            Log::error("Binance order book fetch failed: {$e->getMessage()}");
            return null;
        }
    }

    // Get account balance (authenticated)
    public function getBalances()
    {
        try {
            $stablecoins = ['USDT', 'USDC'];
            $tickers = $this->exchange->fetch_tickers();
        
            // Fetch account balance
            $balance = $this->exchange->fetch_balance();
            
            $assets = [];
            foreach ($balance['total'] as $currency => $amount) {
                $currency = strtoupper($currency);
                if ($amount <= 0) continue;

                // Handle stablecoins (price = 1)
                if (in_array($currency, $stablecoins)) {
                    $assets[] = [
                        'symbol' => $currency,
                        'price' => 1.0,
                        'amount' => $amount,
                        'value' => $amount * 1.0
                    ];
                    continue;
                }

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

                $assets[] = [
                    'symbol' => $currency,
                    'price' => $price,
                    'amount' => $amount,
                    'value' => $price !== null ? $price * $amount : null
                ];
            }

            return $assets;

        } catch (\Exception $e) {
            Log::error("Binance balance fetch failed: {$e->getMessage()}");
            return null;
        }
    }

    public function getPriceOfPort($assets) {
        
        try {
            $stablecoins = ['USDT', 'USDC'];
            $tickers = $this->exchange->fetch_tickers();
            
            $result = [];
            foreach ($assets as $a) {
                $a->symbol = strtoupper($a->symbol);
                if ($a->amount <= 0) continue;

                // Handle stablecoins (price = 1)
                if (in_array($a->symbol, $stablecoins)) {
                    $result[$a->symbol] = [
                        'price' => 1.0,
                        'value' => $a->amount * 1.0
                    ];
                    continue;
                }

                // Find price using preferred quotes
                $price = null;
                foreach ($stablecoins as $quote) {
                    $quote = strtoupper($quote);
                    $symbol = "$a->symbol/$quote";
                    if (isset($tickers[$symbol])) {
                        $price = $tickers[$symbol]['last'];
                        break;
                    }
                }

                $result[$a->symbol] = [
                    'price' => $price,
                    'value' => $price !== null ? $price * $a->amount : null
                ];
            }

            return $result;

        } catch (\Exception $e) {
            Log::error("Binance balance fetch failed: {$e->getMessage()}");
            return null;
        }

    }

    public function getPurchaseInfo($symbol)
    {
        try {
            $purchases = [
                'total_amount' => 0,
                'total_cost' => 0,
                'purchase_dates' => [],
            ];
            $trades = $this->exchange->fetch_my_trades($symbol);
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
            if($purchases['total_amount'] === 0) return null;
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
}