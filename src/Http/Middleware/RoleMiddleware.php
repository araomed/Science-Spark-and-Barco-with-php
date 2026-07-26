<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\HttpException;
use App\Http\Request;

final class RoleMiddleware
{
    /**
     * @param array<int, string> $roles
     */
    public function __construct(private readonly array $roles)
    {
    }

    public function __invoke(Request $request, callable $next): void
    {
        $user = (array) $request->attribute('user', []);
        $role = $user['role'] ?? null;

        if (!is_string($role) || !in_array($role, $this->roles, true)) {
            throw new HttpException('You do not have permission to perform this action', 403);
        }

        $next($request);
    }
}
