<?php

namespace App\DataProviders;

use Illuminate\Support\Facades\Http;
use App\Support\RealtimeEvent;

class CexServiceProvider
{
    private $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('app.cex_service_url');
    }

    public function fetchTicker(array $symbols) {
        $response = Http::post($this->baseUrl . '/cex/ticker', [
            'symbols' => $symbols,
        ])->throw()->json();

        return $response['data'];
    }

    public function getPortfolioBalance(array $credentials, array $exchange) {
        $response = Http::post($this->baseUrl . '/cex/portfolio', [
            'credentials' => $credentials,
            'exchanges' => $exchange,
        ])->throw()->json();

        return $response['data'];
    }

    public function getSymbolTransactions(string $symbol, array $exchanges, array $credentials) {
        $response = Http::post($this->baseUrl . '/cex/transaction', [
            'symbol' => $symbol,
            'exchanges' => $exchanges,
            'credentials' => $credentials,
        ])->throw()->json();

        return $response['data'];
    }

    public function validateAPICredentials($exchangeName, $credentials) {
        $response = Http::post($this->baseUrl . '/cex/validate', [
            'exchange' => $exchangeName,
            'credentials' => $credentials,
        ])->throw()->json();
        return $response['success'];
    }

    public function emitEvent(string $event, array $data, int $userId) {
        RealtimeEvent::publish($event, $data, $userId);
    }
}