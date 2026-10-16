<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SseController;
use App\Http\Controllers\WsGatewayController;
use App\Http\Controllers\WsTicketController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('jwt')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // El stream. Es una ruta GET normal; lo unico distinto es que no termina.
    Route::get('/sse', [SseController::class, 'stream']);

    // Canje del JWT de sesion por un ticket corto para abrir el WebSocket.
    Route::post('/ws/ticket', WsTicketController::class);

    // authEndpoint de Laravel Echo para el driver mercure. Es el auth() nativo
    // (corre routes/channels.php y responde con la cookie de suscriptor del
    // hub). Va aparte de /broadcasting/auth porque ese usa el driver por
    // defecto ('sse').
    //
    // Si 'mercure' quedara como driver unico, esta ruta sobra: el
    // /api/broadcasting/auth de withBroadcasting() (bootstrap/app.php) ya
    // usaria mercure, y el front apuntaria su authEndpoint ahi.
    Route::post('/broadcasting/mercure/auth', fn (Request $request) => Broadcast::connection('mercure')->auth($request));
});

// Las llama API Gateway (o gateway-local), no el navegador.
Route::middleware('gateway')->prefix('ws/gateway')->group(function () {
    Route::post('/connect', [WsGatewayController::class, 'connect']);
    Route::post('/subscribe', [WsGatewayController::class, 'subscribe']);
    Route::post('/disconnect', [WsGatewayController::class, 'disconnect']);
});
