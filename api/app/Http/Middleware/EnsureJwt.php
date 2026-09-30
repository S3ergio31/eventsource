<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Valida el bearer token y deja el user id en el request.
 *
 * Hace dos cosas, y las dos importan:
 *
 *  1. $request->attributes->set('userId', ...) — el dato crudo, accesible
 *     desde cualquier controlador sin volver a tocar el token.
 *  2. $request->setUserResolver(...) — hace que $request->user() funcione. Sin
 *     esto los callbacks de routes/channels.php reciben null y la autorizacion
 *     de canales privados no puede funcionar, porque Laravel obtiene el usuario
 *     justamente desde ahi (ver Broadcaster::retrieveUser()).
 */
class EnsureJwt
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Falta el header Authorization: Bearer <token>'], 401);
        }

        try {
            $payload = $this->jwt->verify($token);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Token invalido: '.$e->getMessage()], 401);
        }

        $user = User::find((int) $payload->sub);

        if (! $user) {
            return response()->json(['message' => 'El usuario del token ya no existe'], 401);
        }

        $request->attributes->set('userId', (int) $user->getKey());
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
