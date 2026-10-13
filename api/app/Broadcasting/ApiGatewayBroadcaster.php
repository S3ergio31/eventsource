<?php

namespace App\Broadcasting;

use App\Broadcasting\ApiGateway\ConnectionStore;
use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;
use Aws\Exception\AwsException;
use GuzzleHttp\Promise\Utils;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Driver de broadcasting "apigateway".
 *
 * Aca Laravel no sostiene ninguna conexion: los WebSockets los mantiene API
 * Gateway, y para escribirle a un cliente se le hace un POST a
 * @connections/{connectionId} (PostToConnection). Como API Gateway no conoce
 * canales, quien esta suscrito a que lo resuelve ConnectionStore (DynamoDB).
 *
 * Cada evento ademas se guarda en el historial del canal, con un ULID como id,
 * para que un cliente que reconecta pueda pedir lo que se perdio.
 */
class ApiGatewayBroadcaster extends Broadcaster
{
    use AuthorizesChannelAccess;
    use UsePusherChannelConventions;

    public function __construct(
        protected ConnectionStore $store,
        protected ApiGatewayManagementApiClient $gateway,
    ) {
    }

    public function store(): ConnectionStore
    {
        return $this->store;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        if (empty($channels)) {
            return;
        }

        Arr::pull($payload, 'socket');

        $data = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($this->formatChannels($channels) as $channel) {
            $id = $this->store->appendEvent($channel, (string) $event, $data);

            $this->sendToMany($this->store->subscribersOf($channel), [
                'type' => 'event',
                'channel' => $channel,
                'id' => $id,
                'event' => (string) $event,
                'data' => $payload,
            ], $channel);
        }
    }

    /**
     * Mensaje a una sola conexion (acks, errores, reposicion).
     *
     * @param  array<string, mixed>  $message
     * @return bool false si la conexion ya no existe.
     */
    public function send(string $connectionId, array $message): bool
    {
        try {
            $this->gateway->postToConnection([
                'ConnectionId' => $connectionId,
                'Data' => $this->encode($message),
            ]);

            return true;
        } catch (AwsException $e) {
            if ($this->isGone($e)) {
                $this->store->forget($connectionId);

                return false;
            }

            throw $e;
        }
    }

    /**
     * Un PostToConnection por suscriptor, todos en paralelo: con N suscriptores
     * son N requests HTTP a AWS y no queremos esperarlos en serie.
     *
     * @param  list<string>  $connectionIds
     * @param  array<string, mixed>  $message
     */
    protected function sendToMany(array $connectionIds, array $message, string $channel): void
    {
        if ($connectionIds === []) {
            return;
        }

        $body = $this->encode($message);
        $promises = [];

        foreach ($connectionIds as $connectionId) {
            $promises[$connectionId] = $this->gateway->postToConnectionAsync([
                'ConnectionId' => $connectionId,
                'Data' => $body,
            ]);
        }

        foreach (Utils::settle($promises)->wait() as $connectionId => $result) {
            if ($result['state'] === 'fulfilled') {
                continue;
            }

            $reason = $result['reason'];

            try {
                if ($reason instanceof AwsException && $this->isGone($reason)) {
                    // El $disconnect no llego (o todavia no): limpiamos aca.
                    $this->store->forget((string) $connectionId, [$channel]);
                } else {
                    report($reason);
                }
            } catch (Throwable $e) {
                // Un fallo limpiando una conexion no debe cortar el resto.
                report($e);
            }
        }
    }

    protected function isGone(AwsException $e): bool
    {
        return $e->getStatusCode() === 410 || $e->getAwsErrorCode() === 'GoneException';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function encode(array $message): string
    {
        return json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
