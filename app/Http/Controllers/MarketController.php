<?php

namespace App\Http\Controllers;

use App\Services\MarketService;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    use ApiResponse, ErrorHandler;

    protected MarketService $marketService;

    public function __construct(MarketService $marketService)
    {
        $this->marketService = $marketService;
    }

    public function p2p(Request $request)
    {
        try {
            $asset = $request->query('asset', 'USDT');
            $fiat = $request->query('fiat', 'VND');
            return $this->successResponse($this->marketService->getP2PSpread($asset, $fiat));
        } catch (\Throwable $th) {
            return $this->handleException($th, ['asset' => $asset ?? null, 'fiat' => $fiat ?? null]);
        }
    }

    public function performance(Request $request)
    {
        try {
            $range = $request->query('range', '1m');
            return $this->successResponse($this->marketService->getPerformanceComparison($range));
        } catch (\Throwable $th) {
            return $this->handleException($th, ['range' => $range ?? null]);
        }
    }
}
