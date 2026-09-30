<?php

namespace App\Providers;

use App\Broadcasting\SseBroadcaster;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
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
    }
}
