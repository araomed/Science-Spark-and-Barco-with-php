<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByIdentifier(string $identifier): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, email, hashed_password, role
             FROM users
             WHERE username = :identifier OR email = :identifier
             LIMIT 1'
        );
        $statement->execute(['identifier' => $identifier]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, email, role
             FROM users
             WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, email, hashed_password, role)
             VALUES (:username, :email, :hashed_password, :role)
             RETURNING id, username, email, role'
        );
        $statement->execute($data);

        return $statement->fetch();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?array
    {
        $assignments = [];

        foreach (array_keys($data) as $column) {
            $assignments[] = $column . ' = :' . $column;
        }

        if ($assignments === []) {
            return $this->findById($id);
        }

        $data['id'] = $id;

        $statement = $this->pdo->prepare(
            'UPDATE users SET ' . implode(', ', $assignments) .
            ' WHERE id = :id RETURNING id, username, email, role'
        );
        $statement->execute($data);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }
}
