<?php

use App\Http\Controllers\AssetController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExchangeController;
use App\Http\Controllers\PortfolioController;
 

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('portfolio')->group(function () {
        Route::get('', [PortfolioController::class, 'getPortByUserID']);
        Route::post('', [PortfolioController::class, 'store']);
        Route::put('/{portfolio_id}', [PortfolioController::class, 'update']);
        Route::delete('/{portfolio_id}', [PortfolioController::class, 'destroy']);
        Route::post('/asset', [PortfolioController::class, 'addTokenToPort']);
        Route::post('/asset/remove', [PortfolioController::class, 'removeTokenfromPort']);
    });

    Route::prefix('asset')->group(function () {
        Route::get('', [AssetController::class, 'index']);
        Route::post('', [AssetController::class, 'store']);
    });

    Route::prefix('exchange')->group(function () {
        Route::get('/supported-cex', [ExchangeController::class, 'get_supported_cex']);
        Route::post('/connect', [ExchangeController::class, 'connect_cex']);
        Route::get('/info', [ExchangeController::class, 'get_info_from_cex']);
    });
});
