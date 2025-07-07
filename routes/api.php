<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ReceiptController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/test', function() {
    return response()->json([
        'message' => 'API is working!',
        'timestamp' => now(),
        'openai_configured' => !empty(config('services.openai.api_key'))
    ]);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    
    // Receipts - using apiResource for full CRUD
    Route::apiResource('receipts', ReceiptController::class);
    
    // Items (for manual editing)
    Route::put('/items/{item}', [ItemController::class, 'update']);
    
    // Expenses & Analytics
    Route::get('/expenses/weekly', [ExpenseController::class, 'weekly']);
    Route::get('/expenses/summary', [ExpenseController::class, 'summary']);

  
});
