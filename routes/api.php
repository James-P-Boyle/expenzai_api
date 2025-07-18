<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ReceiptController;

Route::options('{any}', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', 'https://www.expenzai.app')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept')
        ->header('Access-Control-Allow-Credentials', 'true');
})->where('any', '.*');

Route::post('/debug-upload', function (Request $request) {
    return response()->json([
        'has_file' => $request->hasFile('image'),
        'all_files' => $request->allFiles(),
        'content_length' => $request->header('content-length'),
        'content_type' => $request->header('content-type'),
        'request_size' => strlen($request->getContent()),
        'php_limits' => [
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ],
        'server_info' => [
            'request_method' => $request->method(),
            'content_length_from_server' => $_SERVER['CONTENT_LENGTH'] ?? 'not set',
            'http_content_length' => $_SERVER['HTTP_CONTENT_LENGTH'] ?? 'not set',
        ],
        'raw_input_info' => [
            'input_size' => strlen(file_get_contents('php://input')),
        ]
    ]);
});

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
