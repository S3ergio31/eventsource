<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\JwtService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class JwtServiceTest extends TestCase
{
    private const SECRET = 'test-secret-at-least-32-bytes-long!!';

    private function service(): JwtService
    {
        return new JwtService(self::SECRET, 'HS256', 3600, 'eventsource-demo', wsTicketTtl: 60);
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 7;
        $user->email = 'ana@example.com';

        return $user;
    }

    public function test_session_token_verifies_as_api(): void
    {
        $token = $this->service()->issue($this->user())['token'];

        $this->assertSame('7', $this->service()->verify($token)->sub);
    }

    public function test_ws_ticket_verifies_only_as_ws(): void
    {
        $issued = $this->service()->issueWsTicket($this->user());

        $this->assertSame(60, $issued['expires_in']);
        $this->assertSame('7', $this->service()->verify($issued['ticket'], JwtService::AUDIENCE_WS)->sub);

        $this->expectException(UnexpectedValueException::class);
        $this->service()->verify($issued['ticket']);
    }

    public function test_session_token_is_not_a_ws_ticket(): void
    {
        $token = $this->service()->issue($this->user())['token'];

        $this->expectException(UnexpectedValueException::class);
        $this->service()->verify($token, JwtService::AUDIENCE_WS);
    }

    public function test_legacy_token_without_audience_counts_as_api(): void
    {
        $token = JWT::encode([
            'iss' => 'eventsource-demo',
            'sub' => '7',
            'exp' => time() + 60,
        ], self::SECRET, 'HS256');

        $this->assertSame('7', $this->service()->verify($token)->sub);

        $this->expectException(UnexpectedValueException::class);
        $this->service()->verify($token, JwtService::AUDIENCE_WS);
    }
}
