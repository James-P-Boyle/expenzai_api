<?php

use App\Models\User;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
// Route::get('/simple-debug', function () {
//     try {
//         $totalReceipts = Receipt::count();
//         $recentReceipts = Receipt::latest()->take(5)->get([
//             'id', 'user_id', 'status', 'original_filename', 'storage_disk', 'created_at'
//         ]);
        
//         $userCount = User::count();
//         $firstUser = User::first(['id', 'email']);
        
//         return response()->json([
//             'total_receipts' => $totalReceipts,
//             'total_users' => $userCount,
//             'first_user' => $firstUser,
//             'recent_receipts' => $recentReceipts,
//             'receipts_by_status' => [
//                 'processing' => Receipt::where('status', 'processing')->count(),
//                 'completed' => Receipt::where('status', 'completed')->count(),
//                 'failed' => Receipt::where('status', 'failed')->count(),
//             ]
//         ]);
//     } catch (\Exception $e) {
//         return response()->json(['error' => $e->getMessage()], 500);
//     }
// });
// Route::get('/test-s3-receipts', function () {
//     return response()->json([
//         'total_receipts' => \App\Models\Receipt::count(),
//         'recent_receipts' => \App\Models\Receipt::latest()->take(5)->get(['id', 'status', 'storage_disk', 'image_path', 'created_at']),
//         's3_receipts' => \App\Models\Receipt::where('storage_disk', 's3')->count(),
//     ]);
// });
// Route::get('/debug-filename', function () {
//     $testFilename = 'test-receipt.jpg';
//     $extension = pathinfo($testFilename, PATHINFO_EXTENSION);
    
//     return response()->json([
//         'original_filename' => $testFilename,
//         'extracted_extension' => $extension,
//         'pathinfo_result' => pathinfo($testFilename),
//     ]);
// });
// Route::get('/debug-s3-flow', function () {
//     try {
//         // Test if we can reach S3
//         $testKey = 'test-' . time() . '.txt';
//         Storage::disk('s3')->put($testKey, 'Hello S3!');
//         $exists = Storage::disk('s3')->exists($testKey);
//         Storage::disk('s3')->delete($testKey);
        
//         return response()->json([
//             's3_connection' => $exists ? 'working' : 'failed',
//             'recent_uploads' => \App\Models\Receipt::latest()->take(3)->get(),
//             'queue_jobs' => DB::table('jobs')->count(),
//             'failed_jobs' => DB::table('failed_jobs')->count(),
//         ]);
//     } catch (\Exception $e) {
//         return response()->json(['error' => $e->getMessage()], 500);
//     }
// });
// Route::get('/env-debug', function () {
//     return response()->json([
//         'app_env' => env('APP_ENV'),
//         'app_debug' => env('APP_DEBUG'),
//         'queue_connection' => env('QUEUE_CONNECTION'),
//         'db_connection' => env('DB_CONNECTION'),
//         'loaded_env_file' => app()->environmentFilePath(),
//     ]);
// });
// Email preview route (remove in production)
Route::get('/preview-welcome-email', function () {
    $fakeUser = new \App\Models\User([
        'name' => 'John Doe',
        'email' => 'john@example.com'
    ]);
    
    $verificationUrl = 'https://www.expenzai.app/verify-email?token=preview-token-123&email=john@example.com';
    
    return view('emails.welcome', [
        'user' => $fakeUser,
        'verificationUrl' => $verificationUrl
    ]);
});
// Route::get('/debug-redis', function () {
//     try {
//         $redis = Redis::connection();
//         $redis->ping();
//         return response()->json(['redis' => 'connected']);
//     } catch (\Exception $e) {
//         return response()->json(['redis' => 'failed', 'error' => $e->getMessage()]);
//     }
// });
Route::get('/', function () {
    return response()->json([
        'status' => 'Expensai',
        'version' => '1.0.0',
        'timestamp' => now()
    ]);
});
