<?php

namespace App\Http\Controllers;

use App\Http\Ultilities\BinanceAPI;
use App\Models\Binance;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class BinanceController extends Controller {

    public function getKeyByUserId() {
        $key = Binance::where('user_id', Auth::id())->first();
        return $key;
    }

    public function store(Request $request)
    {
        $data = $request->all();
        $data['user_id'] = Auth::id();

        try {
            $key = Binance::create($data);
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }
        
        return response()->json([
            'data' => $key,
        ], 201);
    }

    public function getAssetDetails() {
        $key = $this->getKeyByUserId();
        $api = new BinanceAPI($key->api_key, $key->secret_key, env('BINANCE_API_URL'));
        $assets = $api->getAccountInfo();
        $symbol = array_map(fn ($a) => $a['asset'] . 'USDT', $assets['balances']);
       
        $responses = Http::pool(function (Pool $pool) use ($symbol) {
            return array_map(fn ($s) => $pool->get(env('BINANCE_API_URL') . '/v3/ticker/24hr?symbol=' . $s), $symbol);
        });

        $data = [];
        foreach ($responses as $response) {
            $data[] = $response->json();
        }

        return response()->json([
            'assets' => $assets['balances'],
            'prices' => $data
        ]);
    }
}
