<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un evento de Laravel completamente normal. No sabe que transporte lo lleva:
 * declara su canal privado y el driver configurado se encarga del resto.
 *
 * ShouldBroadcastNow (en lugar de ShouldBroadcast) lo emite de forma sincrona,
 * asi la evaluacion no necesita un worker de colas.
 *
 * broadcastConnections() lo manda por varios drivers a la vez (por defecto
 * los tres candidatos: 'sse', 'apigateway' y 'mercure'). Laravel arma el
 * payload una sola vez y se lo pasa a cada driver, asi que 'sentAt' es
 * identico en todos y la latencia se compara en igualdad.
 */
class UserNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public string $message,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        // Se serializa como "private-user.7"
        return [new PrivateChannel('user.'.$this->userId)];
    }

    /**
     * @return list<string>
     */
    public function broadcastConnections(): array
    {
        return config('broadcasting.fanout');
    }

    /**
     * Nombre del evento tal como lo vera el front en el campo "event:" de SSE.
     */
    public function broadcastAs(): string
    {
        return 'notification';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'userId' => $this->userId,
            'message' => $this->message,
            // Con milisegundos: el front calcula la latencia de cada transporte.
            'sentAt' => now()->format('Y-m-d\TH:i:s.vP'),
        ];
    }
}
