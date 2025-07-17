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

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $tableCount = DB::select("SHOW TABLES");
        return response()->json([
            'status' => 'ok', 
            'database' => 'connected',
            'tables' => count($tableCount)
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error', 
            'message' => $e->getMessage()
        ], 500);
    }
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/update-email', [AuthController::class, 'updateEmail']);
    Route::delete('/delete-account', [AuthController::class, 'deleteAccount']);
    Route::post('/request-data', [AuthController::class, 'requestData']);
    
    // Receipts - using apiResource for full CRUD
    Route::apiResource('receipts', ReceiptController::class);
    
    // Items (for manual editing)
    Route::put('/items/{item}', [ItemController::class, 'update']);
    
    // Expenses & Analytics
    Route::get('/expenses/weekly', [ExpenseController::class, 'weekly']);
    Route::get('/expenses/summary', [ExpenseController::class, 'summary']);

  
});
