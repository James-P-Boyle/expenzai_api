<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'Expensai',
        'version' => '1.0.0',
        'timestamp' => now()
    ]);
});