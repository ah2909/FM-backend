<?php

namespace App\Http\Controllers;

use App\Jobs\UpdatePortfolioAssets;
use App\Models\Exchange;
use App\Services\ExchangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;
use Illuminate\Support\Facades\Redis;

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
                'password' => 'nullable|string',
            ]);

            $cex_id = DB::select('select id from CEXs where name = ?', [$validatedData['cex_name']]);
            $user_id = $request->attributes->get('user')->id;
            Exchange::create([
                'cex_id' => $cex_id[0]->id,
                'api_key'=> Crypt::encryptString($validatedData['api_key']),
                'secret_key' => Crypt::encryptString($validatedData['secret_key']),
                'password' => isset($validatedData['password']) ? Crypt::encryptString($validatedData['password']) : null,
                'user_id' => $user_id
            ]);

            $exchange = [strtolower($validatedData['cex_name'])];
            $credentials[strtolower($validatedData['cex_name'])] = [
                'api_key' => $validatedData['api_key'],
                'api_secret' => $validatedData['secret_key'],
                'password' => isset($validatedData['password']) ? $validatedData['password'] : null,
            ];
            // DO LATER
            // UpdatePortfolioAssets::dispatch($exchange, $credentials, $user_id, $exchange[0]);
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
            $user_id = request()->attributes->get('user')->id; 
            if (Redis::exists("cex_info_{$user_id}")) {
                $data = json_decode(Redis::get("cex_info_{$user_id}"), true);
                return $this->successResponse($data, 'Get info from CEX successfully');
            }
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
            Redis::set('cex_info_'.$user_id, json_encode($data), 'EX', 15 * 60);
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
