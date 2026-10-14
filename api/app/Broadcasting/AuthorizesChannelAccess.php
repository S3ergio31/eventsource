<?php

namespace App\Broadcasting;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Autorizacion de canales con los callbacks de routes/channels.php, para
 * drivers que extienden Broadcaster y usan UsePusherChannelConventions.
 *
 * Es el mismo codigo que SseBroadcaster tiene inline, extraido a un trait para
 * que los drivers 'apigateway' y 'mercure' autoricen exactamente igual.
 */
trait AuthorizesChannelAccess
{
    /**
     * Endpoint POST /api/broadcasting/auth, por compatibilidad con el flujo
     * estandar de Laravel.
     */
    public function auth($request)
    {
        $channel = (string) $request->channel_name;

        if ($channel === '') {
            throw new AccessDeniedHttpException('channel_name is required');
        }

        $normalized = $this->normalizeChannelName($channel);

        if ($this->isGuardedChannel($channel) && ! $this->retrieveUser($request, $normalized)) {
            throw new AccessDeniedHttpException('Unauthenticated');
        }

        return parent::verifyUserCanAccessChannel($request, $normalized);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return is_bool($result)
            ? json_encode($result)
            : json_encode(['channel_data' => $result]);
    }

    /**
     * Misma autorizacion que auth(), pero devolviendo bool en vez de tirar
     * excepcion. $request->user() tiene que resolver al usuario.
     */
    public function canAccess($request, string $channel): bool
    {
        if (! $this->isGuardedChannel($channel)) {
            return true;
        }

        $normalized = $this->normalizeChannelName($channel);

        if (! $this->retrieveUser($request, $normalized)) {
            return false;
        }

        try {
            $this->verifyUserCanAccessChannel($request, $normalized);

            return true;
        } catch (AccessDeniedHttpException) {
            return false;
        }
    }
}
