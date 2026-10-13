<?php

namespace App\Broadcasting;

use Firebase\JWT\JWT;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Support\Arr;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Driver de broadcasting "mercure".
 *
 * Las conexiones SSE las sostiene el hub Mercure que viene dentro de
 * FrankenPHP (ver docker/Caddyfile): es codigo Go, una goroutine por cliente,
 * no un hilo PHP. Laravel solo publica: un POST al hub por evento, sin
 * importar cuantos suscriptores haya. El fan-out lo hace el hub.
 *
 * Cada canal de Laravel es un topic de Mercure con el mismo nombre
 * ("private-user.1"), y las publicaciones son privadas: el hub solo las
 * entrega a quien presente un JWT de suscriptor que incluya ese topic. Ese
 * JWT lo firma subscriberToken() despues de pasar por routes/channels.php.
 */
class MercureBroadcaster extends Broadcaster
{
    use AuthorizesChannelAccess;
    use UsePusherChannelConventions;

    public function __construct(
        protected HubInterface $hub,
        protected string $subscriberKey,
        protected int $subscriberTtl = 3600,
    ) {
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        if (empty($channels)) {
            return;
        }

        Arr::pull($payload, 'socket');

        $data = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($this->formatChannels($channels) as $channel) {
            // El id lo asigna el hub y queda en su historial (transporte Bolt):
            // con eso funciona el Last-Event-ID al reconectar.
            $this->hub->publish(new Update(
                topics: $channel,
                data: $data,
                private: true,
                type: (string) $event,
            ));
        }
    }

    /**
     * JWT de suscriptor: el hub lo valida con la misma clave y solo deja
     * escuchar los topics del claim mercure.subscribe. El hub no le vuelve a
     * preguntar a Laravel, asi que este token ES la autorizacion: hay que
     * emitirlo solo despues de canAccess().
     *
     * @param  list<string>  $topics
     * @return array{token: string, expires_in: int}
     */
    public function subscriberToken(array $topics): array
    {
        $now = time();

        return [
            'token' => JWT::encode([
                'mercure' => ['subscribe' => array_values($topics)],
                'iat' => $now,
                'exp' => $now + $this->subscriberTtl,
            ], $this->subscriberKey, 'HS256'),
            'expires_in' => $this->subscriberTtl,
        ];
    }

    public function publicUrl(): string
    {
        return $this->hub->getPublicUrl();
    }
}
