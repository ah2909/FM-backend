<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Models\Transaction;
use App\Services\AssetService;
use App\Services\ExchangeService;
use App\Services\PortfolioService;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function getPortByUserID()
    {
        try {
            $user_id = request()->attributes->get('user')->id;
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

            $portfolio->assets = $portfolio->assets->map(function($asset) {
                if (isset($asset->pivot->amount)) {
                    $asset->amount = $asset->pivot->amount;
                    $asset->avg_price = $asset->pivot->avg_price;
                    unset($asset->pivot);
                }
                return $asset;
            });
            $priceData = $this->exchangeService->getPriceOfPort($portfolio->assets);
            $portfolio = $this->portfolioService->calculatePortfolioValue($portfolio, $priceData);
                    
            return $this->successResponse($portfolio);
        } catch (\Exception $e) {
            return $this->handleException($e, ['user_id' => $user_id]);
        }
    }

    public function getPortByID($id)
    {
        try {
            $portfolio = Portfolio::with(['assets', 'transactions'])->findOrFail($id);
            return $this->successResponse($portfolio);
        } catch (\Exception $e) {
            return $this->handleException($e, ['portfolio_id' => $id]);
        }
    }

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
            $portfolio = Portfolio::findOrFail($request['portfolio_id']);
            
            foreach ($validatedData['token'] as $token) {
                $tmp = $this->assetService->checkAssetExists($token['symbol']);
                if ($tmp->isEmpty()) {
                    //Search in coingecko and add to database
                }
                else {
                    $listTokenID[] = $tmp[0]['id'];
                    $listTokenAmount[] = $token['amount'];
                    $listSymbols[] = [
                        'name' => $token['symbol'] . '/USDT',
                        'exchange' => $token['exchange'],
                    ];
                }
            }
            $transactions = $this->exchangeService->getSymbolTransactions($listSymbols);
            $formattedTransactions = [];

            foreach ($listSymbols as $index => $symbol) {
                $assetID = $listTokenID[$index];
                $symbolTransactions = $transactions[$symbol['name']];
            
                $listAvgPrice[] = $this->portfolioService->calculateAvgPrice($symbolTransactions);

                foreach ($symbolTransactions as $transaction) {
                    $exchange_id = config('exchanges.' . strtolower($transaction['exchange']) . '_id');
                    $formattedTransactions[] = [
                        'exchange_id' => $exchange_id,
                        'portfolio_id' => $portfolio->id,
                        'asset_id' => $assetID,
                        'quantity' => $transaction['quantity'],
                        'price' => $transaction['price'],
                        'type' => $transaction['type'],
                        'transact_date' => $transaction['transact_date']
                    ];
                }
                
            }
            DB::transaction(function () use ($formattedTransactions, $listTokenID, $listTokenAmount, $listAvgPrice, $portfolio) {
                Transaction::upsert($formattedTransactions, [], ['type', 'price', 'quantity', 'transact_date']);
                $assetsToAttach = array_combine($listTokenID, array_map(fn($amount, $avgPrice) => 
                ['amount' => $amount, 'avg_price' => $avgPrice['average_price']], $listTokenAmount, $listAvgPrice));
                $portfolio->assets()->attach($assetsToAttach);
            });

            return $this->successResponse(null, 'Token added to portfolio successfully', 201);     
        } catch (\Exception $e) {
            return $this->handleException($e, ['request' => $request->all()]);
        }
    }

    public function addTokenToPortManual(Request $request) {
        /*
            $portfolio_id: int
            $token: {} -> token EX: {symbol: 'BTC', amount: 1}
        */
        try {
            $validatedData = $request->validate([
                'portfolio_id' => 'required',
                'token' => 'required'
            ]);
            $portfolio = Portfolio::findOrFail($request['portfolio_id']);
            $tokenID = $this->assetService->checkAssetExists($validatedData['token']['symbol']);
            if (!$tokenID) {
                //Search in coingecko and add to database
            }
           
            $portfolio->assets()->attach($tokenID, ['amount' => $validatedData['token']['amount']]);

            return $this->successResponse(null, 'Add token to portfolio successfully', 201);
        } catch (\Exception $e) {
            return $this->handleException($e, ['token' => $validatedData['token']]);
        }
    }

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
            $tokenID = $this->assetService->checkAssetExists($validatedData['token']);
            $portfolio->assets()->detach($tokenID);

            return $this->successResponse(null, 'Remove token from portfolio successfully', 200);
        } catch (\Exception $e) {
            return $this->handleException($e, ['token' => $validatedData['token']]);
        }
    }
}
