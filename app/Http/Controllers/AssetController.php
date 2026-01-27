<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;

/**
 * @group Asset
 * 
 * APIs for managing global assets and user-specific asset lists.
 */
class AssetController extends Controller
{
    use ApiResponse, ErrorHandler;

    /**
     * Get user assets
     * 
     * Returns a list of assets associated with the authenticated user.
     * 
     * @authenticated
     * @response {
     *  "success": true,
     *  "message": null,
     *  "data": [
     *    {
     *      "id": 1,
     *      "name": "Bitcoin",
     *      "symbol": "BTC",
     *      "img_url": "https://assets.coingecko.com/coins/images/1/large/bitcoin.png",
     *      "created_at": "2024-01-27T14:00:00.000000Z",
     *      "updated_at": "2024-01-27T14:00:00.000000Z"
     *    }
     *  ]
     * }
     */
    public function index()
    {
        try {
            $user_id = request()->attributes->get('user')->id;
            $asset = User::find($user_id)->assets;
            return $this->successResponse($asset);
        } catch (\Throwable $th) {
            return $this->handleException($th, ['user_id' => $user_id]);
        }
    }

    /**
     * Create a new asset
     * 
     * Admin endpoint to create a global asset available for tracking.
     * 
     * @authenticated
     * @bodyParam name string required The name of the asset. Example: Ethereum
     * @bodyParam symbol string required The symbol of the asset. Example: ETH
     * @bodyParam img_url string required URL to the asset icon.
     * 
     * @response 201 {
     *  "success": true,
     *  "message": "Asset created successfully",
     *  "data": {
     *    "id": 2,
     *    "name": "Ethereum",
     *    "symbol": "ETH",
     *    "img_url": "https://assets.coingecko.com/coins/images/279/large/ethereum.png",
     *    "updated_at": "2024-01-27T14:05:00.000000Z",
     *    "created_at": "2024-01-27T14:05:00.000000Z"
     *  }
     * }
     */
    public function store(Request $request)
    {
        $data = $request->all();
        try {
            $asset = Asset::create($data);
        } catch (\Illuminate\Database\QueryException $err) {
            return $this->handleException($err, [
                'data' => $data
            ]);
        }
        return $this->successResponse($asset, 'Asset created successfully', 201);
    }
}
