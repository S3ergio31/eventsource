<?php

namespace Tests\Feature\ApiGateway;

use App\Broadcasting\ApiGateway\ConnectionStore;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\Uid\Ulid;
use Tests\TestCase;

class ApiGatewayBroadcasterTest extends TestCase
{
    use FakesApiGateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeApiGateway();
    }

    public function test_broadcast_stores_the_event_and_posts_to_every_subscriber(): void
    {
        $this->store->shouldReceive('appendEvent')->once()
            ->with('private-user.1', 'notification', '{"message":"hola"}')
            ->andReturn('01JEVENT000000000000000000');
        $this->store->shouldReceive('subscribersOf')->with('private-user.1')->andReturn(['a', 'b']);

        Broadcast::connection('apigateway')->broadcast(['private-user.1'], 'notification', [
            'message' => 'hola',
            'socket' => 'ignored',
        ]);

        foreach (['a', 'b'] as $connectionId) {
            $this->assertSame([
                'type' => 'event',
                'channel' => 'private-user.1',
                'id' => '01JEVENT000000000000000000',
                'event' => 'notification',
                'data' => ['message' => 'hola'],
            ], $this->posted[$connectionId][0]);
        }
    }

    public function test_gone_connections_are_forgotten_without_affecting_the_rest(): void
    {
        $this->goneConnections = ['dead'];

        $this->store->shouldReceive('appendEvent')->andReturn('01JEVENT000000000000000000');
        $this->store->shouldReceive('subscribersOf')->andReturn(['dead', 'alive']);
        $this->store->shouldReceive('forget')->once()->with('dead', ['private-user.1']);

        Broadcast::connection('apigateway')->broadcast(['private-user.1'], 'notification', ['message' => 'hola']);

        $this->assertCount(1, $this->posted['alive']);
        $this->assertArrayNotHasKey('dead', $this->posted);
    }

    public function test_replay_lower_bound_goes_back_five_seconds(): void
    {
        $lastId = '01J00000100000000000000000';
        $bound = ConnectionStore::replayLowerBound($lastId);
        $ms = fn (string $ulid) => (int) Ulid::fromString($ulid)->getDateTime()->format('Uv');

        $this->assertTrue(Ulid::isValid($bound));
        $this->assertSame(ConnectionStore::REPLAY_SKEW_MS, $ms($lastId) - $ms($bound));
        // El minimo de ese milisegundo: todo evento de ese instante queda adentro.
        $this->assertStringEndsWith(str_repeat('0', 16), $bound);
        $this->assertLessThan($lastId, $bound);
    }
}
