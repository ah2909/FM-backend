<?php

namespace App\Http\Controllers;

use App\Models\Exchange;
use App\Services\BinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExchangeController extends Controller
{
    public function get_supported_cex() {
        try {
            $cex = DB::select('select * from CEXs');
            $cex_connected = DB::select('select cex_id from exchanges where user_id=?', [Auth::id()]);
            foreach ($cex as $ele) {
                foreach($cex_connected as $tmp) {
                    if($ele->id === $tmp->cex_id) $ele->is_connected = true;
                }
            }

            return response()->json([
                'error_code' => 0,
                'data' => $cex
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => $th->getMessage()
            ], 400);
        }
        
    }
    
    public function connect_cex(Request $request) {
        $validatedData = $request->validate([
            'cex_name' => 'required|string|max:50',
            'api_key' => 'required|string',
            'secret_key' => 'required|string',
        ]);

        if($validatedData) {
            try {
                $cex_id = DB::select('select id from CEXs where name = ?', [$validatedData['cex_name']]);
                $exchange = Exchange::create([
                    'cex_id' => $cex_id[0]->id,
                    'api_key'=> Crypt::encryptString($validatedData['api_key']),
                    'secret_key' => Crypt::encryptString($validatedData['secret_key']),
                    'user_id' => Auth::id()
                ]);
                return response()->json([
                    'message' => 'Connect successfully'
                ]);
            } catch (\Throwable $th) {
                Log::error($th->getMessage());
                return response()->json([
                    'message' => 'Error occurs when connecting to CEX'
                ]);
            }
        }
        else {
            return response()->json([
                'message' => 'Validation error'
            ], 400);
        }
    }

    public function get_info_from_cex() {
        try {
            $binance = new BinanceService();
            $balance = $binance->getBalances();

            $asset = DB::select('select symbol, img_url from assets');
            
            // Create an associative array from array2 for faster lookup 
            $data = [];
            $symbols = [];
            foreach ($balance as $item) {
                $data[$item['symbol']] = $item;
                $symbols[] = $item['symbol'] . '/USDT';
            }
            
            // Iterate through array1 and merge with matching elements from array2
            foreach ($asset as $a) {
                if (array_key_exists(strtoupper($a->symbol), $data)) {
                    $data[strtoupper($a->symbol)]['img_url'] = $a->img_url;
                }
            }
            
            $data = array_values($data);

            return response()->json([
                'error_code' => 0,
                'data' => $data,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => $th->getMessage()
            ], 400);
        }
        
    }
}
