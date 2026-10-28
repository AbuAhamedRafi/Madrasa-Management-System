<?php

use App\Http\Controllers\Authentication\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Native Laravel Sanctum session endpoints for SPA login/logout
Route::middleware('web')->group(function () {
    Route::post('/login', [SessionController::class, 'login']);
    Route::post('/logout', [SessionController::class, 'logout']);
});
