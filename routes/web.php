<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'API is running',
        'timestamp' => now(),
        'app' => config('app.name')
    ]);
});

Route::get('/health', function () {
    return response()->json(['status' => 'healthy']);
});