<?php

namespace App\Http\Controllers;

use App\Jobs\AddTokenToPort;
use App\Jobs\ImportCSVTransactions;
use App\Jobs\SyncTransactions;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Services\AssetService;
use App\Services\ExchangeService;
use App\Services\PortfolioService;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;


class PortfolioController extends Controller
{
    use ApiResponse, ErrorHandler;

    protected AssetService $assetService;
    protected ExchangeService $exchangeService;
    protected PortfolioService $portfolioService;

    public function __construct(
        AssetService $assetService,
        ExchangeService $exchangeService,
        PortfolioService $portfolioService
    ) {
        $this->assetService = $assetService;
        $this->exchangeService = $exchangeService;
        $this->portfolioService = $portfolioService;
    }

    public function calculatePortfolioBalance($portfolio) {
        $portfolio->assets = $portfolio->assets->map(function($asset) {
            if (isset($asset->pivot->amount)) {
                $asset->amount = $asset->pivot->amount;
                $asset->avg_price = $asset->pivot->avg_price;
                unset($asset->pivot);
            }
            return $asset;
        });
        $priceData = $this->portfolioService->getPriceOfPort($portfolio->assets);
        $portfolio = $this->portfolioService->calculatePortfolioValue($portfolio, $priceData);
        return $portfolio;
    }

    public function getPortByUserID()
    {
        try {
            $user_id = request()->attributes->get('user')->id;
            if (Redis::exists("portfolio_user_{$user_id}")) {
                $portfolio = json_decode(Redis::get("portfolio_user_{$user_id}"), true);
                return $this->successResponse($portfolio);
            }
            $portfolio = Portfolio::with([
                'assets',
                'transactions'
            ])->where('user_id', $user_id)->first();
            
            if (!$portfolio) {
                return $this->successResponse([]);
            }
            if($portfolio->assets->isEmpty()) {
                return $this->successResponse($portfolio);
            }

            $portfolio = $this->calculatePortfolioBalance($portfolio);
            Redis::set("portfolio_user_{$user_id}", json_encode($portfolio), 'EX', 120);

            return $this->successResponse($portfolio);
        } catch (\Exception $e) {
            return $this->handleException($e, ['user_id' => $user_id]);
        }
    }

