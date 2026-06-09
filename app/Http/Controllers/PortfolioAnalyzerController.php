<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzePortfolio;
use App\Jobs\AnalyzeTokens;
use App\Models\Portfolio;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class PortfolioAnalyzerController extends Controller
{
    use ApiResponse, ErrorHandler;

    public function analyze(Request $request)
    {
        try {
            $user_id = request()->get('user')->id;

            // Fetch the user's primary portfolio
            $portfolio = Portfolio::where('user_id', $user_id)->first();

            if (!$portfolio) {
                return $this->errorResponse('Portfolio not found for user', 404);
            }

            $portfolioId = $portfolio->id;
            $cacheKey    = "portfolio_analysis_{$user_id}_{$portfolioId}";

            if ($cached = Redis::get($cacheKey)) {
                return $this->successResponse(json_decode($cached, true));
            }

            $jobId = uniqid('port_analysis_', true);

            AnalyzePortfolio::dispatch(
                (int) $user_id,
                (int) $portfolioId,
                $jobId
            );

            return $this->successResponse([
                'message' => 'Portfolio analysis started',
                'job_id'  => $jobId,
                'status'  => 'pending'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e, [
                'user_id' => $user_id ?? null,
            ]);
        }
    }

    public function analyzeToken(Request $request)
    {
        try {
            $user_id = request()->get('user')->id;

            $symbol = strtoupper(trim((string) $request->input('symbol', '')));
            if ($symbol === '') {
                return $this->errorResponse('Symbol is required', 422);
            }

            // Per-symbol cache, shared across all users (token outlooks are user-independent).
            $cacheKey = "token_research:{$symbol}";
            if ($cached = Redis::get($cacheKey)) {
                return $this->successResponse(json_decode($cached, true));
            }

            $jobId = uniqid('token_research_', true);

            AnalyzeTokens::dispatch(
                (int) $user_id,
                $symbol,
                $jobId
            );

            return $this->successResponse([
                'message' => 'Token research started',
                'job_id'  => $jobId,
                'status'  => 'pending'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e, [
                'user_id' => $user_id ?? null,
            ]);
        }
    }
}
