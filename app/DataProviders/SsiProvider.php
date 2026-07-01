<?php

namespace App\DataProviders;

use Illuminate\Support\Facades\Http;

class SsiProvider
{
    private $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('app.ssi_url');
    }

    public function getDailyCloses(string $symbol, int $days): array
    {
        $to = time();
        $from = $to - $days * 86400;

        $response = Http::timeout(8)->withHeaders([
            'accept' => 'application/json',
            'user-agent' => 'Mozilla/5.0',
        ])->get("{$this->baseUrl}/statistics/charts/history", [
            'resolution' => '1D',
            'symbol' => $symbol,
            'from' => $from,
            'to' => $to,
        ])->json();

        $timestamps = $response['data']['t'] ?? [];
        $closes = $response['data']['c'] ?? [];

        $series = [];
        foreach ($timestamps as $i => $ts) {
            if (isset($closes[$i])) {
                $series[date('Y-m-d', $ts)] = $closes[$i];
            }
        }
        return $series;
    }
}
