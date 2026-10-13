<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SseController;
use App\Http\Controllers\WsGatewayController;
use App\Http\Controllers\WsTicketController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('jwt')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // El stream. Es una ruta GET normal; lo unico distinto es que no termina.
    Route::get('/sse', [SseController::class, 'stream']);

    // Canje del JWT de sesion por un ticket corto para abrir el WebSocket.
    Route::post('/ws/ticket', WsTicketController::class);
});

// Las llama API Gateway (o gateway-local), no el navegador.
Route::middleware('gateway')->prefix('ws/gateway')->group(function () {
    Route::post('/connect', [WsGatewayController::class, 'connect']);
    Route::post('/subscribe', [WsGatewayController::class, 'subscribe']);
    Route::post('/disconnect', [WsGatewayController::class, 'disconnect']);
});
