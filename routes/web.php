<?php

use App\Models\User;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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

Route::get('/test-logs', function () {
    // Direct output that MUST show
    echo "DIRECT ECHO TEST\n";
    file_put_contents('php://stdout', "STDOUT DIRECT WRITE\n");
    file_put_contents('php://stderr', "STDERR DIRECT WRITE\n");
    
    // Laravel logging
    Log::info('LARAVEL LOG TEST');
    
    // PHP error log
    error_log('PHP ERROR LOG TEST');
    
    return response()->json([
        'message' => 'All logging methods tested',
        'log_channel' => config('logging.default'),
        'channels_available' => array_keys(config('logging.channels'))
    ]);
});

Route::get('/test-winds', function () {
    // Test papertrail directly
    $logger = Log::channel('solarwinds');
    $logger->info('Solar DIRECT TEST', ['timestamp' => now()]);
    
    return 'Solar test sent - check SolarWinds dashboard';
});

Route::get('/test-queue-job', function () {
    Log::info('Dispatching test queue job');
    
    // Simple test job
    dispatch(function () {
        Log::info('TEST QUEUE JOB EXECUTED - this should appear in SolarWinds');
    });
    
    return 'Test job dispatched - check SolarWinds logs';
});

Route::get('/debug-filament-full', function() {
    return response()->json([
        'providers_loaded' => array_keys(app()->getLoadedProviders()),
        'filament_provider_loaded' => in_array('App\Providers\Filament\AdminPanelProvider', array_keys(app()->getLoadedProviders())),
        'filament_panels' => method_exists(\Filament\Facades\Filament::class, 'getPanels') ? \Filament\Facades\Filament::getPanels() : 'Method not available',
        'php_version' => PHP_VERSION,
        'laravel_version' => app()->version(),
        'admin_routes' => collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->filter(fn($route) => str_contains($route->uri(), 'admin'))
            ->map(fn($route) => [
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'name' => $route->getName(),
            ])
            ->values()
            ->toArray(),
        'total_routes' => count(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes()),
    ]);
});

Route::get('/test-filament-class', function() {
    return response()->json([
        'filament_panel_provider_exists' => class_exists(\App\Providers\Filament\AdminPanelProvider::class),
        'filament_facade_exists' => class_exists(\Filament\Facades\Filament::class),
        'php_version' => PHP_VERSION,
    ]);
});