<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\TableRepository;
use App\Repositories\UserRepository;
use App\Validation\Validator;

final class UserController extends ResourceController
{
    public function __construct(
        TableRepository $tableRepository,
        private readonly UserRepository $users
    ) {
        parent::__construct($tableRepository);
    }

    public function store(Request $request): void
    {
        $data = Validator::validate($request->body(), [
            'username' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|max:255',
            'role' => 'required|string|enum:admin,manager,technician',
        ]);

        $record = $this->users->create([
            'username' => $data['username'],
            'email' => $data['email'],
            'hashed_password' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'role' => $data['role'],
        ]);

        Response::success($record, 'User created', 201);
    }

    public function update(Request $request): void
    {
        $data = Validator::validate($request->body(), [
            'username' => 'optional|string|min:2|max:120',
            'email' => 'optional|email|max:255',
            'password' => 'optional|string|min:8|max:255',
            'role' => 'optional|string|enum:admin,manager,technician',
        ]);

        if (isset($data['password'])) {
            $data['hashed_password'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }

        $record = $this->users->update($this->routeId($request), $data);

        if ($record === null) {
            throw new HttpException('Record not found', 404);
        }

        Response::success($record, 'User updated');
    }
}
