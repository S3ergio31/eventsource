<?php

namespace Tests\Feature\Mercure;

use App\Models\User;
use App\Services\JwtService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Tests\TestCase;

/**
 * El driver 'mercure' es el nativo de Laravel. Estos tests no prueban el
 * framework: fijan lo que la demo asume de el (topics, sobre del update,
 * cookie de suscriptor) y que hereda los canales de routes/channels.php.
 */
class MercureTest extends TestCase
{
    use RefreshDatabase;

    private const SUBSCRIBE_SECRET = 'test-mercure-subscriber-key-32-bytes!!';

    private const COOKIE = 'mercureAuthorization';

    private const TOPIC_PREFIX = 'https://laravel.alt/echo/channel/';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.connections.mercure.url' => 'http://hub.test/.well-known/mercure',
            'broadcasting.connections.mercure.public_url' => 'http://localhost/.well-known/mercure',
            'broadcasting.connections.mercure.publish_secret' => 'test-mercure-publisher-key-32-bytes!!!',
            'broadcasting.connections.mercure.subscribe_secret' => self::SUBSCRIBE_SECRET,
            'broadcasting.connections.mercure.subscribe_expiration' => 10,
            'broadcasting.connections.mercure.cookie_name' => self::COOKIE,
        ]);
    }

    public function test_broadcast_publishes_a_private_update_per_channel(): void
    {
        /** @var list<Update> $published */
        $published = [];

        $broadcaster = Broadcast::connection('mercure');
        $broadcaster->setHub(new MockHub(
            'http://hub.test/.well-known/mercure',
            new StaticTokenProvider('publisher-jwt'),
            function (Update $update) use (&$published): string {
                $published[] = $update;

                return 'urn:uuid:test';
            },
            cookieName: self::COOKIE,
        ));

        $broadcaster->broadcast(['private-user.1'], 'notification', [
            'message' => 'hola',
            'socket' => null,
        ]);

        $this->assertCount(1, $published);

        $update = $published[0];
        $this->assertSame([self::TOPIC_PREFIX.'private-user.1'], $update->getTopics());
        $this->assertTrue($update->isPrivate());
        $this->assertSame([
            'channels' => ['private-user.1'],
            'event' => 'notification',
            'payload' => ['message' => 'hola'],
        ], json_decode($update->getData(), true));
    }

    public function test_auth_for_own_channel_sets_a_cookie_for_that_topic(): void
    {
        $user = User::factory()->create();
        $channel = "private-user.{$user->id}";

        $response = $this->auth([$channel], $user)
            ->assertOk()
            ->assertJson([
                'channel_names' => [['name' => $channel]],
                'expires_in' => 600,
            ]);

        $this->assertArrayNotHasKey('denied', $response->json('channel_names.0'));
        $this->assertStringContainsString(self::TOPIC_PREFIX.$channel, $this->cookieClaims($response));
    }

    public function test_auth_for_someone_elses_channel_is_denied(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $channel = "private-user.{$other->id}";

        $response = $this->auth([$channel], $user)
            ->assertOk()
            ->assertJson(['channel_names' => [['name' => $channel, 'denied' => true]]]);

        $this->assertStringNotContainsString(self::TOPIC_PREFIX.$channel, $this->cookieClaims($response));
    }

    public function test_auth_requires_a_session(): void
    {
        $this->postJson('/api/broadcasting/mercure/auth', ['channel_names' => ['private-user.1']])
            ->assertUnauthorized();
    }

    /**
     * @param  list<string>  $channels
     */
    private function auth(array $channels, User $user): TestResponse
    {
        return $this->postJson('/api/broadcasting/mercure/auth', ['channel_names' => $channels], [
            'Authorization' => 'Bearer '.app(JwtService::class)->issue($user)['token'],
        ]);
    }

    /**
     * Claims del JWT de la cookie como JSON, verificando la firma con la clave
     * que tiene el hub.
     */
    private function cookieClaims(TestResponse $response): string
    {
        $cookie = $response->getCookie(self::COOKIE, decrypt: false);

        $this->assertNotNull($cookie, 'La respuesta no trae la cookie de suscriptor.');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/.well-known/mercure', $cookie->getPath());

        $claims = JWT::decode($cookie->getValue(), new Key(self::SUBSCRIBE_SECRET, 'HS256'));

        return json_encode($claims, JSON_UNESCAPED_SLASHES);
    }
}
