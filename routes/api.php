<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\S3UploadController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\StripeWebhookController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/verify-email', [AuthController::class, 'verifyEmail']);

Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

// Email receipt webhook (no auth needed)
Route::post('/webhooks/email-receipts', [WebhookController::class, 'handleEmailReceipts']);

// Anonymous upload routes
Route::post('/anonymous/upload/presigned-url', [S3UploadController::class, 'getPresignedUrlAnonymous']);
Route::post('/anonymous/upload/confirm', [S3UploadController::class, 'confirmUploadAnonymous']);
Route::get('/anonymous/receipts/{sessionId}', [ReceiptController::class, 'getAnonymousReceipts']);
Route::get('/anonymous/receipts/{sessionId}/{receiptId}', [ReceiptController::class, 'getAnonymousReceipt']);

// Anonymous multi-upload routes
Route::prefix('anonymous/{sessionId}')->group(function () {
    Route::post('/receipts/multi-upload', [ReceiptController::class, 'multiUploadAnonymous']);
});

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
        // Multi-upload route for authenticated users
        Route::post('/receipts/multi-upload', [ReceiptController::class, 'multiUpload']);
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

    // Subscription management (verified users only)
    Route::prefix('subscription')->group(function () {
        Route::get('/current', [SubscriptionController::class, 'current']);
        Route::post('/create', [SubscriptionController::class, 'create']);
        Route::put('/update', [SubscriptionController::class, 'update']);
        Route::post('/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('/resume', [SubscriptionController::class, 'resume']);
        Route::post('/billing-portal', [SubscriptionController::class, 'billingPortal']);
        Route::get('/usage', [SubscriptionController::class, 'usage']);
    });
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'service' => 'expenzai-api'
    ]);
});