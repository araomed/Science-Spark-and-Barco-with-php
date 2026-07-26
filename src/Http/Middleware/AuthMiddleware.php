<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\JwtService;
use App\Exceptions\HttpException;
use App\Http\Request;

final class AuthMiddleware
{
    public function __construct(private readonly JwtService $jwt)
    {
    }

    public function __invoke(Request $request, callable $next): void
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            throw new HttpException('Authentication required', 401);
        }

        $payload = $this->jwt->decode($token);
        $user = isset($payload['user']) ? (array) $payload['user'] : [];

        $next(
            $request
                ->withAttribute('auth_payload', $payload)
                ->withAttribute('user', $user)
        );
    }
}
