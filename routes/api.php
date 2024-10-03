<?php

use App\Http\Controllers\AssetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BinanceController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;
use App\Models\Category;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('transactions')->group(function () {
        Route::post('/statistics', [TransactionController::class, 'transactionStatisticsInRange']);
        Route::get('', [TransactionController::class, 'index']);
        Route::get('/{transaction_id}', [TransactionController::class, 'show']);
        Route::post('', [TransactionController::class, 'store']);
        Route::put('/{transaction_id}', [TransactionController::class, 'update']);
        Route::delete('/{transaction_id}', [TransactionController::class, 'destroy']);
    });

    Route::prefix('category')->group(function () {
        Route::get('/{typ}', [CategoryController::class, 'showCategoryByType']);
        Route::get('', [CategoryController::class, 'index']);
        Route::get('/{category_id}', [CategoryController::class, 'show']);
        Route::post('', [CategoryController::class, 'store']);
        Route::put('/{category_id}', [CategoryController::class, 'update']);
        Route::delete('/{category_id}', [CategoryController::class, 'destroy']);
    });

    Route::prefix('asset')->group(function () {
        Route::get('', [AssetController::class, 'index']);
        Route::post('', [AssetController::class, 'store']);
    });

    Route::prefix('binance-key')->group(function () {
        Route::get('', [BinanceController::class, 'getKeyByUserId']);
        Route::get('/assets', [BinanceController::class, 'getAssetDetails']);
        Route::post('', [BinanceController::class, 'store']);
    });
});
