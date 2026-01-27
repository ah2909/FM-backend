<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeSymbol;
use App\Services\TradingService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;
use Illuminate\Support\Facades\Redis;

/**
 * @group Trading
 * 
 * APIs for futures trading, account information, and market analysis.
 */
class TradingController extends Controller
{
    use ApiResponse, ErrorHandler;

    protected $tradingService;

    public function __construct(TradingService $tradingService)
    {
        $this->tradingService = $tradingService;
    }

    /**
     * Get futures account info
     * 
     * Retrieves account balance, margin, and other metadata for futures trading.
     * 
     * @authenticated
     * @response {
     *  "success": true,
     *  "message": null,
     *  "data": {
     *    "totalWalletBalance": "1500.50",
     *    "totalUnrealizedProfit": "50.25",
     *    "totalMarginBalance": "1550.75",
     *    "availableBalance": "1200.00",
     *    "assets": [
     *      {
     *        "asset": "USDT",
     *        "walletBalance": "1500.50",
     *        "unrealizedProfit": "50.25",
     *        "marginBalance": "1550.75"
     *      }
     *    ]
     *  }
     * }
     */
    public function getFuturesAccountInfo()
    {
        try {
            $accountInfo = $this->tradingService->getFuturesAccountInfo();
            return $this->successResponse($accountInfo);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get futures ticker
     * 
     * Fetches current market price and 24h stats for a specific futures symbol.
     * 
     * @authenticated
     * @queryParam symbol string required The trading pair symbol. Example: BTCUSDT
     * 
     * @response {
     *  "success": true,
     *  "message": null,
     *  "data": {
     *    "symbol": "BTCUSDT",
     *    "lastPrice": "65123.40",
     *    "priceChange": "1234.50",
     *    "priceChangePercent": "1.92",
     *    "highPrice": "66000.00",
     *    "lowPrice": "63000.00",
     *    "volume": "12500.5"
     *  }
     * }
     */
    public function getFuturesTicker(Request $request)
    {
        try {
            $symbol = $request->input('symbol');
            if (!$symbol) {
                return $this->errorResponse('Symbol is required', 400);
            }

            $ticker = $this->tradingService->getFuturesTicker($symbol);
            return $this->successResponse($ticker);
        } catch (\Exception $e) {
            return $this->handleException($e, [
                'symbol' => $request->input('symbol')
            ]);
        }
    }

    /**
     * Get futures positions
     * 
     * Retrieves currently open futures positions for the user.
     * 
     * @authenticated
     * @queryParam symbol string The trading pair symbol to filter by. Example: BTCUSDT
     * 
     * @response {
     *  "success": true,
     *  "message": null,
     *  "data": {
     *    "positions": [
     *      {
     *        "symbol": "BTCUSDT",
     *        "positionAmt": "0.05",
     *        "entryPrice": "64000.00",
     *        "markPrice": "65123.40",
     *        "unRealizedProfit": "56.17",
     *        "liquidationPrice": "52000.00",
     *        "leverage": "10",
     *        "marginType": "cross"
     *      }
     *    ],
     *    "count": 1,
     *    "symbol": "BTCUSDT"
     *  }
     * }
     */
    public function getFuturesPositions(Request $request)
    {
        try {
            $symbol = $request->input('symbol');
            $positions = $this->tradingService->getFuturesPositions($symbol);
            return $this->successResponse([
                'positions' => $positions,
                'count' => count($positions),
                'symbol' => $symbol ?? 'all'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e, [
                'symbol' => $request->input('symbol')
            ]);
        }
    }

    /**
     * Analyze symbol
     * 
     * Starts an AI/algorithmic analysis of a specific symbol for trading signals.
     * 
     * @authenticated
     * @bodyParam symbol string required The trading pair symbol. Example: BTCUSDT
     * 
     * @response {
     *  "success": true,
     *  "message": "Analysis started",
     *  "data": {
     *    "job_id": "analysis_65b53e4b3c9a1",
     *    "status": "pending"
     *  }
     * }
     * @response 200 {
     *  "success": true,
     *  "message": null,
     *  "data": {
     *    "symbol": "BTCUSDT",
     *    "signal": "BUY",
     *    "confidence": "0.85",
     *    "indicators": {
     *      "rsi": "45",
     *      "macd": "bullish"
     *    },
     *    "timestamp": "2024-01-27T14:30:00Z"
     *  }
     * }
     */
    public function analyzeSymbol(Request $request)
    {
        try {
            $symbol = $request->input('symbol');
            if (!$symbol) {
                return $this->errorResponse('Symbol is required', 400);
            }

            // Check if analysis is already cached
            if ($cachedAnalysis = Redis::get("analysis:{$symbol}")) {
                return $this->successResponse(json_decode($cachedAnalysis, true));
            }

            // Generate unique job ID
            $jobId = uniqid('analysis_', true);
            $userId = request()->get('user')->id;

            // Dispatch the job
            AnalyzeSymbol::dispatch($this->tradingService, $symbol, $jobId, $userId);

            return $this->successResponse([
                'message' => 'Analysis started',
                'job_id' => $jobId,
                'status' => 'pending'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e, [
                'symbol' => $request->input('symbol')
            ]);
        }
    }
}
