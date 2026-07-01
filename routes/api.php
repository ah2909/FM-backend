<?php

use App\Http\Controllers\AssetController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExchangeController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\PortfolioAnalyzerController;
use App\Http\Controllers\PortfolioController;
use App\Http\Middleware\JWTAuth;

Route::middleware([JWTAuth::class])->group(function () {

    Route::prefix('portfolio')->group(function () {
        Route::get('', [PortfolioController::class, 'getPortByUserID']);
        Route::post('', [PortfolioController::class, 'store']);
        Route::put('/{portfolio_id}', [PortfolioController::class, 'update']);
        Route::delete('/{portfolio_id}', [PortfolioController::class, 'destroy']);
        Route::post('/asset', [PortfolioController::class, 'addTokenToPort']);
        // Route::post('/asset/add-manual', [PortfolioController::class, 'addTokenToPortManual']);
        Route::post('/asset/remove', [PortfolioController::class, 'removeTokenfromPort']);
        Route::post('/sync-transactions', [PortfolioController::class, 'syncPortfolioTransactions']);
        Route::get('/balance', [PortfolioController::class, 'getBalanceByUserID']);
        Route::get('/recent-activity', [PortfolioController::class, 'getRecentActivity']);
        Route::patch('/recent-activity/read-all', [PortfolioController::class, 'markAllNotificationsRead']);
        Route::patch('/recent-activity/{id}/read', [PortfolioController::class, 'markNotificationRead']);
        Route::post('/import-transactions', [PortfolioController::class, 'importPortfolioTransactionsCSV']);
        Route::get('/analyze', [PortfolioAnalyzerController::class, 'analyze']);
        Route::post('/analyze-token', [PortfolioAnalyzerController::class, 'analyzeToken']);
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

    Route::prefix('market')->group(function () {
        Route::get('/p2p', [MarketController::class, 'p2p']);
        Route::get('/performance', [MarketController::class, 'performance']);
    });

    Route::prefix('user')->group(function () {
        Route::get('/info', function () {
            return response()->json(['user' => request()->attributes->get('user')]);
        });
    });
});
