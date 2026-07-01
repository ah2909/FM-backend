<?php

namespace App\DataProviders;

use Illuminate\Support\Facades\Http;

class BinanceP2PProvider
{
    private $url;

    public function __construct()
    {
        $this->url = config('app.binance_p2p_url');
    }

    public function search(string $asset, string $fiat, string $tradeType, int $rows = 10): array
    {
        $response = Http::timeout(8)->withHeaders([
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->post($this->url, [
            'asset' => $asset,
            'fiat' => $fiat,
            'tradeType' => $tradeType,
            'page' => 1,
            'rows' => $rows,
        ])->json();

        $prices = [];
        foreach ($response['data'] ?? [] as $item) {
            if (isset($item['adv']['price'])) {
                $prices[] = (float) $item['adv']['price'];
            }
        }
        return $prices;
    }
}
