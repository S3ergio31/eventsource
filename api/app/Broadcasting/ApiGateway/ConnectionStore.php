<?php

namespace App\Broadcasting\ApiGateway;

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;

/**
 * Todo el estado del transporte API Gateway vive en DynamoDB, en tres tablas:
 *
 *  - connections:   connectionId -> userId + canales a los que se suscribio.
 *  - subscriptions: canal -> connectionIds. Es lo que API Gateway no tiene:
 *                   solo conoce conexiones sueltas, no canales.
 *  - events:        canal -> historial corto de eventos (ULID como id), para
 *                   reponer lo que un cliente se perdio mientras reconectaba.
 *                   Cumple el papel de los Redis Streams del driver SSE.
 *
 * Todas las filas llevan 'ttl' (epoch en segundos) y la tabla tiene TTL
 * activado: DynamoDB poda solo las conexiones cuyo $disconnect nunca llego.
 */
class ConnectionStore
{
    /**
     * Cuanto se retrocede desde el lastId del cliente al reponer. Los ULID se
     * generan con el reloj de cada instancia; con este margen, un evento
     * escrito por una instancia con el reloj algo atrasado no queda afuera.
     * El cliente descarta los duplicados por id.
     */
    public const REPLAY_SKEW_MS = 5000;

    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(
        private DynamoDbClient $dynamo,
        private string $tablePrefix = 'ws_',
        private int $connectionTtl = 10800,
        private int $eventsTtl = 86400,
    ) {
    }

    public function client(): DynamoDbClient
    {
        return $this->dynamo;
    }

    public function connectionsTable(): string
    {
        return $this->tablePrefix.'connections';
    }

    public function subscriptionsTable(): string
    {
        return $this->tablePrefix.'subscriptions';
    }

    public function eventsTable(): string
    {
        return $this->tablePrefix.'events';
    }

    // ---------------------------------------------------------------------
    // Conexiones
    // ---------------------------------------------------------------------

    public function openConnection(string $connectionId, int $userId): void
    {
        $this->dynamo->putItem([
            'TableName' => $this->connectionsTable(),
            'Item' => [
                'connectionId' => ['S' => $connectionId],
                'userId' => ['N' => (string) $userId],
                'connectedAt' => ['N' => (string) time()],
                'ttl' => ['N' => (string) (time() + $this->connectionTtl)],
            ],
        ]);
    }

    /**
     * @return array{connectionId: string, userId: int, channels: list<string>}|null
     */
    public function findConnection(string $connectionId): ?array
    {
        $item = $this->dynamo->getItem([
            'TableName' => $this->connectionsTable(),
            'Key' => ['connectionId' => ['S' => $connectionId]],
            'ConsistentRead' => true,
        ])['Item'] ?? null;

        if (! $item) {
            return null;
        }

        return [
            'connectionId' => $connectionId,
            'userId' => (int) $item['userId']['N'],
            'channels' => array_values($item['channels']['SS'] ?? []),
        ];
    }

    /**
     * Borra la conexion y todas sus suscripciones. Lo llaman $disconnect y el
     * broadcaster cuando API Gateway responde 410 (la conexion ya no existe).
     *
     * @param  list<string>  $knownChannels  Canales a limpiar aunque el registro
     *                                       de la conexion ya no este.
     */
    public function forget(string $connectionId, array $knownChannels = []): void
    {
        $channels = array_unique([
            ...($this->findConnection($connectionId)['channels'] ?? []),
            ...$knownChannels,
        ]);

        foreach ($channels as $channel) {
            $this->dynamo->deleteItem([
                'TableName' => $this->subscriptionsTable(),
                'Key' => [
                    'channel' => ['S' => $channel],
                    'connectionId' => ['S' => $connectionId],
                ],
            ]);
        }

        $this->dynamo->deleteItem([
            'TableName' => $this->connectionsTable(),
            'Key' => ['connectionId' => ['S' => $connectionId]],
        ]);
    }

    // ---------------------------------------------------------------------
    // Suscripciones
    // ---------------------------------------------------------------------

