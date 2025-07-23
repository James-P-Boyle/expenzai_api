<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\S3UploadController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/verify-email', [AuthController::class, 'verifyEmail']);

// Anonymous upload routes
Route::post('/anonymous/upload/presigned-url', [S3UploadController::class, 'getPresignedUrlAnonymous']);
Route::post('/anonymous/upload/confirm', [S3UploadController::class, 'confirmUploadAnonymous']);
Route::get('/anonymous/receipts/{sessionId}', [ReceiptController::class, 'getAnonymousReceipts']);
Route::get('/anonymous/receipts/{sessionId}/{receiptId}', [ReceiptController::class, 'getAnonymousReceipt']);

// Protected routes that don't require verification
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/resend-verification', [AuthController::class, 'resendVerification']);

    // Upload routes (check limits but allow unverified users to see upload page)
    Route::middleware('upload.limits')->group(function () {
        Route::post('/upload/presigned-url', [S3UploadController::class, 'getPresignedUrl']);
        Route::post('/upload/confirm', [S3UploadController::class, 'confirmUpload']);
    });

    // Receipt viewing (allow unverified - they can see their uploads)
    Route::get('/receipts', [ReceiptController::class, 'index']);
    Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
});

// VERIFIED USERS ONLY - Full dashboard access
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    // Account management
    Route::post('/update-email', [AuthController::class, 'updateEmail']);
    Route::delete('/delete-account', [AuthController::class, 'deleteAccount']);
    Route::post('/request-data', [AuthController::class, 'requestData']);
    
    // Receipt management (editing/deleting)
    Route::put('/receipts/{receipt}', [ReceiptController::class, 'update']);
    Route::delete('/receipts/{receipt}', [ReceiptController::class, 'destroy']);
    
    // Categories (full dashboard features)
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/weekly', [CategoryController::class, 'weekly']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    
    // Items (for manual editing)
    Route::get('/items/{item}', [ItemController::class, 'show']);
    Route::put('/items/{item}', [ItemController::class, 'update']);
    
    // Expenses & Analytics (verified users only)
    Route::get('/expenses/weekly', [ExpenseController::class, 'weekly']);
    Route::get('/expenses/summary', [ExpenseController::class, 'summary']);
});
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'service' => 'expenzai-api'
    ]);
});
// DEBUG ROUTES 

// // Test email forwarding route
// Route::get('/test-email-forwarding', function () {
//     try {
//         Mail::raw('This is a test of email forwarding from ExpenzAI! If you receive this at jamesboyle9292@gmail.com, then forwarding is working perfectly.', function ($message) {
//             $message->to('contact@expenzai.app')  
//                    ->from('contact@expenzai.app', 'ExpenzAI') 
//                    ->subject('Email Forwarding Test - ExpenzAI');
//         });
        
//         return response()->json(['message' => 'Forwarding test email sent to contact@expenzai.app']);
//     } catch (\Exception $e) {
//         return response()->json([
//             'error' => 'Failed to send email',
//             'message' => $e->getMessage()
//         ], 500);
//     }
// });

// Route::get('/test-email', function () {
//     try {
//         Mail::raw('Test email from ExpenzAI backend via Resend!', function ($message) {
//             $message->to('jamesboyle9292@gmail.com')
//                    ->from('contact@expenzai.app', 'ExpenzAI')
//                    ->subject('Resend Test Email');
//         });
        
//         return response()->json(['message' => 'Email sent successfully!']);
//     } catch (\Exception $e) {
//         return response()->json([
//             'error' => 'Failed to send email',
//             'message' => $e->getMessage()
//         ], 500);
//     }
// });
// Route::get('/health', function () {
//     try {
//         DB::connection()->getPdo();
//         $tableCount = DB::select("SHOW TABLES");
//         return response()->json([
//             'status' => 'ok', 
//             'database' => 'connected',
//             'tables' => count($tableCount)
//         ]);
//     } catch (\Exception $e) {
//         return response()->json([
//             'status' => 'error', 
//             'message' => $e->getMessage()
//         ], 500);
//     }
// });
// Route::get('/debug-auth', function (Request $request) {
//     $token = $request->bearerToken();
    
//     try {
//         $user = null;
//         if ($token) {
//             $user = \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->tokenable;
//         }
        
//         return response()->json([
//             'has_bearer_token' => $token ? 'yes' : 'no',
//             'token_length' => $token ? strlen($token) : 0,
//             'authenticated_via_sanctum' => auth('sanctum')->check(),
//             'user_from_token' => $user ? ['id' => $user->id, 'email' => $user->email] : null,
//             'user_from_auth' => auth('sanctum')->user(),
//             'authorization_header' => $request->header('Authorization'),
//         ]);
//     } catch (\Exception $e) {
//         return response()->json(['error' => $e->getMessage()], 500);
//     }
// });

// Route::get('/debug-verification', function (Request $request) {
//     $email = $request->get('email');
//     $token = $request->get('token');
    
//     $user = \App\Models\User::where('email', $email)->first();
    
//     if (!$user) {
//         return response()->json(['error' => 'User not found', 'email' => $email]);
//     }
    
//     return response()->json([
//         'user_found' => true,
//         'user_email' => $user->email,
//         'stored_token' => $user->email_verification_token,
//         'provided_token' => $token,
//         'tokens_match' => $user->email_verification_token === $token,
//         'already_verified' => !is_null($user->email_verified_at),
//         'email_verified_at' => $user->email_verified_at,
//         'token_length_stored' => strlen($user->email_verification_token ?? ''),
//         'token_length_provided' => strlen($token ?? ''),
//     ]);
// });