<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// JSON API used by the React interface. It uses the normal session and CSRF token (design decision DD-06).
Route::prefix('api')->group(function () {
    Route::get('csrf', fn () => response()->json(['ok' => true]));
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('admin/ping', fn () => response()->json(['ok' => true]))->middleware('role:System Administrator');
    });
});