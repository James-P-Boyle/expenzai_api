<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\S3UploadController;

// Route::options('{any}', function () {
//     return response('', 200)
//         ->header('Access-Control-Allow-Origin', 'https://www.expenzai.app')
//         ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
//         ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept')
//         ->header('Access-Control-Allow-Credentials', 'true');
// })->where('any', '.*');

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
Route::get('/debug-auth', function (Request $request) {
    $token = $request->bearerToken();
    
    try {
        $user = null;
        if ($token) {
            $user = \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->tokenable;
        }
        
        return response()->json([
            'has_bearer_token' => $token ? 'yes' : 'no',
            'token_length' => $token ? strlen($token) : 0,
            'authenticated_via_sanctum' => auth('sanctum')->check(),
            'user_from_token' => $user ? ['id' => $user->id, 'email' => $user->email] : null,
            'user_from_auth' => auth('sanctum')->user(),
            'authorization_header' => $request->header('Authorization'),
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
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
    
    // Receipts 
    Route::apiResource('receipts', ReceiptController::class);
    
    // Items (for manual editing)
    Route::put('/items/{item}', [ItemController::class, 'update']);
    
    // Expenses & Analytics
    Route::get('/expenses/weekly', [ExpenseController::class, 'weekly']);
    Route::get('/expenses/summary', [ExpenseController::class, 'summary']);

    // Image upload

    Route::post('/upload/presigned-url', [S3UploadController::class, 'getPresignedUrl']);
    Route::post('/upload/confirm', [S3UploadController::class, 'confirmUpload']);
  
});

// Replace your debug-confirm-upload route with this enhanced version
// Replace your debug-confirm-upload route with this enhanced version

Route::post('/debug-confirm-upload-v2', function (Request $request) {
    \Illuminate\Support\Facades\Log::info('DEBUG V2: Starting confirm upload', [
        'user_id' => $request->user()?->id,
        'request_data' => $request->all(),
    ]);

    try {
        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*.file_key' => 'required|string',
            'files.*.original_name' => 'required|string',
            'files.*.file_size' => 'required|integer',
        ]);

        \Illuminate\Support\Facades\Log::info('DEBUG V2: Validation passed');

        $receipts = [];
        $files = $request->input('files'); // Fixed: use input() instead of files
        $fileCount = count($files);
        
        \Illuminate\Support\Facades\Log::info('DEBUG V2: About to process files', [
            'file_count' => $fileCount,
            'files' => $files
        ]);
        
        foreach ($files as $index => $fileData) {
            \Illuminate\Support\Facades\Log::info('DEBUG V2: Starting file processing', [
                'index' => $index,
                'file_data' => $fileData,
            ]);

            try {
                // Skip S3 check for now, just create the receipt
                \Illuminate\Support\Facades\Log::info('DEBUG V2: About to create receipt record');
                
                $receiptData = [
                    'user_id' => $request->user()->id,
                    'image_path' => $fileData['file_key'],
                    'original_filename' => $fileData['original_name'],
                    'file_size' => $fileData['file_size'],
                    'storage_disk' => 's3',
                    'status' => 'processing',
                    'week_of' => \Carbon\Carbon::now()->startOfWeek(),
                ];

                \Illuminate\Support\Facades\Log::info('DEBUG V2: Receipt data prepared', $receiptData);

                // Test database connection
                \Illuminate\Support\Facades\Log::info('DEBUG V2: Testing DB connection');
                $dbTest = DB::select('SELECT 1 as test');
                \Illuminate\Support\Facades\Log::info('DEBUG V2: DB connection OK', ['result' => $dbTest]);

                // Try to create receipt
                \Illuminate\Support\Facades\Log::info('DEBUG V2: Creating receipt in database');
                $receipt = \App\Models\Receipt::create($receiptData);
                \Illuminate\Support\Facades\Log::info('DEBUG V2: Receipt created successfully', [
                    'receipt_id' => $receipt->id,
                    'receipt_data' => $receipt->toArray(),
                ]);

                $receipts[] = [
                    'id' => $receipt->id,
                    'status' => 'processing',
                    'original_filename' => $fileData['original_name'],
                ];

                \Illuminate\Support\Facades\Log::info('DEBUG V2: File processing completed', [
                    'index' => $index,
                    'receipt_id' => $receipt->id,
                ]);

            } catch (\Exception $fileError) {
                \Illuminate\Support\Facades\Log::error('DEBUG V2: File processing failed', [
                    'index' => $index,
                    'error' => $fileError->getMessage(),
                    'trace' => $fileError->getTraceAsString(),
                ]);
                
                return response()->json([
                    'message' => 'DEBUG V2: File processing failed - ' . $fileError->getMessage(),
                    'error' => 'File processing failed',
                    'debug' => true,
                    'file_index' => $index,
                ], 500);
            }
        }

        \Illuminate\Support\Facades\Log::info('DEBUG V2: All files processed successfully', [
            'total_receipts' => count($receipts),
            'receipts' => $receipts,
        ]);

        return response()->json([
            'message' => 'DEBUG V2: Success! ' . count($receipts) . ' receipts created',
            'receipts' => $receipts,
            'total_uploaded' => count($receipts),
            'debug' => true,
        ], 201);

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('DEBUG V2: General failure', [
            'user_id' => $request->user()?->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'message' => 'DEBUG V2: Failed - ' . $e->getMessage(),
            'error' => 'Processing failed',
            'debug' => true,
        ], 500);
    }
})->middleware('auth:sanctum');