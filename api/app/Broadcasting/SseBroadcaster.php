<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Driver de broadcasting "sse".
 *
 * Laravel no sabe nada de SSE: cuando hacés broadcast(new UnEvento), el
 * BroadcastManager resuelve este driver y llama broadcast(). Lo unico que
 * hacemos aca es dejar el evento en un Redis Stream por canal. Quien lo lea
 * (ver SseController) es problema de otra capa.
 *
 * Usamos Streams y no Pub/Sub por una razon concreta: el ID que devuelve XADD
 * ("1727...-0") sirve tal cual como campo "id:" de SSE, asi que cuando el
 * navegador reconecta y manda Last-Event-ID podemos retomar exactamente donde
 * se corto, sin perder eventos. Pub/Sub no tiene memoria y no permite eso.
 */
class SseBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function __construct(
        protected RedisFactory $redis,
        protected ?string $writeConnection = null,
        protected ?string $readConnection = null,
        protected string $prefix = 'sse:stream:',
        protected int $retain = 100,
    ) {
    }

    // ---------------------------------------------------------------------
    // Contrato de Broadcaster
    // ---------------------------------------------------------------------

    /**
     * Endpoint POST /api/broadcasting/auth. Lo dejamos funcionando por
     * compatibilidad con el flujo estandar de Laravel, aunque el cliente de
     * este driver no lo usa: el SseController autoriza en linea con canAccess().
     */
    public function auth($request)
    {
        $channel = (string) $request->channel_name;

        if ($channel === '') {
            throw new AccessDeniedHttpException('channel_name is required');
        }

        $normalized = $this->normalizeChannelName($channel);

        if ($this->isGuardedChannel($channel) && ! $this->retrieveUser($request, $normalized)) {
            throw new AccessDeniedHttpException('Unauthenticated');
        }

        return parent::verifyUserCanAccessChannel($request, $normalized);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return is_bool($result)
            ? json_encode($result)
            : json_encode(['channel_data' => $result]);
    }

    /**
     * El unico metodo que realmente importa: Laravel nos entrega los canales,
     * el nombre del evento y el payload ya serializado.
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {

        Log::info('SseBroadcaster::broadcast', [
            'channels' => $channels,
            'event' => $event,
            'payload' => $payload
        ]);

        if (empty($channels)) {
            return;
        }

        Arr::pull($payload, 'socket');

        $entry = [
            'event' => (string) $event,
            'data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $redis = $this->redis->connection($this->writeConnection);

        foreach ($this->formatChannels($channels) as $channel) {
            // MAXLEN ~ N: el stream se auto-poda. Es historial para reconexiones,
            // no un log permanente.
            $redis->xadd($this->streamKey($channel), '*', $entry, $this->retain, true);
        }
    }

    // ---------------------------------------------------------------------
    // Lo que consume el endpoint SSE
    // ---------------------------------------------------------------------

    /**
     * Misma autorizacion que auth(), pero devolviendo bool en vez de tirar
     * excepcion, para poder responder 403 desde el controlador.
     *
     * La logica de quien puede entrar donde vive en routes/channels.php y no se
     * duplica aca: verifyUserCanAccessChannel() ejecuta esos callbacks.
     */
    public function canAccess($request, string $channel): bool
    {
        if (! $this->isGuardedChannel($channel)) {
            return true;
        }

        $normalized = $this->normalizeChannelName($channel);

        if (! $this->retrieveUser($request, $normalized)) {
            return false;
        }

        try {
            $this->verifyUserCanAccessChannel($request, $normalized);

            return true;
        } catch (AccessDeniedHttpException) {
            return false;
        }
    }

    /**
     * Lee los eventos posteriores a $afterId, bloqueando hasta $blockMs.
     *
     * $afterId puede ser "$" (solo lo nuevo, para una conexion fresca) o un ID
     * concreto de Redis (para reanudar desde un Last-Event-ID).
     *
     * @return list<array{id: string, event: string, data: string}>
     */
    public function readAfter(string $channel, string $afterId, int $blockMs): array
    {
        $key = $this->streamKey($channel);

        $result = $this->redis->connection($this->readConnection)
            ->xread([$key => $afterId], 100, $blockMs);

        if (! is_array($result) || ! isset($result[$key])) {
            return [];
        }

        $events = [];

        foreach ($result[$key] as $id => $fields) {
            $events[] = [
                'id' => (string) $id,
                'event' => $fields['event'] ?? 'message',
                'data' => $fields['data'] ?? '{}',
            ];
        }

        return $events;
    }

    public function streamKey(string $channel): string
    {
        return $this->prefix.$channel;
    }
}
