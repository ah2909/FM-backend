<?php

namespace App\DataProviders;

use Illuminate\Support\Facades\Http;

class YahooFinanceProvider
{
    private $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('app.yahoo_finance_url');
    }

    public function getDailyCloses(string $symbol, string $range): array
    {
        $response = Http::timeout(8)->withHeaders([
            'accept' => 'application/json',
            // Yahoo rejects non-browser user agents.
            'user-agent' => 'Mozilla/5.0',
        ])->get("{$this->baseUrl}/" . rawurlencode($symbol), [
            'range' => $range,
            'interval' => '1d',
        ])->json();

        $result = $response['chart']['result'][0] ?? null;
        if (!$result) {
            return [];
        }

        $timestamps = $result['timestamp'] ?? [];
        $closes = $result['indicators']['quote'][0]['close'] ?? [];

        $series = [];
        foreach ($timestamps as $i => $ts) {
            $close = $closes[$i] ?? null;
            if ($close !== null) {
                $series[date('Y-m-d', $ts)] = $close;
            }
        }
        return $series;
    }
}
