<?php

namespace Tests\Feature\Mercure;

use App\Models\User;
use App\Services\JwtService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Tests\TestCase;

class MercureTest extends TestCase
{
    use RefreshDatabase;

    private const SUBSCRIBER_KEY = 'test-mercure-subscriber-key-32-bytes!!';

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.connections.mercure.subscriber_key' => self::SUBSCRIBER_KEY,
            'broadcasting.connections.mercure.subscriber_ttl' => 600,
        ]);

        $this->app->instance(HubInterface::class, new MockHub(
            'http://hub.test/.well-known/mercure',
            new StaticTokenProvider('publisher-jwt'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'urn:uuid:test';
            },
            publicUrl: 'http://public.test/.well-known/mercure',
        ));
    }

    public function test_broadcast_publishes_a_private_update_per_channel(): void
    {
        Broadcast::connection('mercure')->broadcast(['private-user.1'], 'notification', [
            'message' => 'hola',
            'socket' => 'ignored',
        ]);

        $this->assertCount(1, $this->published);

        $update = $this->published[0];
        $this->assertSame(['private-user.1'], $update->getTopics());
        $this->assertTrue($update->isPrivate());
        $this->assertSame('notification', $update->getType());
        $this->assertSame('{"message":"hola"}', $update->getData());
    }

    public function test_token_for_own_channel_only_allows_that_topic(): void
    {
        $user = User::factory()->create();
        $channel = "private-user.{$user->id}";

        $response = $this->postJson('/api/mercure/token', ['channel' => $channel], $this->bearer($user))
            ->assertOk()
            ->assertJson([
                'hub_url' => 'http://public.test/.well-known/mercure',
                'topic' => $channel,
                'expires_in' => 600,
            ]);

        $this->assertEqualsWithDelta(microtime(true) * 1000, $response->json('server_time'), 5000);

        $claims = JWT::decode($response->json('token'), new Key(self::SUBSCRIBER_KEY, 'HS256'));

        $this->assertSame([$channel], $claims->mercure->subscribe);
    }

    public function test_token_for_someone_elses_channel_is_denied(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->postJson('/api/mercure/token', ['channel' => "private-user.{$other->id}"], $this->bearer($user))
            ->assertForbidden();
    }

    public function test_token_requires_a_session_and_a_channel(): void
    {
        $this->postJson('/api/mercure/token', ['channel' => 'private-user.1'])->assertUnauthorized();

        $this->postJson('/api/mercure/token', [], $this->bearer(User::factory()->create()))
            ->assertStatus(400);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($user)['token']];
    }
}
