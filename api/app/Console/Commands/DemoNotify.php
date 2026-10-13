<?php

namespace App\Console\Commands;

use App\Events\UserNotification;
use App\Models\User;
use Illuminate\Console\Command;

class DemoNotify extends Command
{
    protected $signature = 'demo:notify {user : ID o email del usuario} {message : Texto del evento}';

    protected $description = 'Despacha un evento al canal privado de un usuario a traves del driver de broadcasting';

    public function handle(): int
    {
        $needle = (string) $this->argument('user');

        $user = is_numeric($needle)
            ? User::find((int) $needle)
            : User::where('email', $needle)->first();

        if (! $user) {
            $this->error("No encontre al usuario {$needle}.");

            return self::FAILURE;
        }

        $message = (string) $this->argument('message');

        // Esta es la unica linea que importa: se despacha el evento normalmente.
        // El driver 'sse' lo convierte en un XADD a Redis y el 'apigateway' en
        // un PutItem a DynamoDB + un PostToConnection por suscriptor.
        UserNotification::dispatch($user->id, $message);

        $via = implode(' + ', config('broadcasting.fanout'));

        $this->info("Evento despachado a private-user.{$user->id} ({$user->email}) via {$via}: {$message}");

        return self::SUCCESS;
    }
}
