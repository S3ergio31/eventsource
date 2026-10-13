<?php

namespace App\Console\Commands;

use App\Broadcasting\ApiGateway\ConnectionStore;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Illuminate\Console\Command;

/**
 * Crea las tablas del driver 'apigateway' en DynamoDB Local. En AWS las crea
 * infra/template.yaml; esto es solo para el entorno de docker-compose.
 * Es idempotente: las tablas que ya existen se saltean.
 */
class CreateWsTables extends Command
{
    protected $signature = 'ws:create-tables';

    protected $description = 'Crea las tablas DynamoDB del driver apigateway (para DynamoDB Local)';

    public function handle(ConnectionStore $store): int
    {
        $dynamo = $store->client();

        $tables = [
            $store->connectionsTable() => ['connectionId'],
            $store->subscriptionsTable() => ['channel', 'connectionId'],
            $store->eventsTable() => ['channel', 'eventId'],
        ];

        foreach ($tables as $table => $keys) {
            $this->createTable($dynamo, $table, $keys);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $keys  [partition key, sort key?], todas de tipo string.
     */
    private function createTable(DynamoDbClient $dynamo, string $table, array $keys): void
    {
        try {
            $dynamo->describeTable(['TableName' => $table]);
            $this->line("  {$table}: ya existe");

            return;
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }

        $dynamo->createTable([
            'TableName' => $table,
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => array_map(
                fn ($key) => ['AttributeName' => $key, 'AttributeType' => 'S'],
                $keys,
            ),
            'KeySchema' => array_map(
                fn ($key, $i) => ['AttributeName' => $key, 'KeyType' => $i === 0 ? 'HASH' : 'RANGE'],
                $keys,
                array_keys($keys),
            ),
        ]);

        $dynamo->waitUntil('TableExists', ['TableName' => $table]);

        $dynamo->updateTimeToLive([
            'TableName' => $table,
            'TimeToLiveSpecification' => ['AttributeName' => 'ttl', 'Enabled' => true],
        ]);

        $this->info("  {$table}: creada");
    }
}
