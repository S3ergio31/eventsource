<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege /api/ws/gateway/*: esas rutas las llama API Gateway, no el
 * navegador, y confian en el header X-Connection-Id. Si cualquiera pudiera
 * llamarlas, podria suscribir o desconectar conexiones ajenas.
 *
 * API Gateway inyecta el secreto como header estatico en cada integracion
 * (ver infra/template.yaml). En produccion conviene ademas un VPC Link para
 * que estas rutas ni siquiera sean alcanzables desde internet.
 */
class EnsureGatewaySecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ws.gateway_secret');
        $given = (string) $request->header('X-Gateway-Secret', '');

        // Sin secreto configurado no se abre la puerta: se niega todo.
        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Gateway secret invalido'], 403);
        }

        return $next($request);
    }
}