    // public function getPortByID($id)
    // {
    //     try {
    //         $portfolio = Portfolio::with(['assets', 'transactions'])->findOrFail($id);
    //         return $this->successResponse($portfolio);
    //     } catch (\Exception $e) {
    //         return $this->handleException($e, ['portfolio_id' => $id]);
    //     }
    // }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
            ]);

            $validatedData['user_id'] = $request->attributes->get('user')->id;
            $portfolio = Portfolio::create($validatedData);
            
            return $this->successResponse($portfolio, 'Portfolio created successfully', 201);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function update(Request $request, $portfolio_id)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'description' => 'sometimes|nullable|string',
            ]);
            $portfolio = Portfolio::findOrFail($portfolio_id);
            $portfolio->update($validatedData);
            Redis::del("portfolio_user_{$portfolio->user_id}");
            return $this->successResponse($portfolio, 'Portfolio updated successfully');
        }
        catch (\Exception $e) {
            return $this->handleException($e, ['portfolio_id' => $portfolio_id]);
        }
    }

    public function destroy($portfolio_id)
    {
        try {
            $portfolio = Portfolio::findOrFail($portfolio_id);
            $portfolio->delete();
            return $this->successResponse(null, 'Portfolio deleted successfully', 204);
        } catch (\Exception $e) {
            return $this->handleException($e, ['portfolio_id' => $portfolio_id]);
        }
    }

    public function addTokenToPort(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'portfolio_id' => 'required',
                'token' => 'required|array'
            ]);
            $user_id = $request->attributes->get('user')->id;
            Redis::del("portfolio_user_{$user_id}");
            AddTokenToPort::dispatch($validatedData, $user_id, $this->exchangeService);
            return $this->successResponse(null, 'Token added to portfolio successfully', 201);     
        } catch (\Exception $e) {
            return $this->handleException($e, ['request' => $request->all()]);
        }
    }

    // public function addTokenToPortManual(Request $request) {
    //     /*
    //         $portfolio_id: int
    //         $token: {} -> token EX: {symbol: 'BTC', amount: 1}
    //     */
    //     try {
    //         $validatedData = $request->validate([
    //             'portfolio_id' => 'required',
    //             'token' => 'required'
    //         ]);
    //         $portfolio = Portfolio::findOrFail($request['portfolio_id']);
    //         $tokenID = $this->assetService->checkAssetExists($validatedData['token']['symbol']);
    //         if (!$tokenID) {
    //             throw new \Exception("Token {$validatedData['token']['symbol']} not found.");
    //         }
           
    //         $portfolio->assets()->attach($tokenID, ['amount' => $validatedData['token']['amount']]);

    //         return $this->successResponse(null, 'Add token to portfolio successfully', 201);
    //     } catch (\Exception $e) {
    //         return $this->handleException($e, ['token' => $validatedData['token']]);
    //     }
    // }

    public function removeTokenfromPort(Request $request) {
        /*
            $portfolio_id: int
            $token: 'BTC
        */
        try {
            $validatedData = $request->validate([
                'portfolio_id' => 'required',
                'token' => 'required'
            ]);
            $portfolio = Portfolio::findOrFail($request['portfolio_id']);
            $token = $this->assetService->checkAssetExists($validatedData['token']);
            $portfolio->assets()->detach($token);
            
            $portfolio->transactions()->where('asset_id', $token->id)->delete();
            $user_id = $request->attributes->get('user')->id;
            Redis::del("portfolio_user_{$user_id}");
            PortfolioService::storeRecentActivity($user_id, 'Remove asset', $token->id);

            return $this->successResponse(null, 'Remove token from portfolio successfully', 200);
        } catch (\Exception $e) {
            return $this->handleException($e, ['token' => $validatedData['token']]);
        }
    }

    public function syncPortfolioTransactions(Request $request) {
        /*
            $portfolio_id: int
        */
        try {
            $validatedData = $request->validate([
                'portfolio_id' => 'required|integer',
            ]);
            $portfolio = Portfolio::findOrFail($validatedData['portfolio_id']);
            $user_id = $request->attributes->get('user')->id;
            $jobId = "{$user_id}_{$portfolio->id}";
            
            if(Redis::exists("sync_transactions_{$jobId}")) {
                return $this->successResponse(['status' => 'success'], 'Portfolio transactions are already synced', 200);
            }

            SyncTransactions::dispatch($this->exchangeService, $jobId, $portfolio, $user_id);
            return $this->successResponse(['status' => 'syncing', 'job_id' => $jobId], 'Portfolio transactions are syncing', 200);
        } catch (\Exception $e) {
            return $this->handleException($e, ['portfolio_id' => $validatedData['portfolio_id']]);
        }
    }

    public function getBalanceByUserID()
    {
        try {
            $user_id = request()->attributes->get('user')->id;
            $balance = DB::table('portfolio_balance')->select(['balance', 'date'])
                ->where('user_id', $user_id)
                ->orderBy('date', 'desc')
                ->get();
            
            if (!$balance) {
                return $this->successResponse([]);
            }              
            return $this->successResponse($balance);
        } catch (\Exception $e) {
            return $this->handleException($e, ['user_id' => $user_id]);
        }
    }

    public function getRecentActivity()
    {
        try {
            $user_id = request()->attributes->get('user')->id;
            $recentActivities = DB::table('recent_activity')
                ->join('assets', 'recent_activity.asset_id', '=', 'assets.id')
                ->select('recent_activity.*', 'assets.symbol', 'assets.img_url', 'assets.name')
                ->where('user_id', $user_id)
                ->orderBy('recent_activity.created_at', 'desc')
                ->get();
            
            return $this->successResponse($recentActivities);
        } catch (\Exception $e) {
            return $this->handleException($e, ['user_id' => $user_id]);
        }
    }

    public function importPortfolioTransactionsCSV(Request $request)
    {
        $userId = $request->attributes->get('user')->id;
        $exchange = "";
        try {
            $validatedData = $request->validate([
                'file' => 'required|file|mimes:csv',
                'exchange' => 'required|string',
            ]);

            $exchange = $validatedData['exchange'];
            $exchangeId = config('exchanges.' . strtolower($validatedData['exchange']) . '_id');
            $file = $request->file('file');
            $file = $file->store('', ['disk' => 'public']);

            ImportCSVTransactions::dispatch($file, $userId, $exchangeId);
            return $this->successResponse(null, 'Portfolio transactions imported successfully', 201);
        } catch (\Exception $e) {
            return $this->handleException($e, ['user_id' => $userId, 'exchange' => $exchange]);
        }
    }
}
