<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\JwtService;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;
use App\Validation\Validator;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly JwtService $jwt
    ) {
    }

    public function login(Request $request): void
    {
        $data = Validator::validate($request->body(), [
            'password' => 'required|string|min:1|max:255',
        ]);

        $identifier = trim((string) (
            $request->input('identifier')
            ?? $request->input('username')
            ?? $request->input('email')
            ?? ''
        ));

        if ($identifier === '') {
            throw new HttpException('Identifier and password are required', 422, [
                'identifier' => ['Provide username, email, or identifier.'],
            ]);
        }

        $user = $this->users->findByIdentifier($identifier);

        if (
            $user === null
            || !is_string($user['hashed_password'] ?? null)
            || !password_verify((string) $data['password'], $user['hashed_password'])
        ) {
            throw new HttpException('Invalid credentials', 401);
        }

        $publicUser = $this->publicUser($user);
        $token = $this->jwt->createToken($publicUser);

        Response::success([
            ...$token,
            'user' => $publicUser,
        ], 'Login successful');
    }

    public function me(Request $request): void
    {
        $payload = (array) $request->attribute('auth_payload', []);
        $userId = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);

        if ($userId === false || $userId < 1) {
            throw new HttpException('Invalid authentication token', 401);
        }

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException('Authenticated user no longer exists', 401);
        }

        Response::success($user);
    }

    public function logout(Request $request): void
    {
        Response::success(null, 'Logout successful');
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }
}
