<?php

namespace App\Providers;

use App\Broadcasting\ApiGateway\ConnectionStore;
use App\Broadcasting\ApiGatewayBroadcaster;
use App\Broadcasting\SseBroadcaster;
use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;
use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Las piezas del driver 'apigateway' van al contenedor: el ConnectionStore
     * tambien lo usan el WsGatewayController y ws:create-tables, y los tests
     * las reemplazan por dobles.
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
     * Aca se enganchan los tres drivers candidatos ('sse', 'apigateway' y
     * 'mercure') a la libreria de broadcasting de Laravel. Despues de esto son
     * drivers validos en config/broadcasting.php y broadcast(new UnEvento) los
     * usa sin que el resto de la app cambie: elegir uno es solo configuracion.
     *
     * Si 'mercure' quedara como driver unico, este provider entero sobra: los
     * extend() y la herencia de canales existen solo porque conviven varios
     * drivers. Alcanza con el driver nativo, BROADCAST_CONNECTION=mercure y
     * withBroadcasting() en bootstrap/app.php.
     */
    public function boot(): void
    {
        // Driver 'sse': Laravel sostiene el stream (SseController) y el estado
        // vive en Redis Streams. Ver config/broadcasting.php.
        Broadcast::extend('sse', function ($app, array $config) {
            return new SseBroadcaster(
                redis: $app->make(RedisFactory::class),
                writeConnection: $config['connection'] ?? 'default',
                readConnection: $config['read_connection'] ?? 'sse',
                prefix: $config['prefix'] ?? 'sse:stream:',
                retain: (int) ($config['retain'] ?? 100),
            );
        });

        // Broadcast::channel() en routes/channels.php registra los callbacks
        // solo en el driver por defecto. Los otros drivers los heredan, asi la
        // autorizacion sigue teniendo una unica fuente de verdad.
        //
        // Es un closure y no un metodo porque Broadcast::extend() re-vincula
        // los closures al BroadcastManager: adentro, $this y self:: ya no son
        // este provider.
        $inheritChannels = static function (Broadcaster $broadcaster, string $driver): Broadcaster {
            $default = Broadcast::getDefaultDriver();

            if ($default !== $driver) {
                foreach (Broadcast::connection($default)->getChannels() as $pattern => $callback) {
                    $broadcaster->channel($pattern, $callback);
                }
            }

            return $broadcaster;
        };

        // Driver 'apigateway': los sockets los sostiene API Gateway y el estado
        // vive en DynamoDB. Ver config/broadcasting.php.
        Broadcast::extend('apigateway', function ($app, array $config) use ($inheritChannels) {
            return $inheritChannels(new ApiGatewayBroadcaster(
                store: $app->make(ConnectionStore::class),
                gateway: $app->make(ApiGatewayManagementApiClient::class),
            ), 'apigateway');
        });

        // Driver 'mercure': el nativo de Laravel. El hub Mercure que trae
        // FrankenPHP sostiene las conexiones SSE; Laravel solo publica. Ver
        // docker/Caddyfile. Se pasa por extend() unicamente para heredar los
        // canales: el closure queda vinculado al BroadcastManager, asi que
        // $this->createMercureDriver() es el mismo creador que usaria el
        // framework sin este extend. Con mercure como driver por defecto (o
        // unico) este extend no hace nada y se puede borrar.
        Broadcast::extend('mercure', function ($app, array $config) use ($inheritChannels) {
            return $inheritChannels($this->createMercureDriver($config), 'mercure');
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
