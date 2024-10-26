<?php

namespace App\Http\Controllers;

use App\Http\Ultilities\BinanceAPI;
use App\Models\Binance;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BinanceController extends Controller {

    public function getKeyByUserId($id = null) {
        $user_id = $id ?? Auth::id();
        $key = Binance::where('user_id', $user_id)->first();
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

    public function getAssetDetails($id = null) {
        $user_id = $id ?? Auth::id();
        $key = $this->getKeyByUserId($user_id);
        if(!$key) return null;
        $api = new BinanceAPI($key->api_key, $key->secret_key, env('BINANCE_API_URL'));
        $assets = $api->getAccountInfo();
        $symbol = array_map(fn ($a) => $a['asset'] . 'USDT', $assets['balances']);
       
        $responses = Http::pool(function (Pool $pool) use ($symbol) {
            return array_map(fn ($s) => $pool->get(env('BINANCE_API_URL') . '/v3/ticker/24hr?symbol=' . $s), $symbol);
        });

        $history = DB::table('cron_data')->select([
            'asset_balance'
        ])->orderBy('created_at', 'desc')->limit(10)->get();

        $data = [];
        foreach ($responses as $response) {
            $data[] = $response->json();
        }

        $totalValue = 0;
        for ($i = 0; $i < count($data); $i++) {
            if(array_key_exists('symbol', $data[$i]))
                $totalValue += $data[$i]['askPrice'] * $assets['balances'][$i]['free'];
        }
    
        return response()->json([
            'assets' => $assets['balances'],
            'prices' => $data,
            'total'  => ceil($totalValue),
            'history'=> $history
        ]);
    }
}
