<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;

/**
 * Emision y verificacion de JWT a mano, sin paquete de auth intermedio, para
 * que se vea el mecanismo completo: que va en el token y quien lo valida.
 */
class JwtService
{
    public function __construct(
        private string $secret,
        private string $algo,
        private int $ttl,
        private string $issuer,
    ) {
    }

    /**
     * @return array{token: string, expires_in: int}
     */
    public function issue(User $user): array
    {
        $now = time();

        $token = JWT::encode([
            'iss' => $this->issuer,
            'sub' => (string) $user->getKey(),   // el user id viaja en 'sub'
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'email' => $user->email,
        ], $this->secret, $this->algo);

        return ['token' => $token, 'expires_in' => $this->ttl];
    }

    /**
     * @throws \Firebase\JWT\ExpiredException|\Firebase\JWT\SignatureInvalidException|\UnexpectedValueException
     */
    public function verify(string $token): stdClass
    {
        $payload = JWT::decode($token, new Key($this->secret, $this->algo));

        if (($payload->iss ?? null) !== $this->issuer) {
            throw new \UnexpectedValueException('Unexpected token issuer');
        }

        return $payload;
    }
}
