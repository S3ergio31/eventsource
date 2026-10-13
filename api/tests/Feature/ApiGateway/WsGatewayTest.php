<?php

namespace Tests\Feature\ApiGateway;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WsGatewayTest extends TestCase
{
    use FakesApiGateway;
    use RefreshDatabase;

    private const SECRET = 'gateway-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ws.gateway_secret' => self::SECRET]);
        $this->fakeApiGateway();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function gateway(string $route, string $connectionId, array $body = [], string $query = '', string $secret = self::SECRET)
    {
        return $this->postJson("/api/ws/gateway/{$route}{$query}", $body, [
            'X-Connection-Id' => $connectionId,
            'X-Gateway-Secret' => $secret,
        ]);
    }

    public function test_rejects_requests_without_the_gateway_secret(): void
    {
        $this->gateway('connect', 'conn-1', secret: 'wrong')->assertForbidden();
    }

    public function test_rejects_everything_when_no_secret_is_configured(): void
    {
        config(['ws.gateway_secret' => '']);

        $this->gateway('connect', 'conn-1', secret: '')->assertForbidden();
    }

    public function test_ticket_endpoint_requires_a_session_token(): void
    {
        $this->postJson('/api/ws/ticket')->assertUnauthorized();
    }

    public function test_connect_accepts_a_valid_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = app(JwtService::class)->issueWsTicket($user)['ticket'];

        $this->store->shouldReceive('openConnection')->once()->with('conn-1', $user->id);

        $this->gateway('connect', 'conn-1', query: '?ticket='.$ticket)->assertOk();
    }

    public function test_connect_rejects_a_session_token_used_as_ticket(): void
    {
        $user = User::factory()->create();
        $token = app(JwtService::class)->issue($user)['token'];

        $this->store->shouldNotReceive('openConnection');

        $this->gateway('connect', 'conn-1', query: '?ticket='.$token)->assertUnauthorized();
    }

    public function test_subscribe_to_own_channel_replays_missed_events_after_subscribing(): void
    {
        $user = User::factory()->create();
        $channel = "private-user.{$user->id}";
        $lastId = '01J00000000000000000000000';

        $this->store->shouldReceive('findConnection')->with('conn-1')
            ->andReturn(['connectionId' => 'conn-1', 'userId' => $user->id, 'channels' => []]);

        // El orden es el que evita perder eventos: suscribir, despues leer.
        $this->store->shouldReceive('subscribe')->once()->with('conn-1', $channel)->ordered();
        $this->store->shouldReceive('eventsAfter')->once()->with($channel, $lastId)->ordered()
            ->andReturn([['id' => '01J00000000000000000000001', 'event' => 'notification', 'data' => '{"message":"perdido"}']]);

        $this->gateway('subscribe', 'conn-1', ['action' => 'subscribe', 'channel' => $channel, 'lastId' => $lastId])
            ->assertOk();

        [$replayed, $ack] = $this->posted['conn-1'];

        $this->assertSame('event', $replayed['type']);
        $this->assertSame('perdido', $replayed['data']['message']);
        $this->assertSame('subscribed', $ack['type']);
        $this->assertSame(1, $ack['replayed']);
        $this->assertSame($lastId, $ack['resumedFrom']);
        $this->assertNotEmpty($ack['cursor']);
    }

    public function test_subscribe_to_someone_elses_channel_is_denied(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->store->shouldReceive('findConnection')
            ->andReturn(['connectionId' => 'conn-1', 'userId' => $user->id, 'channels' => []]);
        $this->store->shouldNotReceive('subscribe');

        $this->gateway('subscribe', 'conn-1', ['action' => 'subscribe', 'channel' => "private-user.{$other->id}"])
            ->assertOk();

        $this->assertSame('error', $this->posted['conn-1'][0]['type']);
        $this->assertSame(403, $this->posted['conn-1'][0]['status']);
    }

    public function test_disconnect_forgets_the_connection(): void
    {
        $this->store->shouldReceive('forget')->once()->with('conn-1');

        $this->gateway('disconnect', 'conn-1')->assertOk();
    }
}
