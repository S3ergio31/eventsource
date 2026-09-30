<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un evento de Laravel completamente normal. No sabe que existe SSE: declara su
 * canal privado y el driver configurado se encarga del transporte.
 *
 * ShouldBroadcastNow (en lugar de ShouldBroadcast) lo emite de forma sincrona,
 * asi la maqueta no necesita un worker de colas.
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
            'sentAt' => now()->toIso8601String(),
        ];
    }
}
