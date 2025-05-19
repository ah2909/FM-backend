<?php

namespace App\Http\Controllers;

use App\Models\Exchange;
use App\Services\ExchangeService;
use App\Services\OkxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;

class ExchangeController extends Controller
{
    use ApiResponse, ErrorHandler;
    protected $exchangeService;

    public function __construct(ExchangeService $exchangeService)
    {
        $this->exchangeService = $exchangeService;
    }

    public function get_supported_cex(Request $request) {
        try {
            $cex = DB::select('select * from CEXs');
            $user_id = $request->attributes->get('user')->id;
            $cex_connected = DB::select('select cex_id from exchanges where user_id=?', [$user_id]);
            foreach ($cex as $ele) {
                foreach($cex_connected as $tmp) {
                    if($ele->id === $tmp->cex_id) $ele->is_connected = true;
                }
            }
            return $this->successResponse($cex);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => $th->getMessage()
            ], 400);
            return $this->handleException($th, ['user_id' => $user_id]);
        }
        
    }
    
    public function connect_cex(Request $request) {
        try {
            $validatedData = $request->validate([
                'cex_name' => 'required|string|max:50',
                'api_key' => 'required|string',
                'secret_key' => 'required|string',
            ]);

            $cex_id = DB::select('select id from CEXs where name = ?', [$validatedData['cex_name']]);
            $user_id = $request->attributes->get('user')->id;
            $exchange = Exchange::create([
                'cex_id' => $cex_id[0]->id,
                'api_key'=> Crypt::encryptString($validatedData['api_key']),
                'secret_key' => Crypt::encryptString($validatedData['secret_key']),
                'user_id' => $user_id
            ]);
            return response()->json([
                'message' => 'Connect successfully'
            ]);
            return $this->successResponse([], 'Connect successfully', 201);
        }
        catch (\Throwable $th) {
            return $this->handleException($th, [
                'cex_name' => $validatedData['cex_name'],
                'user_id' => $user_id,
            ]);
        } 
    }

    public function get_info_from_cex() {
        try {     
            $balance = $this->exchangeService->getBalances();
            $asset = DB::select('select symbol, img_url from assets');
             
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
            return $this->successResponse($data, 'Get info from CEX successfully');
        } catch (\Throwable $th) {
            return $this->handleException($th, []);
        }
    }

    public function get_history_transaction(Request $request) {
        try {
            $validatedData = $request->validate([
                'symbols' => 'required|array',
            ]);
            $trans = $this->exchangeService->getSymbolTransactions($validatedData['symbols'], 'binance');
            if(empty($trans)) {
                return $this->successResponse([], 'No transaction history found for the provided symbols.');
            }
            return $this->successResponse($trans, 'Get transaction history successfully');
        }
        catch (\Throwable $th) {
            return $this->handleException($th, [
                'symbols' => $request->input('symbols'),
            ]);
        }
    }
}
