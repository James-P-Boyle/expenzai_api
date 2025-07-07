<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'Receipt Tracker API',
        'version' => '1.0.0',
        'timestamp' => now()
    ]);
});