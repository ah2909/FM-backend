<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Services\AssetService;
use App\Services\BinanceService;
use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PortfolioController extends Controller
{
    protected $assetService;
    protected $binanceService;
    protected $portfolioService;

    public function __construct(AssetService $assetService, BinanceService $binanceService, PortfolioService $portfolioService)
    {
        $this->assetService = $assetService;
        $this->binanceService = $binanceService;
        $this->portfolioService = $portfolioService;
    }

    // Display a listing of the resource.
    public function getPortByUserID()
    {
        $portfolios = Portfolio::with(['assets', 'transactions'])->where('user_id', Auth::id())->get();
        // manipulate default data
        foreach ($portfolios as $portfolio) {
            $portfolio->assets = $portfolio->assets->map(function($asset) {
                if (isset($asset->pivot->amount)) {
                    $asset->amount = $asset->pivot->amount;
                    unset($asset->pivot);
                }
                return $asset;
            });
            $priceData = $this->binanceService->getPriceOfPort($portfolio->assets);
            $portfolio = $this->portfolioService->calculatePortfolioValue($portfolio, $priceData);
        }

        return response()->json($portfolios);
    }

    public function getPortByID($id)
    {
        $portfolio = Portfolio::with(['user', 'assets', 'transactions'])->findOrFail($id);
        return response()->json($portfolio);
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
            ]);

            $validatedData['user_id'] = Auth::id();
            $portfolio = Portfolio::create($validatedData);
            return response()->json($portfolio, 201);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create portfolio'], 400);
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
            return response()->json($portfolio);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update portfolio'], 400);
        }
    }

    public function destroy($portfolio_id)
    {
        $portfolio = Portfolio::findOrFail($portfolio_id);
        $portfolio->delete();
        return response()->json(['message' => 'Delete successfully'], 204);
    }

    public function addTokenToPort(Request $request) {
        /*
            $portfolio_id: int
            $token: [] -> token EX: [{symbol: 'BTC', amount: 1}]
        */
        try {
            $validatedData = $request->validate([
                'portfolio_id' => 'required',
                'token' => 'required'
            ]);
            $portfolio = Portfolio::findOrFail($request['portfolio_id']);
            
            $listTokenID = [];
            $listTokenAmount = [];
            foreach ($validatedData['token'] as $token) {
                $tmp = $this->assetService->checkAssetExists($token['symbol']);
                if ($tmp->isEmpty()) {
                    //Search in coingecko and add to database
                }
                else {
                    $listTokenID[] = $tmp[0]['id'];
                    $listTokenAmount[] = $token['amount'];
                }
            }

            $assetsToAttach = array_combine($listTokenID, array_map(fn($amount) => ['amount' => $amount], $listTokenAmount));
            $portfolio->assets()->attach($assetsToAttach);

            return response()->json([
                'message' => 'Add token to portfolio successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $e->errors(),
            ], 422);
        }catch (\Exception $e) {
            return response()->json(['error' => 'Failed to add token'], 400);
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

            return response()->json([
                'message' => 'Remove token from portfolio successfully',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $e->errors(),
            ], 422);
        }catch (\Exception $e) {
            return response()->json(['error' => 'Failed to remove token'], 400);
        }
    }
}
