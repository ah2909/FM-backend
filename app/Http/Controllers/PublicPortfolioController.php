<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Services\PortfolioService;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;
use Illuminate\Support\Facades\Redis;

// Standalone controller: PortfolioController's constructor pulls user-bound
// services (ExchangeService) that cannot resolve on unauthenticated routes.
class PublicPortfolioController extends Controller
{
    use ApiResponse, ErrorHandler;

    protected PortfolioService $portfolioService;

    public function __construct(PortfolioService $portfolioService)
    {
        $this->portfolioService = $portfolioService;
    }

    public function show($token)
    {
        try {
            if (Redis::exists("public_portfolio_{$token}")) {
                return $this->successResponse(json_decode(Redis::get("public_portfolio_{$token}"), true));
            }

            $portfolio = Portfolio::with('assets')->where('share_token', $token)->first();
            if (!$portfolio) {
                return $this->errorResponse('Portfolio not found', 404);
            }

            $showAmounts = (bool) $portfolio->share_amounts;
            $payload = [
                'name' => $portfolio->name,
                'share_amounts' => $showAmounts,
                'updated_at' => now()->toIso8601String(),
                'totalValue' => null,
                'assets' => [],
            ];

            if ($portfolio->assets->isNotEmpty()) {
                $portfolio->assets = $portfolio->assets->map(function ($asset) {
                    if (isset($asset->pivot->amount)) {
                        $asset->amount = $asset->pivot->amount;
                        $asset->avg_price = $asset->pivot->avg_price;
                        unset($asset->pivot);
                    }
                    return $asset;
                });
                $priceData = $this->portfolioService->getPriceOfPort($portfolio->assets);
                $portfolio = $this->portfolioService->calculatePortfolioValue($portfolio, $priceData);
                $totalValue = $portfolio->totalValue ?: 0;

                // Owner-opted public view: only expose amounts when share_amounts is on
                $payload['totalValue'] = $showAmounts ? $totalValue : null;
                $payload['assets'] = $portfolio->assets->map(function ($asset) use ($totalValue, $showAmounts) {
                    return [
                        'symbol' => $asset->symbol,
                        'name' => $asset->name,
                        'img_url' => $asset->img_url,
                        'price' => $asset->price,
                        'percentChange' => $asset->percentChange,
                        'allocation' => $totalValue > 0 ? round(($asset->value / $totalValue) * 100, 1) : 0,
                        'value' => $showAmounts ? $asset->value : null,
                        'amount' => $showAmounts ? $asset->amount : null,
                    ];
                })->sortByDesc('allocation')->values();
            }

            Redis::set("public_portfolio_{$token}", json_encode($payload), 'EX', 120);
            return $this->successResponse($payload);
        } catch (\Exception $e) {
            return $this->handleException($e, ['share_token' => $token]);
        }
    }
}
