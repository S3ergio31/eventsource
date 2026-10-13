<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;

/**
 * Emision y verificacion de JWT a mano, sin paquete de auth intermedio, para
 * que se vea el mecanismo completo: que va en el token y quien lo valida.
 *
 * Emite dos tipos de token con el mismo secreto, separados por 'aud':
 *
 *  - 'api': el de sesion, viaja como Bearer en cada request.
 *  - 'ws':  el ticket de vida corta para abrir el WebSocket de API Gateway.
 *           El navegador no puede mandar headers en un WebSocket, asi que este
 *           viaja en la query string; por eso vive segundos y no sirve de Bearer.
 */
class JwtService
{
    public const AUDIENCE_API = 'api';

    public const AUDIENCE_WS = 'ws';

    public function __construct(
        private string $secret,
        private string $algo,
        private int $ttl,
        private string $issuer,
        private int $wsTicketTtl = 60,
    ) {
    }

    /**
     * @return array{token: string, expires_in: int}
     */
    public function issue(User $user): array
    {
        return [
            'token' => $this->encode($user, self::AUDIENCE_API, $this->ttl),
            'expires_in' => $this->ttl,
        ];
    }

    /**
     * @return array{ticket: string, expires_in: int}
     */
    public function issueWsTicket(User $user): array
    {
        return [
            'ticket' => $this->encode($user, self::AUDIENCE_WS, $this->wsTicketTtl),
            'expires_in' => $this->wsTicketTtl,
        ];
    }

    /**
     * @throws \Firebase\JWT\ExpiredException|\Firebase\JWT\SignatureInvalidException|\UnexpectedValueException
     */
    public function verify(string $token, string $audience = self::AUDIENCE_API): stdClass
    {
        $payload = JWT::decode($token, new Key($this->secret, $this->algo));

        if (($payload->iss ?? null) !== $this->issuer) {
            throw new \UnexpectedValueException('Unexpected token issuer');
        }

        // Los tokens emitidos antes de separar audiencias no traen 'aud': se
        // tratan como de sesion.
        if (($payload->aud ?? self::AUDIENCE_API) !== $audience) {
            throw new \UnexpectedValueException('Unexpected token audience');
        }

        return $payload;
    }

    private function encode(User $user, string $audience, int $ttl): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $this->issuer,
            'aud' => $audience,
            'sub' => (string) $user->getKey(),   // el user id viaja en 'sub'
            'iat' => $now,
            'exp' => $now + $ttl,
            'email' => $user->email,
        ], $this->secret, $this->algo);
    }
}
