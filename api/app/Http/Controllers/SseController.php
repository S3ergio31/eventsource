<?php

namespace App\Http\Controllers;

use App\Broadcasting\SseBroadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SseController extends Controller
{
    public function stream(Request $request): StreamedResponse
    {
        $channel = $this->requireChannel($request);
        $broadcaster = $this->broadcaster();

        $this->authorizeChannel($broadcaster, $request, $channel);

        $lastId = $this->resumePointFrom($request);

        return $this->eventStreamResponse(
            fn () => $this->streamEvents($broadcaster, $channel, $lastId)
        );
    }

    private function requireChannel(Request $request): string
    {
        $channel = (string) $request->query('channel', '');

        abort_if($channel === '', 400, 'Falta el parametro ?channel=');

        return $channel;
    }

    private function broadcaster(): SseBroadcaster
    {
        /** @var SseBroadcaster $broadcaster */
        $broadcaster = Broadcast::connection('sse');

        return $broadcaster;
    }

    private function authorizeChannel(SseBroadcaster $broadcaster, Request $request, string $channel): void
    {
        // Misma autorizacion que usaria Pusher: los callbacks de
        // routes/channels.php son la unica fuente de verdad.
        abort_unless(
            $broadcaster->canAccess($request, $channel),
            403,
            "No tenes acceso al canal {$channel}"
        );
    }

    private function resumePointFrom(Request $request): string
    {
        // Si el cliente reconecta, @microsoft/fetch-event-source manda este
        // header con el ultimo id que recibio, y retomamos desde ahi. En una
        // conexion nueva arrancamos en '$' = solo lo que llegue de ahora en mas.
        return $request->header('Last-Event-ID') ?: '$';
    }

    private function eventStreamResponse(callable $callback): StreamedResponse
    {
        $response = new StreamedResponse($callback);

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        // Le dice a nginx y companiia que no bufferice este response.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function streamEvents(SseBroadcaster $broadcaster, string $channel, string $lastId): void
    {
        $this->prepareUnbufferedOutput();
        $deadline = $this->connectionDeadline();

        $this->sendRetryInterval();
        $this->emit('sse:connected', ['channel' => $channel, 'resumedFrom' => $lastId]);

        while (! connection_aborted()) {
            if ($this->lifetimeExpired($deadline)) {
                // Cerramos nosotros antes de que lo haga un timeout ajeno.
                // El cliente reconecta con Last-Event-ID y no pierde nada.
                $this->requestReconnect('max_lifetime_reached');

                return;
            }

            // Bloquea hasta blockMs esperando eventos nuevos. Mientras
            // bloquea, este hilo no consume CPU.
            try {
                $events = $broadcaster->readAfter($channel, $lastId, $this->blockMs());
            } catch (Throwable $e) {
                // Redis se cayo o vencio el read_timeout. Los headers ya
                // salieron, asi que no podemos devolver un 500: cortamos
                // limpio y el cliente reconecta con Last-Event-ID.
                report($e);
                $this->requestReconnect('redis_unavailable');

                return;
            }

            if ($events === []) {
                $this->sendHeartbeat();

                continue;
            }

            $lastId = $this->sendEvents($events);
        }
    }

    private function prepareUnbufferedOutput(): void
    {
        @set_time_limit(0); // timeout infinito
        ignore_user_abort(false); // no abortar el script si el cliente se desconecta

        // Sin esto PHP acumula la salida y el navegador no ve nada.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    private function connectionDeadline(): ?float
    {
        $maxLifetime = $this->maxLifetime();

        return $maxLifetime > 0 ? microtime(true) + $maxLifetime : null;
    }

    private function lifetimeExpired(?float $deadline): bool
    {
        return $deadline !== null && microtime(true) >= $deadline;
    }

    private function requestReconnect(string $reason): void
    {
        $this->emit('sse:reconnect', ['reason' => $reason]);
    }

    private function sendRetryInterval(): void
    {
        // Es una instrucción para el cliente SSE define cuántos milisegundos
        // espera el cliente antes de reconectarse cuando se corta la conexión.
        echo "retry: {$this->retryMs()}\n\n";
    }

    private function sendHeartbeat(): void
    {
        // Un comentario SSE mantiene viva la conexion y, al escribir,
        // nos enteramos si el cliente se fue.
        echo ":heartbeat\n\n";
        flush();
    }

    /**
     * @param  non-empty-list<array{id: string, event: string, data: string}>  $events
     * @return string El id del ultimo evento enviado.
     */
    private function sendEvents(array $events): string
    {
        foreach ($events as $event) {
            $this->writeEvent($event);
        }

        flush();

        return end($events)['id'];
    }

    /**
     * @param  array{id: string, event: string, data: string}  $event
     */
    private function writeEvent(array $event): void
    {
        echo "id: {$event['id']}\n";
        echo "event: {$event['event']}\n";
        echo "data: {$event['data']}\n\n";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emit(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_SLASHES)."\n\n";
        flush();
    }

    private function blockMs(): int
    {
        return (int) config('sse.block_ms');
    }

    private function maxLifetime(): int
    {
        return (int) config('sse.max_lifetime');
    }

    private function retryMs(): int
    {
        return (int) config('sse.retry_ms');
    }
}
