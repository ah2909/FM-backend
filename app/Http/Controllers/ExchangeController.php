<?php

namespace App\Http\Controllers;

use App\DataProviders\CexServiceProvider;
use App\Jobs\UpdatePortfolioAssets;
use App\Models\Exchange;
use App\Services\ExchangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;
use Illuminate\Support\Facades\Redis;

/**
 * @group Exchange
 * 
 * APIs for connecting to Centralized Exchanges (CEX) and retrieving account info/balances.
 */
class ExchangeController extends Controller
{
    use ApiResponse, ErrorHandler;
    protected $exchangeService;
    protected $cexService;

    public function __construct(ExchangeService $exchangeService, CexServiceProvider $cexService)
    {
        $this->exchangeService = $exchangeService;
        $this->cexService = $cexService;
    }

    /**
     * Get supported CEXs
     * 
     * Retrieves a list of all supported Centralized Exchanges and indicates if the user has already connected them.
     * 
     * @authenticated
     * @response {
     *  "success": true,
     *  "message": null,
     *  "data": [
     *    {
     *      "id": 1,
     *      "name": "binance",
     *      "img_url": "https://example.com/binance.png",
     *      "is_connected": true
     *    },
     *    {
     *      "id": 2,
     *      "name": "okx",
     *      "img_url": "https://example.com/okx.png",
     *      "is_connected": false
     *    }
     *  ]
     * }
     */
    public function get_supported_cex(Request $request)
    {
        try {
            $cexs = DB::select('select * from CEXs');
            $user_id = $request->attributes->get('user')->id;
            $cex_connected = DB::select('select cex_id from exchanges where user_id=?', [$user_id]);
            foreach ($cex_connected as $tmp) {
                foreach ($cexs as $cex) {
                    if ($cex->id === $tmp->cex_id) {
                        $cex->is_connected = true;
                        break;
                    }
                }
            }
            return $this->successResponse($cexs);
        } catch (\Throwable $th) {
            return $this->handleException($th, ['user_id' => $user_id]);
        }
    }

    /**
     * Connect to a CEX
     * 
     * Connects the user's account to a centralized exchange using API credentials.
     * 
     * @authenticated
     * @bodyParam cex_name string required The name of the exchange (e.g., binance, okx). Example: binance
     * @bodyParam api_key string required The API key provided by the exchange.
     * @bodyParam secret_key string required The Secret key provided by the exchange.
     * @bodyParam password string Optional passphrase for exchanges that require it (like OKX or Kucoin).
     * 
     * @response 201 {
     *  "success": true,
     *  "message": "Connect successfully",
     *  "data": []
     * }
     * @response 400 {
     *  "success": false,
     *  "error": "Invalid API credentials"
     * }
     */
    public function connect_cex(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'cex_name' => 'required|string|max:50',
                'api_key' => 'required|string',
                'secret_key' => 'required|string',
                'password' => 'nullable|string',
            ]);

            $cex_name = strtolower($validatedData['cex_name']);
            $cex_id = DB::select('select id from CEXs where name = ?', [$cex_name]);
            $user_id = $request->attributes->get('user')->id;
            $credentials[$cex_name] = [
                'api_key' => $validatedData['api_key'],
                'api_secret' => $validatedData['secret_key'],
                'password' => isset($validatedData['password']) ? $validatedData['password'] : null,
            ];

            UpdatePortfolioAssets::dispatch($cex_name, $credentials, $user_id, $cex_name);
            $isValid = $this->cexService->validateAPICredentials($cex_name, $credentials[$cex_name]);
            if (!$isValid) {
                return $this->errorResponse('Invalid API credentials', 400);
            }
            Exchange::create([
                'cex_id' => $cex_id[0]->id,
                'api_key' => Crypt::encryptString($validatedData['api_key']),
                'secret_key' => Crypt::encryptString($validatedData['secret_key']),
                'password' => isset($validatedData['password']) ? Crypt::encryptString($validatedData['password']) : null,
                'user_id' => $user_id
            ]);
            return $this->successResponse([], 'Connect successfully', 201);
        } catch (\Throwable $th) {
            return $this->handleException($th, [
                'cex_name' => $cex_name,
                'user_id' => $user_id,
            ]);
        }
    }

    /**
     * Get CEX account info
     * 
     * Fetches real-time balance and account information from all connected exchanges.
     * 
     * @authenticated
     * @response {
     *  "success": true,
     *  "message": "Get info from CEX successfully",
     *  "data": [
     *    {
     *      "symbol": "BTC",
     *      "free": "0.5",
     *      "locked": "0.0",
     *      "total": "0.5",
     *      "img_url": "https://assets.coingecko.com/coins/images/1/large/bitcoin.png"
     *    },
     *    {
     *      "symbol": "ETH",
     *      "free": "10.0",
     *      "locked": "2.0",
     *      "total": "12.0",
     *      "img_url": "https://assets.coingecko.com/coins/images/279/large/ethereum.png"
     *    }
     *  ]
     * }
     */
    public function get_info_from_cex()
    {
        try {
            $user_id = request()->attributes->get('user')->id;
            if (Redis::exists("cex_info_{$user_id}")) {
                $data = json_decode(Redis::get("cex_info_{$user_id}"), true);
                return $this->successResponse($data, 'Get info from CEX successfully');
            }
            $balance = $this->exchangeService->getBalances();
            $assets = DB::select('select symbol, img_url from assets');

            $data = [];
            $symbols = [];
            foreach ($balance as $item) {
                $data[$item['symbol']] = $item;
                $symbols[] = $item['symbol'] . '/USDT';
            }
            // Iterate through array1 and merge with matching elements from array2
            foreach ($assets as $asset) {
                if (array_key_exists(strtoupper($asset->symbol), $data)) {
                    $data[strtoupper($asset->symbol)]['img_url'] = $asset->img_url;
                }
            }
            $data = array_values($data);
            Redis::set("cex_info_$user_id", json_encode($data), 'EX', 15 * 60);
            return $this->successResponse($data, 'Get info from CEX successfully');
        } catch (\Throwable $th) {
            return $this->handleException($th, []);
        }
    }

    /**
     * Get CEX transaction history
     * 
     * Retrieves historical trade data for specific symbols from connected exchanges.
     * 
     * @authenticated
     * @bodyParam symbols string[] required List of symbols to fetch history for. Example: ["BTC/USDT", "ETH/USDT"]
     * 
     * @response {
     *  "success": true,
     *  "message": "Get transaction history successfully",
     *  "data": [
     *    {
     *      "symbol": "BTC/USDT",
     *      "id": "123456",
     *      "order": "78910",
     *      "type": "limit",
     *      "side": "buy",
     *      "price": "45000.00",
     *      "amount": "0.01",
     *      "cost": "450.00",
     *      "timestamp": 1706364000000,
     *      "datetime": "2024-01-27T14:00:00.000Z"
     *    }
     *  ]
     * }
     */
    public function get_history_transaction(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'symbols' => 'required|array',
            ]);
            $trans = $this->exchangeService->getSymbolTransactions($validatedData['symbols'], 'binance');
            if (empty($trans)) {
                return $this->successResponse([], 'No transaction history found for the provided symbols.');
            }
            return $this->successResponse($trans, 'Get transaction history successfully');
        } catch (\Throwable $th) {
            return $this->handleException($th, [
                'symbols' => $request->input('symbols'),
            ]);
        }
    }
}
