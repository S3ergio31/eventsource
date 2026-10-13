<?php

namespace App\Http\Controllers;

use App\Broadcasting\MercureBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Canje del JWT de sesion por un JWT de suscriptor de Mercure.
 *
 * Es el unico punto donde Laravel autoriza al transporte Mercure: aca corren
 * los callbacks de routes/channels.php y, si pasan, se firma un token que el
 * hub acepta sin volver a consultar. Por eso vive poco (MERCURE_SUBSCRIBER_TTL)
 * y el cliente lo renueva cuando el hub responde 401.
 */
class MercureTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $channel = (string) $request->input('channel', '');

        abort_if($channel === '', 400, 'Falta channel');

        /** @var MercureBroadcaster $broadcaster */
        $broadcaster = Broadcast::connection('mercure');

        abort_unless(
            $broadcaster->canAccess($request, $channel),
            403,
            "No tenes acceso al canal {$channel}"
        );

        return response()->json([
            ...$broadcaster->subscriberToken([$channel]),
            'hub_url' => $broadcaster->publicUrl(),
            'topic' => $channel,
            // Para que el cliente estime el desfase entre su reloj y el del
            // servidor (los sentAt de los eventos usan este reloj).
            'server_time' => (int) floor(microtime(true) * 1000),
        ]);
    }
}
