<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SseController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('jwt')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // El stream. Es una ruta GET normal; lo unico distinto es que no termina.
    Route::get('/sse', [SseController::class, 'stream']);
});
