<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Autorizacion de canales privados.
 *
 * Esta es la unica fuente de verdad sobre quien puede escuchar que. El
 * endpoint SSE ejecuta estos mismos callbacks (via
 * SseBroadcaster::canAccess()), asi que no hay logica duplicada.
 *
 * El $user llega desde $request->user(), que existe porque el middleware
 * EnsureJwt llamo a setUserResolver().
 */
Broadcast::channel('user.{userId}', function (User $user, int $userId) {
    return (int) $user->id === (int) $userId;
});
