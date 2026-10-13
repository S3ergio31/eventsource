<?php

namespace App\Http\Controllers;

use App\Broadcasting\ApiGateway\ConnectionStore;
use App\Broadcasting\ApiGatewayBroadcaster;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Throwable;

/**
 * Las rutas del WebSocket de API Gateway, vistas desde Laravel. Cada evento
 * del socket llega como un POST corto, con el connectionId en un header:
 *
 *   $connect     -> connect()      valida el ticket; un no-2xx rechaza el socket
 *   subscribe    -> subscribe()    autoriza el canal y repone lo perdido
 *   $disconnect  -> disconnect()   limpia el estado
 *
 * Laravel nunca sostiene la conexion: responde y se libera.
 */
class WsGatewayController extends Controller
{
    public function __construct(private ConnectionStore $store)
    {
    }

    public function connect(Request $request, JwtService $jwt): JsonResponse
    {
        $connectionId = $this->connectionId($request);
        $ticket = (string) ($request->query('ticket') ?: $request->header('X-Ws-Ticket', ''));

        try {
            $payload = $jwt->verify($ticket, JwtService::AUDIENCE_WS);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Ticket invalido: '.$e->getMessage()], 401);
        }

        $user = User::find((int) $payload->sub);

        if (! $user) {
            return response()->json(['message' => 'El usuario del ticket ya no existe'], 401);
        }

        $this->store->openConnection($connectionId, (int) $user->getKey());

        return response()->json(['connected' => true]);
    }

    /**
     * El cuerpo es el mensaje que mando el cliente por el socket:
     * {"action":"subscribe","channel":"private-user.1","lastId":"01J..."}
     *
     * Siempre responde 200 a API Gateway: el resultado se le avisa al cliente
     * con mensajes por el propio socket (PostToConnection).
     */
    public function subscribe(Request $request): JsonResponse
    {
        $connectionId = $this->connectionId($request);
        $channel = (string) $request->json('channel', '');
        $lastId = $request->json('lastId');
        $lastId = is_string($lastId) && $lastId !== '' ? $lastId : null;

        $broadcaster = $this->broadcaster();
        $connection = $this->store->findConnection($connectionId);

        if (! $connection) {
            return response()->json(['message' => 'Conexion desconocida'], 410);
        }

        if ($channel === '') {
            $broadcaster->send($connectionId, ['type' => 'error', 'channel' => null, 'status' => 400, 'message' => 'Falta channel']);

            return response()->json(['subscribed' => false]);
        }

        // Mismo truco que EnsureJwt: $request->user() tiene que resolver para
        // que los callbacks de routes/channels.php reciban al usuario.
        $user = User::find($connection['userId']);
        $request->setUserResolver(fn () => $user);

        if (! $user || ! $broadcaster->canAccess($request, $channel)) {
            $broadcaster->send($connectionId, [
                'type' => 'error',
                'channel' => $channel,
                'status' => 403,
                'message' => "No tenes acceso al canal {$channel}",
            ]);

            return response()->json(['subscribed' => false]);
        }

        // El orden importa: primero la suscripcion y despues la lectura del
        // historial. Al reves, un evento publicado entre las dos operaciones se
        // perderia; asi, en el peor caso llega dos veces y el cliente lo descarta.
        $this->store->subscribe($connectionId, $channel);

        $missed = $lastId ? $this->store->eventsAfter($channel, $lastId) : [];

        foreach ($missed as $event) {
            $broadcaster->send($connectionId, [
                'type' => 'event',
                'channel' => $channel,
                'id' => $event['id'],
                'event' => $event['event'],
                'data' => json_decode($event['data'], true),
            ]);
        }

        // 'cursor' es el "desde aca" para un cliente sin lastId: si se corta
        // antes de recibir el primer evento, reconecta pidiendo desde este id y
        // no pierde lo publicado durante el corte.
        $broadcaster->send($connectionId, [
            'type' => 'subscribed',
            'channel' => $channel,
            'resumedFrom' => $lastId,
            'replayed' => count($missed),
            'cursor' => (string) Str::ulid(),
        ]);

        return response()->json(['subscribed' => true, 'replayed' => count($missed)]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->store->forget($this->connectionId($request));

        return response()->json(['disconnected' => true]);
    }

    private function connectionId(Request $request): string
    {
        $connectionId = (string) $request->header('X-Connection-Id', '');

        abort_if($connectionId === '', 400, 'Falta el header X-Connection-Id');

        return $connectionId;
    }

    private function broadcaster(): ApiGatewayBroadcaster
    {
        /** @var ApiGatewayBroadcaster $broadcaster */
        $broadcaster = Broadcast::connection('apigateway');

        return $broadcaster;
    }
}
