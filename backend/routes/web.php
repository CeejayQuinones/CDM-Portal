<?php

use App\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:20,1');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1');

Route::get('/', function () {
    return view('welcome');
});
