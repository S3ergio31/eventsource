<?php

namespace Tests\Feature\ApiGateway;

use App\Broadcasting\ApiGateway\ConnectionStore;
use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;
use Aws\ApiGatewayManagementApi\Exception\ApiGatewayManagementApiException;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\MockInterface;

/**
 * Dobles para el driver 'apigateway': el ConnectionStore es un mock y el
 * cliente de AWS usa el MockHandler del SDK, que registra cada
 * PostToConnection en $this->posted (connectionId => mensajes decodificados).
 */
trait FakesApiGateway
{
    protected ConnectionStore&MockInterface $store;

    /** @var array<string, list<array<string, mixed>>> */
    protected array $posted = [];

    /** @var list<string> connectionIds que responden 410 */
    protected array $goneConnections = [];

    protected function fakeApiGateway(): void
    {
        $this->store = Mockery::mock(ConnectionStore::class);
        $this->app->instance(ConnectionStore::class, $this->store);

        $handler = new MockHandler;
        $respond = function (CommandInterface $command) use ($handler, &$respond) {
            // El MockHandler consume una respuesta por llamada: se re-encola.
            $handler->append($respond);

            $connectionId = $command['ConnectionId'];

            if (in_array($connectionId, $this->goneConnections, true)) {
                return new ApiGatewayManagementApiException('Gone', $command, [
                    'code' => 'GoneException',
                    'response' => new Response(410),
                ]);
            }

            $this->posted[$connectionId][] = json_decode($command['Data'], true);

            return new Result;
        };
        $handler->append($respond);

        $this->app->instance(ApiGatewayManagementApiClient::class, new ApiGatewayManagementApiClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => 'http://gateway.test',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]));
    }
}
