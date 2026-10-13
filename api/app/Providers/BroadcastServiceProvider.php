<?php

namespace App\Providers;

use App\Broadcasting\ApiGateway\ConnectionStore;
use App\Broadcasting\ApiGatewayBroadcaster;
use App\Broadcasting\MercureBroadcaster;
use App\Broadcasting\SseBroadcaster;
use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;
use Aws\DynamoDb\DynamoDbClient;
use Firebase\JWT\JWT;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\CallableTokenProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Las piezas de los drivers 'apigateway' y 'mercure' van al contenedor:
     * el ConnectionStore tambien lo usan el WsGatewayController y
     * ws:create-tables, y los tests reemplazan todas por dobles.
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

        $this->app->singleton(HubInterface::class, function ($app) {
            $config = $app['config']['broadcasting.connections.mercure'];

            // Laravel publica con un JWT propio de vida corta, firmado con la
            // clave de publicador que el hub tiene en el Caddyfile.
            return new Hub(
                url: $config['hub_url'],
                jwtProvider: new CallableTokenProvider(fn () => JWT::encode([
                    'mercure' => ['publish' => ['*']],
                    'exp' => time() + 60,
                ], $config['publisher_key'], 'HS256')),
                publicUrl: $config['public_url'],
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

        // Segundo driver, en paralelo: los sockets los sostiene API Gateway y
        // el estado vive en DynamoDB. Ver config/broadcasting.php.
        Broadcast::extend('apigateway', function ($app, array $config) use ($inheritChannels) {
            return $inheritChannels(new ApiGatewayBroadcaster(
                store: $app->make(ConnectionStore::class),
                gateway: $app->make(ApiGatewayManagementApiClient::class),
            ), 'apigateway');
        });

        // Tercer driver: el hub Mercure que trae FrankenPHP sostiene las
        // conexiones SSE; Laravel solo publica. Ver docker/Caddyfile.
        Broadcast::extend('mercure', function ($app, array $config) use ($inheritChannels) {
            return $inheritChannels(new MercureBroadcaster(
                hub: $app->make(HubInterface::class),
                subscriberKey: $config['subscriber_key'],
                subscriberTtl: (int) ($config['subscriber_ttl'] ?? 3600),
            ), 'mercure');
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
