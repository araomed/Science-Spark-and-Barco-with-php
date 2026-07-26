<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config\Config;
use App\Exceptions\HttpException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

final class JwtService
{
    public function createToken(array $user): array
    {
        $secret = $this->secret();
        $issuedAt = time();
        $expiresAt = $issuedAt + (Config::int('JWT_EXPIRATION_MINUTES', 60) * 60);

        $payload = [
            'iss' => Config::string('APP_URL', 'http://127.0.0.1:8080'),
            'sub' => (string) $user['id'],
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'] ?? null,
                'email' => $user['email'] ?? null,
                'role' => $user['role'] ?? null,
            ],
        ];

        return [
            'access_token' => JWT::encode($payload, $secret, 'HS256'),
            'token_type' => 'Bearer',
            'expires_at' => date(DATE_ATOM, $expiresAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        try {
            return (array) JWT::decode($token, new Key($this->secret(), 'HS256'));
        } catch (ExpiredException) {
            throw new HttpException('Authentication token has expired', 401);
        } catch (Throwable) {
            throw new HttpException('Invalid authentication token', 401);
        }
    }

    private function secret(): string
    {
        $secret = Config::string('JWT_SECRET', '');

        if ($secret === '') {
            throw new HttpException('JWT secret is not configured', 500);
        }

        return $secret;
    }
}
