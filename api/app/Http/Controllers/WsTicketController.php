<?php

namespace App\Http\Controllers;

use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El navegador no puede mandar Authorization en el handshake de un
 * WebSocket. En vez de poner el JWT de sesion en la URL (queda en logs de
 * acceso), se canjea aca por un ticket que vive segundos y solo sirve para
 * abrir el socket (aud = 'ws').
 */
class WsTicketController extends Controller
{
    public function __invoke(Request $request, JwtService $jwt): JsonResponse
    {
        return response()->json($jwt->issueWsTicket($request->user()));
    }
}