    public function subscribe(string $connectionId, string $channel): void
    {
        $ttl = (string) (time() + $this->connectionTtl);

        $this->dynamo->putItem([
            'TableName' => $this->subscriptionsTable(),
            'Item' => [
                'channel' => ['S' => $channel],
                'connectionId' => ['S' => $connectionId],
                'ttl' => ['N' => $ttl],
            ],
        ]);

        // El indice inverso, para que forget() sepa que borrar.
        $this->dynamo->updateItem([
            'TableName' => $this->connectionsTable(),
            'Key' => ['connectionId' => ['S' => $connectionId]],
            'UpdateExpression' => 'ADD #channels :channel',
            'ExpressionAttributeNames' => ['#channels' => 'channels'],
            'ExpressionAttributeValues' => [':channel' => ['SS' => [$channel]]],
        ]);
    }

    /**
     * @return list<string> connectionIds suscritos al canal.
     */
    public function subscribersOf(string $channel): array
    {
        $ids = [];

        $pages = $this->dynamo->getPaginator('Query', [
            'TableName' => $this->subscriptionsTable(),
            'KeyConditionExpression' => '#channel = :channel',
            'ExpressionAttributeNames' => ['#channel' => 'channel', '#cid' => 'connectionId'],
            'ExpressionAttributeValues' => [':channel' => ['S' => $channel]],
            'ProjectionExpression' => '#cid',
        ]);

        foreach ($pages as $page) {
            foreach ($page['Items'] ?? [] as $item) {
                $ids[] = $item['connectionId']['S'];
            }
        }

        return $ids;
    }

    // ---------------------------------------------------------------------
    // Historial de eventos
    // ---------------------------------------------------------------------

    /**
     * @return string El id del evento (ULID): ordenable por tiempo, igual que
     *                los ids de Redis Streams del driver SSE.
     */
    public function appendEvent(string $channel, string $event, string $data): string
    {
        $id = (string) Str::ulid();

        $this->dynamo->putItem([
            'TableName' => $this->eventsTable(),
            'Item' => [
                'channel' => ['S' => $channel],
                'eventId' => ['S' => $id],
                'event' => ['S' => $event],
                'data' => ['S' => $data],
                'ttl' => ['N' => (string) (time() + $this->eventsTtl)],
            ],
        ]);

        return $id;
    }

    /**
     * Eventos posteriores a $lastId (con el margen REPLAY_SKEW_MS), en orden.
     *
     * @return list<array{id: string, event: string, data: string}>
     */
    public function eventsAfter(string $channel, string $lastId): array
    {
        if (! Ulid::isValid($lastId)) {
            return [];
        }

        $events = [];

        $pages = $this->dynamo->getPaginator('Query', [
            'TableName' => $this->eventsTable(),
            'KeyConditionExpression' => '#channel = :channel AND #eventId > :after',
            'ExpressionAttributeNames' => ['#channel' => 'channel', '#eventId' => 'eventId'],
            'ExpressionAttributeValues' => [
                ':channel' => ['S' => $channel],
                ':after' => ['S' => self::replayLowerBound($lastId)],
            ],
            'ConsistentRead' => true,
        ]);

        foreach ($pages as $page) {
            foreach ($page['Items'] ?? [] as $item) {
                $events[] = [
                    'id' => $item['eventId']['S'],
                    'event' => $item['event']['S'],
                    'data' => $item['data']['S'],
                ];
            }
        }

        return $events;
    }

    /**
     * El ULID mas chico posible REPLAY_SKEW_MS antes que $lastId. Los primeros
     * 10 caracteres de un ULID son el timestamp en ms (base32 de Crockford),
     * el resto es aleatorio; rellenar con '0' da el minimo de ese instante.
     */
    public static function replayLowerBound(string $lastId): string
    {
        $ms = (int) Ulid::fromString($lastId)->getDateTime()->format('Uv');
        $ms = max(0, $ms - self::REPLAY_SKEW_MS);

        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::CROCKFORD[$ms % 32].$time;
            $ms = intdiv($ms, 32);
        }

        return $time.str_repeat('0', 16);
    }
}
