<?php

namespace App\Providers;

use App\Broadcasting\ApiGateway\ConnectionStore;
use App\Broadcasting\ApiGatewayBroadcaster;
use App\Broadcasting\SseBroadcaster;
use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;
use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Las piezas del driver 'apigateway' van al contenedor: el ConnectionStore
     * tambien lo usan el WsGatewayController y ws:create-tables, y los tests
     * reemplazan ambas por dobles.
     */
    public function register(): void
    {
        $this->app->singleton(ConnectionStore::class, function ($app) {
            $config = $app['config']['broadcasting.connections.apigateway'];

            return new ConnectionStore(
                dynamo: new DynamoDbClient(self::awsOptions($config, $config['dynamodb_endpoint'] ?? null)),
                tablePrefix: $config['table_prefix'] ?? 'ws_',
                connectionTtl: (int) ($config['connection_ttl'] ?? 10800),
                eventsTtl: (int) ($config['events_ttl'] ?? 86400),
            );
        });

        $this->app->singleton(ApiGatewayManagementApiClient::class, function ($app) {
            $config = $app['config']['broadcasting.connections.apigateway'];

            return new ApiGatewayManagementApiClient(
                self::awsOptions($config, $config['management_endpoint'])
            );
        });
    }

    /**
     * Aca se engancha el driver custom a la libreria de broadcasting de Laravel.
     * Despues de esto, 'sse' es un driver valido en config/broadcasting.php y
     * broadcast(new UnEvento) lo usa sin que el resto de la app cambie.
     */
    public function boot(): void
    {
        Broadcast::extend('sse', function ($app, array $config) {
            return new SseBroadcaster(
                redis: $app->make(RedisFactory::class),
                writeConnection: $config['connection'] ?? 'default',
                readConnection: $config['read_connection'] ?? 'sse',
                prefix: $config['prefix'] ?? 'sse:stream:',
                retain: (int) ($config['retain'] ?? 100),
            );
        });

        // Segundo driver, en paralelo: los sockets los sostiene API Gateway y
        // el estado vive en DynamoDB. Ver config/broadcasting.php.
        Broadcast::extend('apigateway', function ($app, array $config) {
            $broadcaster = new ApiGatewayBroadcaster(
                store: $app->make(ConnectionStore::class),
                gateway: $app->make(ApiGatewayManagementApiClient::class),
            );

            // Broadcast::channel() en routes/channels.php registra los callbacks
            // solo en el driver por defecto. Este los hereda, asi la
            // autorizacion sigue teniendo una unica fuente de verdad.
            $default = Broadcast::getDefaultDriver();

            if ($default !== 'apigateway') {
                foreach (Broadcast::connection($default)->getChannels() as $pattern => $callback) {
                    $broadcaster->channel($pattern, $callback);
                }
            }

            return $broadcaster;
        });
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function awsOptions(array $config, ?string $endpoint): array
    {
        $options = [
            'region' => $config['region'],
            'version' => 'latest',
        ];

        if ($endpoint) {
            $options['endpoint'] = $endpoint;
        }

        // Sin claves explicitas, el SDK usa su cadena por defecto (rol de la
        // tarea en ECS, perfil de ~/.aws, etc.).
        if (! empty($config['key']) && ! empty($config['secret'])) {
            $options['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
        }

        return $options;
    }
}
