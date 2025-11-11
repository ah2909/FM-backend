<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeSymbol;
use App\Services\TradingService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;
use Illuminate\Support\Facades\Redis;

class TradingController extends Controller
{
    use ApiResponse, ErrorHandler;

    protected $tradingService;

    public function __construct(TradingService $tradingService)
    {
        $this->tradingService = $tradingService;
    }

    public function getFuturesAccountInfo()
    {
        try {
            $accountInfo = $this->tradingService->getFuturesAccountInfo();
            return $this->successResponse($accountInfo);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

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

    public function analyzeSymbol(Request $request)
{
    try {
        $symbol = $request->input('symbol');
        if (!$symbol) {
            return $this->errorResponse('Symbol is required', 400);
        }

        // Check if analysis is already cached
        if($cachedAnalysis = Redis::get("analysis:{$symbol}")) {
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
