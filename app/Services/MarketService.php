<?php

namespace App\Services;

use App\DataProviders\BinanceP2PProvider;
use App\DataProviders\CoinGeckoProvider;
use App\DataProviders\SsiProvider;
use App\DataProviders\YahooFinanceProvider;
use Illuminate\Support\Facades\Cache;

class MarketService
{
    private $p2p;
    private $coingecko;
    private $yahoo;
    private $ssi;

    public function __construct(
        BinanceP2PProvider $p2p,
        CoinGeckoProvider $coingecko,
        YahooFinanceProvider $yahoo,
        SsiProvider $ssi
    ) {
        $this->p2p = $p2p;
        $this->coingecko = $coingecko;
        $this->yahoo = $yahoo;
        $this->ssi = $ssi;
    }

    public function getP2PSpread(string $asset = 'USDT', string $fiat = 'VND'): array
    {
        return Cache::remember("market:p2p:{$asset}:{$fiat}", 60, function () use ($asset, $fiat) {
            $buy = $this->p2p->search($asset, $fiat, 'BUY');
            $sell = $this->p2p->search($asset, $fiat, 'SELL');

            $buyBest = $buy ? min($buy) : null;
            $sellBest = $sell ? max($sell) : null;

            return [
                'asset' => $asset,
                'fiat' => $fiat,
                'buy' => [
                    'best' => $buyBest,
                    'avg' => $buy ? array_sum($buy) / count($buy) : null,
                ],
                'sell' => [
                    'best' => $sellBest,
                    'avg' => $sell ? array_sum($sell) / count($sell) : null,
                ],
                'spread' => ($buyBest !== null && $sellBest !== null) ? $buyBest - $sellBest : null,
                'updated_at' => now()->toIso8601String(),
            ];
        });
    }

    public function getPerformanceComparison(string $range = '1m'): array
    {
        $range = in_array($range, ['1w', '1m', '1y'], true) ? $range : '1m';
        $cacheKey = "market:performance:{$range}";

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $days = ['1w' => 7, '1m' => 30, '1y' => 365][$range];
        $yahooRange = ['1w' => '5d', '1m' => '1mo', '1y' => '1y'][$range];

        $sources = [
            ['key' => 'bitcoin', 'label' => 'Bitcoin', 'closes' => fn () => $this->coingecko->getMarketChart('bitcoin', $days)],
            ['key' => 'gold', 'label' => 'Gold', 'closes' => fn () => $this->yahoo->getDailyCloses('GC=F', $yahooRange)],
            ['key' => 'sp500', 'label' => 'S&P 500', 'closes' => fn () => $this->yahoo->getDailyCloses('^GSPC', $yahooRange)],
            ['key' => 'vnindex', 'label' => 'VN-INDEX', 'closes' => fn () => $this->ssi->getDailyCloses('VNINDEX', $days)],
            ['key' => 'vn30', 'label' => 'VN30', 'closes' => fn () => $this->ssi->getDailyCloses('VN30', $days)],
        ];

        $series = [];
        $hasData = false;
        foreach ($sources as $source) {
            try {
                $closes = ($source['closes'])();
            } catch (\Throwable $e) {
                $closes = [];
            }
            $points = $this->rebase($closes);
            $hasData = $hasData || count($points) > 0;
            $series[] = [
                'key' => $source['key'],
                'label' => $source['label'],
                'points' => $points,
            ];
        }

        $result = ['range' => $range, 'series' => $series];
        if ($hasData) {
            Cache::put($cacheKey, $result, 6 * 3600);
        }
        return $result;
    }

    // Normalize a date=>close map to indexed-to-100 points so mixed-unit assets compare.
    private function rebase(array $closes): array
    {
        ksort($closes);
        $base = null;
        $points = [];
        foreach ($closes as $date => $value) {
            if ($value === null || $value == 0) {
                continue;
            }
            if ($base === null) {
                $base = $value;
            }
            $points[] = ['date' => $date, 'value' => round($value / $base * 100, 2)];
        }
        return $points;
    }
}
