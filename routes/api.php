<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('transactions')->group(function () {
        Route::get('', [TransactionController::class, 'index']);
        Route::get('/{transaction_id}', [TransactionController::class, 'show']);
        Route::post('', [TransactionController::class, 'store']);
        Route::put('/{transaction_id}', [TransactionController::class, 'update']);
        Route::delete('/{transaction_id}', [TransactionController::class, 'destroy']);
    });

    Route::prefix('category')->group(function () {
        Route::get('', [CategoryController::class, 'index']);
        Route::get('/{category_id}', [CategoryController::class, 'show']);
        Route::post('', [CategoryController::class, 'store']);
        Route::put('/{category_id}', [CategoryController::class, 'update']);
        Route::delete('/{category_id}', [CategoryController::class, 'destroy']);
    });
});
